<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Trade;

use App\Enums\ListingStatus;
use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Exceptions\TradeException;
use App\Models\Address;
use App\Models\Listing;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 下单 / 取消（T4 · 担保交易第一步）。
 *
 * 并发与幂等的三重防线：
 *   1. 幂等键：orders.idempotency_key 唯一；命中即返回原单（不重复锁库/扣库存）；
 *   2. 行锁：SELECT ... FOR UPDATE 锁 listing，串行化同一商品的抢购；
 *   3. 唯一约束兜底：两请求同时穿过第 1 步时，DB 抛唯一冲突 → 回查原单返回。
 */
class OrderService
{
    public function __construct(private readonly FeeCalculator $fees) {}

    /**
     * 两条定价纪律，都由服务端说了算：
     *   1. 运费取 listings.shipping_fee，**不认客户端传的任何运费**——此前 shipping_amount
     *      由买家自行传入，等于买家可以把自己的运费改成 0；
     *   2. 收货地址必须属于下单人，并在下单瞬间整条快照进订单（地址之后可改可删）。
     *
     * @param  array{quantity?: int, remark?: string, address?: Address|null}  $options
     * @return array{order: Order, replayed: bool, quote: FeeQuote}
     */
    public function checkout(int $buyerId, int $listingId, string $idempotencyKey, array $options = []): array
    {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 64) {
            throw new TradeException('幂等键必填且长度 ≤ 64');
        }

        if ($replayed = $this->findByIdempotencyKey($idempotencyKey, $buyerId)) {
            return ['order' => $replayed, 'replayed' => true, 'quote' => $this->quoteOf($replayed)];
        }

        // 实物交易没有地址就无法发货：在进事务前挡掉，避免白锁库存
        $address = $options['address'] ?? null;
        if (! $address instanceof Address) {
            throw new TradeException('请先填写收货地址');
        }

        $quantity = max((int) ($options['quantity'] ?? 1), 1);

        try {
            [$order, $quote] = DB::transaction(function () use ($buyerId, $listingId, $idempotencyKey, $quantity, $address, $options) {
                /** @var Listing $listing */
                $listing = Listing::query()->whereKey($listingId)->lockForUpdate()->firstOrFail();

                if ((int) $listing->seller_id === $buyerId) {
                    throw new TradeException('不能购买自己发布的商品');
                }

                if (! $listing->isPurchasable()) {
                    throw new TradeException('商品当前不可购买（已售出/已锁定/已下架）');
                }

                if ($listing->stock < $quantity) {
                    throw new TradeException('库存不足');
                }

                $goodsCents = Money::toCents((string) $listing->price) * $quantity;
                $quote = $this->fees->quote(
                    Money::fromCents($goodsCents),
                    $this->fees->resolve((int) $listing->seller_id, (string) ($listing->meta['category'] ?? ''))
                );

                // 每单计一次运费（C2C 一物一价，多件合并寄送的运费差异由卖家自行让利）
                $shipping = Money::toCents((string) ($listing->shipping_fee ?? '0'));

                $order = Order::create([
                    'order_no' => $this->generateOrderNo($buyerId),
                    'idempotency_key' => $idempotencyKey,
                    'buyer_id' => $buyerId,
                    'seller_id' => (int) $listing->seller_id,
                    'status' => OrderStatus::PendingPayment,
                    'escrow_status' => 'none',
                    'goods_amount' => $quote->goodsAmount,
                    'shipping_amount' => Money::fromCents($shipping),
                    'fee_amount' => $quote->feeAmount,
                    'payable_amount' => Money::fromCents(Money::toCents($quote->payableAmount) + $shipping),
                    'currency' => $listing->currency,
                    'fee_rule_id' => $quote->feeRuleId,
                    'fee_bearer' => $quote->bearer->value,
                    'fee_snapshot' => $quote->snapshot(),
                    'remark' => $options['remark'] ?? null,
                    // 收货快照：卖家发货只认订单里的这组字段，地址簿后续怎么改都不影响历史单
                    'address_id' => $address->address_id,
                    'receiver_name' => $address->receiver_name,
                    'receiver_phone' => $address->receiver_phone,
                    'receiver_province' => $address->province,
                    'receiver_city' => $address->city,
                    'receiver_district' => $address->district,
                    'receiver_detail' => $address->detail,
                    'expire_at' => Carbon::now()->addMinutes((int) config('goat.trade.payment_timeout_minutes', 30)),
                ]);

                OrderItem::create([
                    'order_id' => $order->order_id,
                    'listing_id' => $listing->listing_id,
                    'figure_id' => $listing->figure_id,
                    'title_snapshot' => $listing->title,
                    'cover_key_snapshot' => $listing->cover_key,
                    'quantity' => $quantity,
                    'unit_price' => $listing->price,
                    'subtotal' => Money::fromCents($goodsCents),
                ]);

                // 锁库：库存清零则整件商品置为已锁定，锁定期到期由调度释放
                $listing->stock -= $quantity;
                if ($listing->stock <= 0) {
                    $listing->status = ListingStatus::Reserved;
                    $listing->reserved_until = Carbon::now()->addMinutes((int) config('goat.trade.reserve_minutes', 30));
                }
                $listing->save();

                return [$order->fresh('items'), $quote];
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            // 竞态兜底：另一个请求已用同一幂等键落库
            $existing = $this->findByIdempotencyKey($idempotencyKey, $buyerId);
            if (! $existing) {
                throw $e;
            }

            return ['order' => $existing, 'replayed' => true, 'quote' => $this->quoteOf($existing)];
        }

        return ['order' => $order, 'replayed' => false, 'quote' => $quote];
    }

    /** 取消待付款订单并释放库存（幂等：已取消直接返回） */
    public function cancel(Order $order, string $reason = ''): Order
    {
        if ($order->status === OrderStatus::Cancelled) {
            return $order;
        }

        if ($order->status->holdsFunds()) {
            throw new TradeException('订单已支付，须走退款流程，不能直接取消');
        }

        return DB::transaction(function () use ($order, $reason) {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->order_id)->lockForUpdate()->firstOrFail();

            $locked->transitTo(OrderStatus::Cancelled, [
                'closed_at' => Carbon::now(),
                'remark' => $reason !== '' ? $reason : $locked->remark,
            ]);

            foreach ($locked->items as $item) {
                /** @var Listing $listing */
                $listing = Listing::query()->whereKey($item->listing_id)->lockForUpdate()->first();
                if (! $listing) {
                    continue;
                }

                $listing->stock += $item->quantity;
                if ($listing->status === ListingStatus::Reserved) {
                    $listing->status = ListingStatus::Active;
                    $listing->reserved_until = null;
                }
                $listing->save();
            }

            return $locked;
        }, 3);
    }

    /**
     * 支付成功（pending_payment → paid），担保资金置为冻结（T5 调用）。
     *
     * 幂等语义：重复回调直接返回当前订单，不再跃迁——
     * 渠道会重推通知，靠状态机挡比靠调用方判重更可靠。
     */
    public function markPaid(int $orderId): Order
    {
        return DB::transaction(function () use ($orderId) {
            /** @var Order $order */
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();

            if ($order->status !== OrderStatus::PendingPayment) {
                return $order;
            }

            $order->transitTo(OrderStatus::Paid, [
                'paid_at' => Carbon::now(),
                'escrow_status' => 'frozen',
            ]);

            return $order->refresh();
        }, 3);
    }

    /**
     * 卖家发货（paid → seller_shipped）。
     *
     * 只认订单里的 seller_id，不信任入参里的任何卖家标识；
     * 运单号落库后买家侧即可查件，无需再跳外部系统。
     */
    public function ship(int $sellerId, int $orderId, string $company, string $trackingNo): Order
    {
        return DB::transaction(function () use ($sellerId, $orderId, $company, $trackingNo) {
            /** @var Order $order */
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();

            if ((int) $order->seller_id !== $sellerId) {
                throw new TradeException('无权操作他人的订单');
            }

            if ($order->status !== OrderStatus::Paid) {
                throw new TradeException('只有已付款订单可以发货');
            }

            $order->transitTo(OrderStatus::SellerShipped, [
                'shipping_company' => $company,
                'tracking_no' => $trackingNo,
                'shipped_at' => Carbon::now(),
            ]);

            return $order->refresh();
        }, 3);
    }

    /**
     * 买家确认收货（seller_shipped → buyer_confirmed → completed），担保资金解冻。
     *
     * 分两步走而不是直接置 completed：状态机里 completed 只能由 buyer_confirmed 进入，
     * 直跳会让「确认收货时间」与「资金释放」两个事实失去单据依据。
     */
    public function confirmReceipt(int $buyerId, int $orderId): Order
    {
        return DB::transaction(function () use ($buyerId, $orderId) {
            /** @var Order $order */
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();

            if ((int) $order->buyer_id !== $buyerId) {
                throw new TradeException('无权操作他人的订单');
            }

            if ($order->status !== OrderStatus::SellerShipped) {
                throw new TradeException('只有已发货订单可以确认收货');
            }

            $order->transitTo(OrderStatus::BuyerConfirmed, ['confirmed_at' => Carbon::now()]);
            $order->transitTo(OrderStatus::Completed, ['escrow_status' => 'released']);

            return $order->refresh();
        }, 3);
    }

    /**
     * 系统代买家确认收货（调度用）。
     *
     * 与手动确认走**同一条状态机路径**，只有操作者不同：买家可以一直不点确认，
     * 但担保款不能因此永久冻结在平台。返回 null 表示订单已不在可确认状态
     * （并发下已被买家确认、或已进入退款），调用方跳过即可。
     */
    public function confirmReceiptByTimeout(Order $order): ?Order
    {
        return DB::transaction(function () use ($order) {
            /** @var Order|null $locked */
            $locked = Order::query()->whereKey($order->order_id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== OrderStatus::SellerShipped) {
                return null;
            }

            $locked->transitTo(OrderStatus::BuyerConfirmed, ['confirmed_at' => Carbon::now()]);
            $locked->transitTo(OrderStatus::Completed, ['escrow_status' => 'released']);

            return $locked->refresh();
        }, 3);
    }

    /**
     * 到期望天数仍未确认收货的订单（自动确认的扫描入口）。
     *
     * 排除有未结退款单的：买家正在维权时，系统不能替他把钱放给卖家。
     *
     * @return \Illuminate\Support\Collection<int, Order>
     */
    public function autoConfirmDue(int $days, int $limit = 200): \Illuminate\Support\Collection
    {
        return Order::query()
            ->where('status', OrderStatus::SellerShipped->value)
            ->whereNotNull('shipped_at')
            ->where('shipped_at', '<=', Carbon::now()->subDays(max(1, $days)))
            ->whereNotExists(function ($query): void {
                $query->from('refunds')
                    ->whereColumn('refunds.order_id', 'orders.order_id')
                    ->whereIn('refunds.status', [RefundStatus::Pending->value, RefundStatus::Failed->value]);
            })
            ->orderBy('order_id')
            ->limit($limit)
            ->get();
    }

    /**
     * 付款后超过 N 天仍未发货的订单（催办扫描入口）。
     *
     * @return \Illuminate\Support\Collection<int, Order>
     */
    public function paidUnshippedBefore(int $days, int $limit = 200): \Illuminate\Support\Collection
    {
        return Order::query()
            ->where('status', OrderStatus::Paid->value)
            ->whereNotNull('paid_at')
            ->where('paid_at', '<=', Carbon::now()->subDays(max(1, $days)))
            ->orderBy('order_id')
            ->limit($limit)
            ->get();
    }

    /** 释放到期未付款订单（调度调用；逐单加锁，单笔失败不影响批次） */
    public function closeExpired(int $limit = 100): int
    {
        $expired = Order::query()
            ->where('status', OrderStatus::PendingPayment->value)
            ->whereNotNull('expire_at')
            ->where('expire_at', '<=', Carbon::now())
            ->orderBy('order_id')
            ->limit($limit)
            ->get();

        $closed = 0;
        foreach ($expired as $order) {
            try {
                $this->cancel($order, '超时未付款自动关闭');
                $closed++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $closed;
    }

    private function findByIdempotencyKey(string $key, int $buyerId): ?Order
    {
        $order = Order::query()->where('idempotency_key', $key)->with('items')->first();

        if ($order && (int) $order->buyer_id !== $buyerId) {
            // 幂等键被他人占用：不泄露订单内容，按非法请求处理
            throw new TradeException('幂等键冲突');
        }

        return $order;
    }

    private function quoteOf(Order $order): FeeQuote
    {
        $snapshot = (array) ($order->fee_snapshot ?? []);

        return new FeeQuote(
            goodsAmount: (string) ($snapshot['goods_amount'] ?? $order->goods_amount),
            feeAmount: (string) ($snapshot['fee_amount'] ?? $order->fee_amount),
            payableAmount: (string) ($snapshot['payable_amount'] ?? $order->payable_amount),
            sellerIncome: (string) ($snapshot['seller_income'] ?? '0.00'),
            bearer: \App\Enums\FeeBearer::from((string) ($snapshot['bearer'] ?? 'seller')),
            feeRuleId: isset($snapshot['fee_rule_id']) ? (int) $snapshot['fee_rule_id'] : null,
            percentBip: (int) ($snapshot['percent_bip'] ?? 0),
            capAmount: $snapshot['cap_amount'] ?? null,
        );
    }

    /** 对外单号：GO + 时间 + 买家尾号 + 随机，冲突由 order_no 唯一约束兜（极端情况重试一次即可） */
    private function generateOrderNo(int $buyerId): string
    {
        return 'GO'.Carbon::now()->format('ymdHis').str_pad((string) ($buyerId % 100), 2, '0', STR_PAD_LEFT).random_int(1000, 9999);
    }
}

<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Payment;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\TradeException;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payment\Contracts\PaymentChannel;
use App\Services\Payment\Contracts\PaymentNotification;
use App\Services\Trade\OrderService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 担保交易资金流（T5）：发起 → 支付成功冻结 → 确认收货分账结算 → 退款。
 *
 * 三条铁律：
 * 1. 回调只信「验签通过 + 金额一致 + 单号匹配」，任一不符按失败处理，绝不改订单状态；
 * 2. 状态跃迁一律走模型 transitTo（状态机断言），业务代码不直接写 status 字段；
 * 3. 幂等靠唯一键兜底（payment_no / idempotency_key / provider_trade_no），并发下靠唯一约束报错而非先查后插。
 */
class PaymentService
{
    public function __construct(
        private readonly PaymentChannel $channel,
        private readonly OrderService $orders,
    ) {}

    /**
     * 发起支付：生成我方支付单并取渠道拉起参数。
     *
     * @return array{payment: Payment, payload: array<string, mixed>}
     */
    public function initiate(int $buyerId, int $orderId, string $idempotencyKey): array
    {
        $existing = Payment::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            $this->assertOwner($existing, $buyerId);

            return ['payment' => $existing, 'payload' => $this->channel->create($existing)];
        }

        $order = Order::query()->whereKey($orderId)->firstOrFail();

        if ((int) $order->buyer_id !== $buyerId) {
            throw new TradeException('无权支付他人的订单');
        }

        if ($order->status !== OrderStatus::PendingPayment) {
            throw new TradeException('订单当前状态不可支付');
        }

        if ($this->cents($order->payable_amount) <= 0) {
            throw new TradeException('订单应付金额异常，无法发起支付');
        }

        try {
            $payment = Payment::query()->create([
                'payment_no' => $this->paymentNo(),
                'order_id' => $order->order_id,
                'idempotency_key' => $idempotencyKey,
                'channel' => $this->channel->name(),
                'status' => PaymentStatus::Pending,
                'amount' => $order->payable_amount,
                'currency' => $order->currency,
                // 分账基数在发起时即冻结：后续费率调整不影响已发起订单
                'platform_fee_amount' => $order->fee_amount,
                'seller_amount' => $this->subtract($order->payable_amount, $order->fee_amount),
            ]);
        } catch (UniqueConstraintViolationException) {
            // 并发下同一幂等键被另一请求抢先写入：回读那条即可，不要报错给用户
            $payment = Payment::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
            $this->assertOwner($payment, $buyerId);

            return ['payment' => $payment, 'payload' => $this->channel->create($payment)];
        }

        return ['payment' => $payment, 'payload' => $this->channel->create($payment)];
    }

    /**
     * 渠道回调落地：验签通过后由控制器调用。
     *
     * 成功后支付单进入 frozen（担保冻结），订单进入 paid；
     * 两者同一事务，避免「钱到了订单没动」或反之。
     */
    public function applyNotification(string $rawBody, array $headers): ?Payment
    {
        $notification = $this->channel->verifyNotify($rawBody, $headers);

        if ($notification === null) {
            return null;
        }

        $payment = Payment::query()->where('payment_no', $this->paymentNoFromBody($rawBody))->first();

        if ($payment === null) {
            return null;
        }

        return $this->markPaid($payment, $notification);
    }

    /** 支付成功（pending → paid → frozen），并把订单推到 paid */
    public function markPaid(Payment $payment, PaymentNotification $notification): Payment
    {
        return DB::transaction(function () use ($payment, $notification) {
            /** @var Payment $current */
            $current = Payment::query()->whereKey($payment->payment_id)->lockForUpdate()->firstOrFail();

            // 重复通知 / 已终态：直接返回，不重复改单
            if ($current->status !== PaymentStatus::Pending) {
                return $current;
            }

            if (! $notification->succeeded) {
                $current->transitTo(PaymentStatus::Failed, ['raw_payload' => $notification->raw]);

                return $current->refresh();
            }

            // 金额必须与支付单一致（按分比对，避免 100 与 100.00 的字符串差异）
            if ($this->cents($current->amount) !== $this->cents($notification->amount)) {
                throw new TradeException('回调金额与支付单金额不一致');
            }

            $current->transitTo(PaymentStatus::Paid, [
                'provider_trade_no' => $notification->providerTradeNo,
                'paid_at' => Carbon::parse($notification->paidAt),
                'raw_payload' => $notification->raw,
            ]);

            // 担保交易：钱先冻结在我方账户，买家确认收货后才分账
            $current->transitTo(PaymentStatus::Frozen, ['frozen_at' => Carbon::now()]);

            $this->orders->markPaid((int) $current->order_id);

            return $current->refresh();
        }, 3);
    }

    /** 买家确认收货后分账结算（frozen → splitting → settled） */
    public function settle(int $paymentId): Payment
    {
        return DB::transaction(function () use ($paymentId) {
            /** @var Payment $payment */
            $payment = Payment::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();

            if ($payment->status === PaymentStatus::Settled) {
                return $payment;
            }

            if ($payment->status !== PaymentStatus::Frozen) {
                throw new TradeException('只有冻结中的支付单可以分账');
            }

            $payment->transitTo(PaymentStatus::Splitting);
            $payment->transitTo(PaymentStatus::Settled, ['settled_at' => Carbon::now()]);

            return $payment->refresh();
        }, 3);
    }

    /**
     * 按订单结算（买家确认收货时由控制器编排调用）。
     *
     * 不在这里反向调用 OrderService：两者互相依赖会成环，
     * 「确认收货」与「资金解冻」的先后由调用方决定，服务层只管自己那段状态机。
     */
    public function settleByOrder(int $orderId): ?Payment
    {
        $paymentId = Payment::query()
            ->where('order_id', $orderId)
            ->where('status', PaymentStatus::Frozen->value)
            ->value('payment_id');

        return $paymentId === null ? null : $this->settle((int) $paymentId);
    }

    /** 退款（已付/冻结/已结算均可退），订单同步进入 refunded */
    public function refund(int $paymentId, string $reason): Payment
    {
        return DB::transaction(function () use ($paymentId, $reason) {
            /** @var Payment $payment */
            $payment = Payment::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();

            if ($payment->status === PaymentStatus::Refunded) {
                return $payment;
            }

            if (in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Failed], true)) {
                throw new TradeException('未成功支付的支付单无需退款');
            }

            $channelResponse = $this->channel->refund($payment, (string) $payment->amount, $reason);

            $payment->transitTo(PaymentStatus::Refunded, [
                'raw_payload' => array_merge($payment->raw_payload ?? [], ['refund' => $channelResponse]),
            ]);

            $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->first();

            if ($order !== null && $order->status === OrderStatus::Paid) {
                $order->transitTo(OrderStatus::Refunding, ['remark' => $reason]);
                $order->transitTo(OrderStatus::Refunded, ['escrow_status' => 'refunded']);
            }

            return $payment->refresh();
        }, 3);
    }

    private function assertOwner(Payment $payment, int $buyerId): void
    {
        $owner = Order::query()->whereKey($payment->order_id)->value('buyer_id');

        if ((int) $owner !== $buyerId) {
            throw new TradeException('无权操作他人的支付单');
        }
    }

    /** 回调体里只有渠道视角的数据，我方单号需由渠道原样带回 */
    private function paymentNoFromBody(string $rawBody): string
    {
        $payload = json_decode($rawBody, true);

        return is_array($payload) && isset($payload['payment_no']) ? (string) $payload['payment_no'] : '';
    }

    private function paymentNo(): string
    {
        return 'PM'.Carbon::now()->format('ymdHis').strtoupper(bin2hex(random_bytes(4)));
    }

    private function cents(string|float|int $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function subtract(string $minuend, string $subtrahend): string
    {
        return number_format((($this->cents($minuend) - $this->cents($subtrahend)) / 100), 2, '.', '');
    }
}

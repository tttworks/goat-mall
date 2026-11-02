<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Refund;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Exceptions\TradeException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\Payment\PaymentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 退款/售后（T4）：买家申请 → 管理员审核 → 渠道退款。
 *
 * 为什么不是「买家点了就退」：担保交易里钱在我方冻结，秒退会让「已发货但未收货」的
 * 货钱两空；因此退款是审核制，管理员同意时才真正调渠道（PaymentService::refund）。
 *
 * 幂等：同一订单同一时刻只允许一条处理中的退款单（Pending/Failed 视为处理中），
 * 买家重复点击返回已存在那条，而不是再开一条导致重复退款。
 */
class RefundService
{
    public function __construct(private readonly PaymentService $payments) {}

    public function apply(int $buyerId, int $orderId, string $reason): Refund
    {
        return DB::transaction(function () use ($buyerId, $orderId, $reason) {
            /** @var Order $order */
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();

            if ((int) $order->buyer_id !== $buyerId) {
                throw new TradeException('无权申请他人订单的退款');
            }

            if (! $order->status->holdsFunds()) {
                throw new TradeException('订单当前状态不支持退款（需在付款后、确认收货前申请）');
            }

            $open = Refund::query()
                ->where('order_id', $order->order_id)
                ->whereIn('status', [RefundStatus::Pending->value, RefundStatus::Failed->value])
                ->first();

            if ($open !== null) {
                return $open;
            }

            $payment = $this->settleablePayment((int) $order->order_id);

            return Refund::query()->create([
                'refund_no' => Refund::nextNo(),
                'order_id' => $order->order_id,
                'payment_id' => $payment?->payment_id,
                'buyer_id' => $buyerId,
                'amount' => (string) $order->payable_amount,
                'currency' => (string) $order->currency,
                'reason' => $reason,
                'status' => RefundStatus::Pending,
            ]);
        }, 3);
    }

    /**
     * 管理员审核：同意则调渠道退款并把订单推到 refunded；驳回只改退款单状态，不动订单。
     */
    public function review(Refund $refund, int $adminId, bool $approve, string $remark = ''): Refund
    {
        return DB::transaction(function () use ($refund, $adminId, $approve, $remark) {
            /** @var Refund $locked */
            $locked = Refund::query()->whereKey($refund->refund_id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isOpen()) {
                // 已处理过：直接回读，不重复退款（这正是审核按钮被连点时的期望行为）
                return $locked;
            }

            $attributes = [
                'remark' => $remark !== '' ? $remark : $locked->remark,
                'reviewed_by' => $adminId,
                'reviewed_at' => Carbon::now(),
            ];

            if (! $approve) {
                return $locked->transitTo(RefundStatus::Rejected, $attributes)->refresh();
            }

            $paymentId = $locked->payment_id
                ?: $this->settleablePayment((int) $locked->order_id)?->payment_id;

            if ($paymentId === null) {
                throw new TradeException('找不到可退款的支付单，请核对订单支付状态');
            }

            $this->payments->refund((int) $paymentId, $locked->reason !== '' ? $locked->reason : '管理员退款');

            return $locked->transitTo(RefundStatus::Refunded, $attributes + [
                'payment_id' => $paymentId,
                'refunded_at' => Carbon::now(),
            ])->refresh();
        }, 3);
    }

    /**
     * 取这张订单「最该被退的那笔支付」：冻结中优先（担保期内），其次已结算，最后已支付。
     * 一笔订单可能有多笔支付（首次失败后重付），退款必须退成功的那笔。
     */
    private function settleablePayment(int $orderId): ?Payment
    {
        foreach ([PaymentStatus::Frozen, PaymentStatus::Settled, PaymentStatus::Paid] as $status) {
            $payment = Payment::query()
                ->where('order_id', $orderId)
                ->where('status', $status->value)
                ->orderByDesc('payment_id')
                ->first();

            if ($payment !== null) {
                return $payment;
            }
        }

        return null;
    }
}

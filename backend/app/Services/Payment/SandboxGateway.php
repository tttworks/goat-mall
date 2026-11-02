<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Payment;

use App\Enums\PaymentStatus;
use App\Exceptions\TradeException;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payment\Contracts\PaymentChannel;
use App\Services\Payment\Drivers\SandboxChannel;
use Illuminate\Support\Carbon;

/**
 * 沙箱「模拟支付」（T5，仅非生产通道可用）。
 *
 * 目的：无商户号阶段也能演示完整闭环（下单 → 支付 → 冻结 → 发货 → 确认收货 → 结算）。
 * 做法不是直接改库：它自己签一条异步通知，再走 PaymentService::applyNotification 的正规验签链路，
 * 所以沙箱演示跑通 == 真实回调逻辑跑通，不会出现「演示一套、上线另一套」。
 */
class SandboxGateway
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentChannel $channel,
    ) {}

    public function pay(int $buyerId, int $paymentId): Payment
    {
        if (! $this->channel instanceof SandboxChannel) {
            throw new TradeException('模拟支付仅在沙箱通道下可用');
        }

        $payment = Payment::query()->whereKey($paymentId)->firstOrFail();
        $order = Order::query()->whereKey($payment->order_id)->firstOrFail();

        if ((int) $order->buyer_id !== $buyerId) {
            throw new TradeException('只能为自己的订单付款');
        }

        if ($payment->status !== PaymentStatus::Pending) {
            throw new TradeException('该支付单当前状态不可支付（'.$payment->status->label().'）');
        }

        // 字段集必须与真实回调一致（payment_no 是验签后定位支付单的唯一凭据）
        $envelope = $this->channel->signNotification([
            'payment_no' => (string) $payment->payment_no,
            'provider_trade_no' => 'sbx_'.$payment->payment_no,
            'amount' => (string) $payment->amount,
            'succeeded' => true,
            'paid_at' => Carbon::now()->toIso8601String(),
        ]);

        $paid = $this->payments->applyNotification($envelope['raw'], $envelope['headers']);

        if ($paid === null) {
            // 验签自己刚签的包都失败，说明密钥配置有问题，必须显式报错而不是静默成功
            throw new TradeException('沙箱回调验签失败，请检查 GOAT_PAYMENT_SANDBOX_SECRET');
        }

        return $paid;
    }
}

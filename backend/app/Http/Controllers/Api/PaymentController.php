<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api;

use App\Exceptions\TradeException;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Notify\NotificationService;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * 支付（T5）：发起、渠道回调、查询。
 *
 * 回调接口刻意放在 auth 之外——渠道服务器不会带用户 Token，
 * 它的身份靠签名证明；验签失败一律 400 且不落库。
 */
class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly NotificationService $notifications,
    ) {}

    /** 发起支付：同一 idempotency_key 重复调用返回同一支付单，不重复下单 */
    public function store(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:64'],
        ]);

        $result = $this->payments->initiate(
            (int) $request->user()->user_id,
            $orderId,
            $validated['idempotency_key']
        );

        return response()->json([
            'data' => $this->payload($result['payment'], true),
            'payment_params' => $result['payload'],
        ], 201);
    }

    /** 渠道异步通知：验签 → 金额比对 → 置为已付并冻结 */
    public function notify(Request $request): JsonResponse
    {
        $raw = $request->getContent();

        try {
            $payment = $this->payments->applyNotification($raw, $request->headers->all());
        } catch (TradeException $e) {
            // 验签过了但金额对不上等异常：记日志并按失败应答，渠道会重试
            logger()->warning('payment notify rejected', ['reason' => $e->getMessage(), 'body' => $raw]);

            return response()->json(['code' => 'REJECTED', 'message' => $e->getMessage()], 422);
        }

        if ($payment === null) {
            return response()->json(['code' => 'INVALID_SIGNATURE'], 400);
        }

        // 通知只对「真的付成功」的支付单发；渠道重推时靠 dedupe_key 命中同一条，不会重复打扰
        if (! in_array($payment->status->value, ['pending', 'failed'], true)) {
            $this->notifyPaid($payment);
        }

        return response()->json(['code' => 'SUCCESS', 'data' => $this->payload($payment)]);
    }

    /** 支付成功双通知：买家确认已付，卖家去发货（两边的下一步动作不同，不能合成一条） */
    private function notifyPaid(Payment $payment): void
    {
        $order = Order::query()->whereKey($payment->order_id)->first();

        if ($order === null) {
            return;
        }

        $this->notifications->push(
            (int) $order->buyer_id,
            'order.paid',
            '支付成功',
            sprintf('订单 %s 已付款，资金由平台担保，确认收货后结算给卖家', $order->order_no),
            ['order_id' => (int) $order->order_id, 'payment_id' => (int) $payment->payment_id],
            'order:'.$order->order_id.':paid'
        );

        $this->notifications->push(
            (int) $order->seller_id,
            'order.pending_ship',
            '买家已付款，请尽快发货',
            sprintf('订单 %s 待发货', $order->order_no),
            ['order_id' => (int) $order->order_id],
            'order:'.$order->order_id.':pending_ship'
        );
    }

    /** 查询支付单（仅订单买家或卖家可见） */
    public function show(Request $request, int $paymentId): JsonResponse
    {
        /** @var Payment $payment */
        $payment = Payment::query()->whereKey($paymentId)->firstOrFail();

        $order = Order::query()->whereKey($payment->order_id)->firstOrFail();
        $userId = (int) $request->user()->user_id;

        if ((int) $order->buyer_id !== $userId && (int) $order->seller_id !== $userId) {
            throw new TradeException('无权查看他人的支付单');
        }

        return response()->json(['data' => $this->payload($payment, true)]);
    }

    /** @return array<string, mixed> */
    private function payload(Payment $payment, bool $detail = false): array
    {
        $data = [
            'payment_id' => $payment->payment_id,
            'payment_no' => $payment->payment_no,
            'order_id' => $payment->order_id,
            'channel' => $payment->channel,
            'status' => $payment->status->value,
            'status_label' => $payment->status->label(),
            'amount' => (string) $payment->amount,
            'currency' => $payment->currency,
        ];

        if ($detail) {
            $data['platform_fee_amount'] = (string) $payment->platform_fee_amount;
            $data['seller_amount'] = (string) $payment->seller_amount;
            $data['provider_trade_no'] = $payment->provider_trade_no;
            $data['paid_at'] = $payment->paid_at?->toIso8601String();
            $data['frozen_at'] = $payment->frozen_at?->toIso8601String();
            $data['settled_at'] = $payment->settled_at?->toIso8601String();
        }

        return $data;
    }
}

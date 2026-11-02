<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Api\RefundController as RefundPayload;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * 后台订单管理（T8 出口：全平台订单可视、可查、可核对资金）。
 *
 * 只读 + 备注，**不提供后台改单/改价**：金额来自下单快照与支付流水，
 * 后台能改就意味着能改账，这类能力要走「工单 + 双人复核」，不该是一个按钮。
 * 唯一写操作是运营备注（写明「为什么这单要人工介入」）。
 */
class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(array_column(OrderStatus::cases(), 'value'))],
            'keyword' => ['nullable', 'string', 'max:64'],
            'buyer_id' => ['nullable', 'integer'],
            'seller_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $page = Order::query()
            ->with(['items', 'buyer', 'seller'])
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['keyword'] ?? null, fn ($query, $keyword) => $query->where('order_no', 'like', '%'.$keyword.'%'))
            ->when($validated['buyer_id'] ?? null, fn ($query, $id) => $query->where('buyer_id', (int) $id))
            ->when($validated['seller_id'] ?? null, fn ($query, $id) => $query->where('seller_id', (int) $id))
            ->when($validated['date_from'] ?? null, fn ($query, $date) => $query->where('created_at', '>=', $date.' 00:00:00'))
            ->when($validated['date_to'] ?? null, fn ($query, $date) => $query->where('created_at', '<=', $date.' 23:59:59'))
            ->orderByDesc('order_id')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => collect($page->items())->map(fn (Order $order) => $this->row($order))->all(),
            'meta' => [
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'statuses' => collect(OrderStatus::cases())->map(fn (OrderStatus $status) => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ])->all(),
            ],
        ]);
    }

    public function show(int $orderId): JsonResponse
    {
        $order = Order::query()
            ->with(['items', 'buyer', 'seller', 'payments', 'refunds'])
            ->whereKey($orderId)
            ->firstOrFail();

        return response()->json([
            'data' => $this->row($order) + [
                'remark' => $order->remark,
                'fee_snapshot' => $order->fee_snapshot,
                'escrow_status' => $order->escrow_status,
                'expire_at' => $order->expire_at?->toIso8601String(),
                'payments' => $order->payments->map(fn (Payment $payment) => [
                    'payment_id' => $payment->payment_id,
                    'payment_no' => $payment->payment_no,
                    'channel' => $payment->channel,
                    'amount' => (string) $payment->amount,
                    'status' => $payment->status->value,
                    'status_label' => $payment->status->label(),
                    'paid_at' => $payment->paid_at?->toIso8601String(),
                ])->all(),
                'refunds' => $order->refunds->map(fn (Refund $refund) => RefundPayload::payload($refund))->all(),
            ],
        ]);
    }

    /** 运营备注：唯一允许后台写入的订单字段 */
    public function remark(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:255'],
        ]);

        $order = Order::query()->whereKey($orderId)->firstOrFail();
        $order->fill(['remark' => $validated['remark']])->save();

        return response()->json(['data' => $this->row($order->refresh())]);
    }

    /** @return array<string, mixed> */
    private function row(Order $order): array
    {
        $first = $order->items->first();

        return [
            'order_id' => $order->order_id,
            'order_no' => $order->order_no,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'goods_amount' => (string) $order->goods_amount,
            'fee_amount' => (string) $order->fee_amount,
            'payable_amount' => (string) $order->payable_amount,
            'shipping_amount' => (string) $order->shipping_amount,
            'currency' => $order->currency,
            // 列表只带首件标题 + 件数，详情页才给明细（一次拉 30 单全量 items 太重）
            'title' => $first?->title_snapshot,
            'item_count' => $order->items->sum('quantity'),
            'buyer' => $order->buyer === null ? null : [
                'user_id' => $order->buyer->user_id,
                'nickname' => $order->buyer->nickname,
            ],
            'seller' => $order->seller === null ? null : [
                'user_id' => $order->seller->user_id,
                'nickname' => $order->seller->nickname,
            ],
            'paid_at' => $order->paid_at?->toIso8601String(),
            'shipped_at' => $order->shipped_at?->toIso8601String(),
            'confirmed_at' => $order->confirmed_at?->toIso8601String(),
            'created_at' => $order->created_at?->toIso8601String(),
            'items' => $order->items->map(fn ($item) => [
                'order_item_id' => $item->order_item_id,
                'listing_id' => $item->listing_id,
                'title' => $item->title_snapshot,
                'quantity' => $item->quantity,
                'unit_price' => (string) $item->unit_price,
                'subtotal' => (string) $item->subtotal,
                'cover_url' => $item->coverUrl(),
            ])->all(),
        ];
    }
}

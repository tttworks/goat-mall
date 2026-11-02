<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api;

use App\Models\Order;
use App\Services\Trade\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * 下单 / 取消（T4）。买家身份取自登录态（认证由 T2 提供，此处不重复实现）。
 * 幂等键由客户端生成并随请求头 Idempotency-Key 传入，缺失直接 422（不静默补一个随机值，
 * 否则重试会变成重复下单）。
 */
class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'listing_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:20'],
            'shipping_amount' => ['nullable', 'numeric', 'min:0'],
            'remark' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ]);

        $key = (string) ($request->header('Idempotency-Key') ?: ($validated['idempotency_key'] ?? ''));
        if ($key === '') {
            return response()->json(['message' => '缺少幂等键（Idempotency-Key）'], 422);
        }

        $result = $this->orders->checkout(
            (int) $request->user()->user_id,
            (int) $validated['listing_id'],
            $key,
            array_filter([
                'quantity' => $validated['quantity'] ?? 1,
                'shipping_amount' => $validated['shipping_amount'] ?? '0',
                'remark' => $validated['remark'] ?? null,
            ], static fn ($v) => $v !== null)
        );

        return response()->json([
            'data' => $this->payload($result['order']),
            'fee' => $result['quote']->toArray(),
            'replayed' => $result['replayed'],
        ], $result['replayed'] ? 200 : 201);
    }

    public function show(Request $request, int $orderId): JsonResponse
    {
        $order = Order::query()->with('items')->whereKey($orderId)->firstOrFail();

        if ((int) $order->buyer_id !== (int) $request->user()->user_id
            && (int) $order->seller_id !== (int) $request->user()->user_id) {
            return response()->json(['message' => '无权查看该订单'], 403);
        }

        return response()->json(['data' => $this->payload($order, true)]);
    }

    public function cancel(Request $request, int $orderId): JsonResponse
    {
        $order = Order::query()->whereKey($orderId)->firstOrFail();

        if ((int) $order->buyer_id !== (int) $request->user()->user_id) {
            return response()->json(['message' => '只有买家可取消订单'], 403);
        }

        $reason = (string) $request->input('reason', '');

        return response()->json(['data' => $this->payload($this->orders->cancel($order, $reason))]);
    }

    /** @return array<string, mixed> */
    private function payload(Order $order, bool $detail = false): array
    {
        $data = [
            'order_id' => $order->order_id,
            'order_no' => $order->order_no,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'escrow_status' => $order->escrow_status,
            'goods_amount' => (string) $order->goods_amount,
            'shipping_amount' => (string) $order->shipping_amount,
            'fee_amount' => (string) $order->fee_amount,
            'payable_amount' => (string) $order->payable_amount,
            'currency' => $order->currency,
            'expire_at' => $order->expire_at?->toIso8601String(),
            'created_at' => $order->created_at?->toIso8601String(),
        ];

        if ($detail) {
            $data['seller_id'] = $order->seller_id;
            $data['fee_bearer'] = $order->fee_bearer;
            $data['items'] = $order->items->map(fn ($item) => [
                'order_item_id' => $item->order_item_id,
                'listing_id' => $item->listing_id,
                'title' => $item->title_snapshot,
                'cover_url' => $item->coverUrl(),
                'quantity' => $item->quantity,
                'unit_price' => (string) $item->unit_price,
                'subtotal' => (string) $item->subtotal,
            ]);
        }

        return $data;
    }
}

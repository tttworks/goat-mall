<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api;

use App\Models\Refund;
use App\Services\Refund\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * 买家退款申请（T4 售后）。
 *
 * 只做「申请 + 查询」：真正退钱在后台审核通过时发生（Admin\RefundController），
 * 这样买家一侧不存在「点一下钱就没了」的路径。
 */
class RefundController extends Controller
{
    public function __construct(private readonly RefundService $refunds) {}

    public function store(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:2', 'max:200'],
        ]);

        $refund = $this->refunds->apply(
            (int) $request->user()->user_id,
            $orderId,
            (string) $validated['reason']
        );

        return response()->json(['data' => $this->payload($refund)], 201);
    }

    /** 我的退款单（买家视角；后台那条队列在 admin 分组里） */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:16'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $page = Refund::query()
            ->with('order')
            ->where('buyer_id', (int) $request->user()->user_id)
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('refund_id')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => collect($page->items())->map(fn (Refund $refund) => $this->payload($refund))->all(),
            'meta' => [
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    public static function payload(Refund $refund): array
    {
        return [
            'refund_id' => $refund->refund_id,
            'refund_no' => $refund->refund_no,
            'order_id' => $refund->order_id,
            'order_no' => $refund->relationLoaded('order') ? $refund->order?->order_no : null,
            'payment_id' => $refund->payment_id,
            'amount' => (string) $refund->amount,
            'currency' => $refund->currency,
            'reason' => $refund->reason,
            'status' => $refund->status->value,
            'status_label' => $refund->status->label(),
            'remark' => $refund->remark,
            'reviewed_at' => $refund->reviewed_at?->toIso8601String(),
            'refunded_at' => $refund->refunded_at?->toIso8601String(),
            'created_at' => $refund->created_at?->toIso8601String(),
        ];
    }
}

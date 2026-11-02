<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api\Admin;

use App\Enums\RefundStatus;
use App\Http\Controllers\Api\RefundController as RefundPayload;
use App\Models\Refund;
use App\Services\Refund\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * 后台退款审核队列（T4 售后）。
 *
 * 默认只出 pending：审核是「今日待办」，默认把已处理的历史混进来等于让运营翻页找活。
 */
class RefundController extends Controller
{
    public function __construct(private readonly RefundService $refunds) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(array_column(RefundStatus::cases(), 'value'))],
            'keyword' => ['nullable', 'string', 'max:64'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $status = $validated['status'] ?? RefundStatus::Pending->value;

        $page = Refund::query()
            ->with(['order', 'buyer'])
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($validated['keyword'] ?? null, function ($query, $keyword) {
                $query->where(fn ($q) => $q->where('refund_no', 'like', '%'.$keyword.'%')
                    ->orWhere('order_id', 'like', '%'.$keyword.'%'));
            })
            ->orderBy('status')
            ->orderByDesc('refund_id')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => collect($page->items())->map(fn (Refund $refund) => RefundPayload::payload($refund) + [
                'buyer' => $refund->buyer === null ? null : [
                    'user_id' => $refund->buyer->user_id,
                    'nickname' => $refund->buyer->nickname,
                ],
                'order' => $refund->order === null ? null : [
                    'order_id' => $refund->order->order_id,
                    'order_no' => $refund->order->order_no,
                    'status' => $refund->order->status->value,
                    'goods_amount' => (string) $refund->order->goods_amount,
                    'payable_amount' => (string) $refund->order->payable_amount,
                    'paid_at' => $refund->order->paid_at?->toIso8601String(),
                ],
            ])->all(),
            'meta' => [
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'pending_total' => Refund::query()->where('status', RefundStatus::Pending->value)->count(),
            ],
        ]);
    }

    public function review(Request $request, int $refundId): JsonResponse
    {
        $validated = $request->validate([
            'approve' => ['required', 'boolean'],
            // 驳回必须给理由：买家会看到这句话，空着等于「不解释就不退」
            'remark' => ['nullable', 'string', 'max:255', 'required_if:approve,false'],
        ]);

        $refund = Refund::query()->whereKey($refundId)->firstOrFail();

        $refund = $this->refunds->review(
            $refund,
            (int) $request->user()->user_id,
            (bool) $validated['approve'],
            (string) ($validated['remark'] ?? '')
        );

        return response()->json(['data' => RefundPayload::payload($refund)]);
    }
}

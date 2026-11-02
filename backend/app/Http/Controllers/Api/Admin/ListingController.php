<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\ListingReviewRequest;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use App\Services\Trade\ListingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * 商品审核（T4，仅 admin）。
 *
 * 默认只出「待审核」队列并按提交先后排序：先提交先审，避免热门卖家插队。
 */
class ListingController extends Controller
{
    public function __construct(private readonly ListingService $listings) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:24'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $page = Listing::query()
            ->when(
                $validated['status'] ?? 'pending_review',
                fn ($query, $status) => $query->where('status', $status)
            )
            ->with(['figure', 'seller'])
            // 无独立的提交时间列：审核队列按「进入待审的时刻」近似取 updated_at
            ->orderBy('updated_at')
            ->orderBy('listing_id')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => ListingResource::collection($page->items(), true),
            'meta' => [
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }

    public function review(ListingReviewRequest $request, int $listingId): JsonResponse
    {
        $listing = Listing::query()->whereKey($listingId)->firstOrFail();

        $listing = $this->listings->review(
            $listing,
            $request->validated('action') === 'approve',
            $request->validated('reason')
        );

        return response()->json(['data' => ListingResource::make($listing, true)]);
    }
}

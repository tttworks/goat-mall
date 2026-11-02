<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api;

use App\Http\Requests\ListingStoreRequest;
use App\Http\Requests\ListingUpdateRequest;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use App\Services\Trade\ListingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * 卖家商品管理（T4）。所有查询强制带 seller_id 条件，
 * 不靠「先按 id 取出再判断归属」——后者一旦漏判就是越权读写。
 */
class SellerListingController extends Controller
{
    public function __construct(private readonly ListingService $listings) {}

    /** 我的商品：默认全部，可按状态筛（草稿/待审/被驳回/在售/已下架） */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:24'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $page = Listing::query()
            ->where('seller_id', (int) $request->user()->user_id)
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->with('figure')
            ->orderByDesc('listing_id')
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

    /** 创建草稿（不直接上架：先存草稿再提交审核，避免半成品暴露给买家） */
    public function store(ListingStoreRequest $request): JsonResponse
    {
        $listing = $this->listings->create($request->user(), $request->validated());

        return response()->json(['data' => ListingResource::make($listing, true)], 201);
    }

    public function update(ListingUpdateRequest $request, int $listingId): JsonResponse
    {
        $listing = $this->owned($request, $listingId);
        $listing = $this->listings->update($request->user(), $listing, $request->validated());

        return response()->json(['data' => ListingResource::make($listing, true)]);
    }

    /** 提交审核：状态机内部会挡掉「非草稿/非驳回」的商品 */
    public function submit(Request $request, int $listingId): JsonResponse
    {
        $listing = $this->listings->submit($request->user(), $this->owned($request, $listingId));

        return response()->json(['data' => ListingResource::make($listing, true)]);
    }

    /** 下架（在售 → 已下架），已产生的订单不受影响 */
    public function delist(Request $request, int $listingId): JsonResponse
    {
        $listing = $this->listings->delist($request->user(), $this->owned($request, $listingId));

        return response()->json(['data' => ListingResource::make($listing, true)]);
    }

    /** 退回草稿修改（已下架 → 草稿） */
    public function reopen(Request $request, int $listingId): JsonResponse
    {
        $listing = $this->listings->reopen($request->user(), $this->owned($request, $listingId));

        return response()->json(['data' => ListingResource::make($listing, true)]);
    }

    private function owned(Request $request, int $listingId): Listing
    {
        return Listing::query()
            ->where('seller_id', (int) $request->user()->user_id)
            ->whereKey($listingId)
            ->firstOrFail();
    }
}

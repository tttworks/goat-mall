<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api;

use App\Enums\ListingStatus;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * 商品浏览（T4）：列表多维筛选 + 详情。写操作（上架/改价/下架）由卖家端走同一模块的后续迭代。
 * 只暴露在售商品；下架/售出仅本人可见（由卖家端接口处理，不在公开列表出现）。
 */
class ListingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:64'],
            'figure_id' => ['nullable', 'integer', 'min:1'],
            'seller_id' => ['nullable', 'integer', 'min:1'],
            'condition' => ['nullable', 'string', 'in:new,like_new,used_good,used_fair,defective,for_parts'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'sort' => ['nullable', 'string', 'in:latest,price_asc,price_desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);

        // 说明：Figure 模型/figures 表由 T3 提供（站点只读）；未就位前调用本接口会报类不存在，
        // 属跨 owner 依赖，不在 T4 内私建 Figure 模型。
        $query = Listing::query()
            ->with(['figure:figure_id,name,series,maker,scale,cover_key'])
            ->where('status', ListingStatus::Active->value)
            ->where('stock', '>', 0);

        if (! empty($validated['keyword'])) {
            // whereLike 由 Builder 按驱动生成 ILIKE / LIKE：SQLite 测试库没有 ILIKE 语法，
            // 直接写 ilike 会在本地与 CI 直接 500（搜索页是 C 端入口，不能只在 PG 上活）
            $keyword = '%'.$validated['keyword'].'%';
            $query->where(fn ($q) => $q->whereLike('title', $keyword)->orWhereLike('description', $keyword));
        }

        foreach (['figure_id', 'seller_id', 'condition'] as $field) {
            if (! empty($validated[$field])) {
                $query->where($field === 'condition' ? 'condition_enum' : $field, $validated[$field]);
            }
        }

        if (isset($validated['min_price'])) {
            $query->where('price', '>=', $validated['min_price']);
        }
        if (isset($validated['max_price'])) {
            $query->where('price', '<=', $validated['max_price']);
        }

        match ($validated['sort'] ?? 'latest') {
            'price_asc' => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            default => $query->orderByDesc('published_at')->orderByDesc('listing_id'),
        };

        $page = $query->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => ListingResource::collection($page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(int $listingId): JsonResponse
    {
        $listing = Listing::query()
            ->with(['figure:figure_id,name,series,maker,scale,cover_key'])
            ->whereKey($listingId)
            ->firstOrFail();

        return response()->json(['data' => ListingResource::make($listing, true)]);
    }
}

<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api;

use App\Enums\FigureCategory;
use App\Exceptions\CatalogException;
use App\Http\Resources\FigureResource;
use App\Models\Figure;
use App\Models\Listing;
use App\Services\Catalog\FigureSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;

/**
 * 图鑑浏览（T3，公开）。只暴露 published 条目；草稿/归档即使知道 slug 也拿不到。
 */
class FigureController extends Controller
{
    public function __construct(private readonly FigureSearchService $search) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:64'],
            'maker' => ['nullable', 'string', 'max:120'],
            'series' => ['nullable', 'string', 'max:120'],
            'scale' => ['nullable', 'string', 'max:24'],
            'category' => ['nullable', 'string', 'max:32'],
            'year' => ['nullable', 'integer', 'between:1950,2100'],
            'year_from' => ['nullable', 'integer', 'between:1950,2100'],
            'year_to' => ['nullable', 'integer', 'between:1950,2100'],
            'sort' => ['nullable', 'string', 'in:latest,year_desc,year_asc,price_low_asc,popular'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);

        $page = $this->search->paginate($validated, false, (int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => FigureResource::collection($page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                // 筛选器选项随列表返回，避免前端再硬编码一份品类中文名
                'categories' => FigureCategory::options(),
            ],
        ]);
    }

    /** 支持 figure_id 与 slug 两种路径（分享链接用 slug，站内跳转用 id） */
    public function show(Request $request, string $figure): JsonResponse
    {
        $record = Figure::query()
            ->published()
            ->with('sources')
            ->withCount('listings')
            ->when(
                ctype_digit($figure),
                fn ($query) => $query->where('figure_id', (int) $figure),
                fn ($query) => $query->where('slug', $figure),
            )
            ->first();

        if ($record === null) {
            throw CatalogException::notFound();
        }

        $this->countView($record, $request);

        $payload = FigureResource::make($record, true);
        $payload['listings'] = $this->availableListings($record->figure_id);

        return response()->json(['data' => $payload]);
    }

    /**
     * 浏览计数：同一 IP 对同一条目 1 小时内只计一次。
     * 不做精确 UV（成本高且易被刷），只为「热度排序」提供可用信号。
     */
    private function countView(Figure $figure, Request $request): void
    {
        $key = 'figure:view:'.$figure->figure_id.':'.sha1((string) $request->ip());

        if (Cache::add($key, 1, 3600)) {
            Figure::query()->whereKey($figure->figure_id)->increment('views');
        }
    }

    /**
     * 该图鑑下在售商品（详情页「现在能买到」模块）。
     *
     * @return array<int, array<string, mixed>>
     */
    private function availableListings(int $figureId): array
    {
        return Listing::query()
            ->purchasable()
            ->where('figure_id', $figureId)
            ->orderByDesc('published_at')
            ->orderByDesc('listing_id')
            ->limit(8)
            ->get()
            ->map(static fn (Listing $listing) => [
                'listing_id' => (int) $listing->listing_id,
                'title' => $listing->title,
                'price' => (string) $listing->price,
                'currency' => $listing->currency,
                'condition' => $listing->condition_enum,
                'cover_url' => $listing->coverUrl(),
                'seller_id' => (int) $listing->seller_id,
                'published_at' => $listing->published_at?->toIso8601String(),
            ])
            ->all();
    }
}

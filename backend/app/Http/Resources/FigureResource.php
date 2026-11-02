<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Resources;

use App\Models\Figure;
use Illuminate\Support\Collection;

/**
 * 图鑑条目的对外表示（T3）。
 *
 * 放独立类的理由：公开列表与后台管理必须共用同一份字段定义，
 * 否则极易出现「后台改了字段、C 端漏改」的漂移；同时保证 response 里
 * 绝不出现 cover_key / gallery_keys 这类内部对象 key（前端只该拿 URL）。
 */
final class FigureResource
{
    /**
     * @return array<string, mixed>
     */
    public static function make(Figure $figure, bool $detail = false): array
    {
        $data = [
            'figure_id' => (int) $figure->figure_id,
            'slug' => $figure->slug,
            'name' => $figure->name,
            'name_ja' => $figure->name_ja,
            'name_en' => $figure->name_en,
            'aliases' => array_values((array) ($figure->aliases ?? [])),
            'maker' => $figure->maker,
            'series' => $figure->series,
            'scale' => $figure->scale,
            'category' => $figure->category->value,
            'category_label' => $figure->category->label(),
            'year' => $figure->year,
            'release_date' => $figure->release_date?->toDateString(),
            'status' => $figure->status->value,
            'cover_url' => $figure->coverUrl(),
            'price_low' => $figure->price_low === null ? null : (string) $figure->price_low,
            // 列表页要显示「在售 N 件」，来自 withCount('listings')
            'listings_count' => (int) ($figure->listings_count ?? 0),
            'views' => (int) $figure->views,
            'published_at' => $figure->published_at?->toIso8601String(),
        ];

        if ($detail) {
            $data['gallery_urls'] = $figure->galleryUrls();
            $data['specs'] = $figure->specs;
            // 外部 ID 属于溯源信息，只给后台/详情页折叠区，列表不返回
            $data['external_ids'] = (object) ($figure->external_ids ?? []);
            $data['sources'] = self::sources($figure);
        }

        return $data;
    }

    /**
     * @param  iterable<int, Figure>  $figures
     * @return array<int, array<string, mixed>>
     */
    public static function collection(iterable $figures, bool $detail = false): array
    {
        return Collection::make($figures)
            ->map(static fn (Figure $figure) => self::make($figure, $detail))
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function sources(Figure $figure): array
    {
        return $figure->sources
            ->map(static fn ($source) => [
                'source' => $source->source,
                'external_id' => $source->external_id,
                'last_synced_at' => $source->last_synced_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}

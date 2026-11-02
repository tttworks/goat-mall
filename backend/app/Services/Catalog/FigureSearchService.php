<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Catalog;

use App\Models\Figure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 图鑑检索（T3）：关键词走 Meilisearch，结构化筛选走 PG 索引。
 *
 * 设计取舍：不把筛选也交给 Meili。图鑑库规模（万级）用 PG 复合索引足够，
 * 而 Meili 的 filterable 变更需要重建索引；把筛选留在 DB、只把「模糊匹配」外包给 Meili，
 * 好处是 Meili 宕机/索引未建时列表仍可用（降级为 ILIKE），不会整页 500。
 */
class FigureSearchService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, bool $includeUnpublished = false, int $perPage = 20): LengthAwarePaginator
    {
        $query = Figure::query()->withCount('listings');

        if ($includeUnpublished) {
            if (! empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }
        } else {
            // 对外接口无论如何都不暴露草稿/归档（前端传 status 也不认）
            $query->published();
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));

        if ($keyword !== '') {
            $this->applyKeyword($query, $keyword);
        }

        foreach (['maker', 'series', 'scale'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (! empty($filters['year'])) {
            $query->where('year', (int) $filters['year']);
        }

        if (! empty($filters['year_from'])) {
            $query->where('year', '>=', (int) $filters['year_from']);
        }

        if (! empty($filters['year_to'])) {
            $query->where('year', '<=', (int) $filters['year_to']);
        }

        $this->applySort($query, (string) ($filters['sort'] ?? 'latest'));

        return $query->paginate($perPage);
    }

    /**
     * 关键词匹配顺序：
     *  1. Meili 能用 → 取回 figure_id 集合（相关度由 Meili 决定，这里不再二次排序）；
     *  2. Meili 命中为空 → 说明索引未建或真无结果，退回 DB ILIKE（宁可慢也不能「有货搜不到」）；
     *  3. Meili 抛异常 → 同上降级，并记 warning 供运维发现索引故障。
     */
    private function applyKeyword(Builder $query, string $keyword): void
    {
        if ($this->meilisearchEnabled()) {
            try {
                $ids = Figure::search($keyword)->take(500)->keys()->all();

                if ($ids !== []) {
                    $query->whereIn('figure_id', $ids);

                    return;
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        $like = '%'.$keyword.'%';
        // PG 用 ilike（大小写不敏感 + 可命中 trigram 索引）；SQLite 只有 like（测试环境够用）
        $operator = $this->isPostgres() ? 'ilike' : 'like';
        // aliases 是 jsonb 数组：PG 转 text 再匹配，SQLite 本身就是 text 列
        $aliasExpression = $this->isPostgres() ? 'aliases::text' : 'aliases';

        $query->where(function (Builder $inner) use ($like, $operator, $aliasExpression) {
            $inner->where('name', $operator, $like)
                ->orWhere('name_ja', $operator, $like)
                ->orWhere('name_en', $operator, $like)
                ->orWhere('series', $operator, $like)
                ->orWhere('maker', $operator, $like)
                ->orWhereRaw("{$aliasExpression} {$operator} ?", [$like]);
        });
    }

    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'year_desc' => $query->orderByDesc('year')->orderByDesc('figure_id'),
            'year_asc' => $query->orderBy('year')->orderBy('figure_id'),
            'price_low_asc' => $query->orderByRaw('price_low IS NULL ASC')->orderBy('price_low'),
            'popular' => $query->orderByDesc('views')->orderByDesc('figure_id'),
            // 默认：最新发布在前（草稿的 published_at 为空，排最后）
            default => $query->orderByRaw('published_at IS NULL ASC')->orderByDesc('published_at')->orderByDesc('figure_id'),
        };
    }

    /** 只有真正配了 Meili 才尝试，测试/本地（SCOUT_DRIVER=null,array）不走网络 */
    private function meilisearchEnabled(): bool
    {
        return config('scout.driver') === 'meilisearch' && config('scout.meilisearch.host') !== '';
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
}

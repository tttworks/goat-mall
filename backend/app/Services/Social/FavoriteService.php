<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Social;

use App\Exceptions\SocialException;
use App\Models\Favorite;
use App\Models\Figure;
use App\Models\Listing;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 收藏（T6）。
 *
 * 两条规则：
 * 1. 只能收藏「存在且对外可见」的对象——下架商品/未发布图鑑收了也点不开，
 *    这种收藏等于给用户埋一个死链，直接拒绝；
 * 2. 重复收藏幂等返回，不报错（前端连点不该弹错）。
 */
class FavoriteService
{
    /**
     * @return array{favorite: Favorite, created: bool}
     */
    public function add(int $userId, string $targetType, int $targetId): array
    {
        $this->assertTargetExists($targetType, $targetId);

        $existing = Favorite::query()
            ->where('user_id', $userId)
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->first();

        if ($existing !== null) {
            return ['favorite' => $existing, 'created' => false];
        }

        $favorite = Favorite::query()->create([
            'user_id' => $userId,
            'target_type' => $targetType,
            'target_id' => $targetId,
        ]);

        return ['favorite' => $favorite, 'created' => true];
    }

    public function remove(int $userId, string $targetType, int $targetId): bool
    {
        return Favorite::query()
            ->where('user_id', $userId)
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->delete() > 0;
    }

    public function paginate(int $userId, ?string $targetType = null, int $perPage = 20): LengthAwarePaginator
    {
        return Favorite::query()
            ->where('user_id', $userId)
            ->when($targetType !== null, fn ($query) => $query->where('target_type', $targetType))
            ->orderByDesc('favorite_id')
            ->paginate($perPage);
    }

    /**
     * 收藏项摘要（批量查，避免 N+1）。
     *
     * @param  array<int, Favorite>  $favorites
     * @return array<int, array<string, mixed>>
     */
    public function summaries(array $favorites): array
    {
        $listingIds = [];
        $figureIds = [];

        foreach ($favorites as $favorite) {
            if ($favorite->target_type === Favorite::TARGET_LISTING) {
                $listingIds[] = (int) $favorite->target_id;
            } elseif ($favorite->target_type === Favorite::TARGET_FIGURE) {
                $figureIds[] = (int) $favorite->target_id;
            }
        }

        $listings = $listingIds === [] ? collect() : Listing::query()->whereKey($listingIds)->get()->keyBy('listing_id');
        $figures = $figureIds === [] ? collect() : Figure::query()->whereKey($figureIds)->get()->keyBy('figure_id');

        return array_map(function (Favorite $favorite) use ($listings, $figures) {
            $target = null;

            if ($favorite->target_type === Favorite::TARGET_LISTING) {
                $listing = $listings->get((int) $favorite->target_id);
                $target = $listing === null ? null : [
                    'title' => $listing->title,
                    'price' => (string) $listing->price,
                    'status' => $listing->status->value,
                    'cover_url' => $listing->coverUrl(),
                ];
            } elseif ($favorite->target_type === Favorite::TARGET_FIGURE) {
                $figure = $figures->get((int) $favorite->target_id);
                $target = $figure === null ? null : [
                    'name' => $figure->name,
                    'slug' => $figure->slug,
                    'status' => $figure->status->value,
                    'cover_url' => $figure->coverUrl(),
                ];
            }

            return [
                'favorite_id' => $favorite->favorite_id,
                'target_type' => $favorite->target_type,
                'target_id' => (int) $favorite->target_id,
                // 对象被删除/软删时 target 为 null，前端按「已失效」处理
                'target' => $target,
                'created_at' => $favorite->created_at?->toIso8601String(),
            ];
        }, $favorites);
    }

    private function assertTargetExists(string $targetType, int $targetId): void
    {
        $exists = match ($targetType) {
            Favorite::TARGET_LISTING => Listing::query()->whereKey($targetId)->exists(),
            Favorite::TARGET_FIGURE => Figure::query()->published()->whereKey($targetId)->exists(),
            default => false,
        };

        if (! $exists) {
            throw SocialException::notFound('收藏对象不存在或已失效');
        }
    }
}

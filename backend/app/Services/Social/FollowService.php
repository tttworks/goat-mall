<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Social;

use App\Enums\SellerStatus;
use App\Models\Follow;
use App\Models\SellerProfile;
use App\Models\User;
use App\Exceptions\SocialException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * 关注卖家（T6）。
 *
 * 口径：
 * 1. 只能关注「已开店且审核通过」的卖家——关注一个没有商品产出的账号没有意义；
 * 2. 不能关注自己（否则自己的上新通知会推给自己）；
 * 3. 重复关注幂等（唯一约束 + 冲突回读），取消关注对不存在的记录也返回成功。
 */
class FollowService
{
    /**
     * @return array{follow: Follow, created: bool}
     */
    public function follow(int $followerId, int $sellerId): array
    {
        if ($followerId === $sellerId) {
            throw SocialException::invalid('不能关注自己');
        }

        $this->assertFollowable($sellerId);

        try {
            $follow = Follow::query()->create([
                'follower_id' => $followerId,
                'seller_id' => $sellerId,
            ]);

            return ['follow' => $follow, 'created' => true];
        } catch (UniqueConstraintViolationException) {
            $existing = Follow::query()
                ->where('follower_id', $followerId)
                ->where('seller_id', $sellerId)
                ->firstOrFail();

            return ['follow' => $existing, 'created' => false];
        }
    }

    public function unfollow(int $followerId, int $sellerId): bool
    {
        return Follow::query()
            ->where('follower_id', $followerId)
            ->where('seller_id', $sellerId)
            ->delete() > 0;
    }

    public function paginate(int $followerId, int $perPage = 20): LengthAwarePaginator
    {
        return Follow::query()
            ->where('follower_id', $followerId)
            ->orderByDesc('follow_id')
            ->paginate($perPage);
    }

    public function countFollowers(int $sellerId): int
    {
        return Follow::query()->where('seller_id', $sellerId)->count();
    }

    public function isFollowing(int $followerId, int $sellerId): bool
    {
        return Follow::query()
            ->where('follower_id', $followerId)
            ->where('seller_id', $sellerId)
            ->exists();
    }

    /**
     * 关注列表摘要：店铺名 + 商品数不在这里聚合（列表页只要头像和店名）。
     *
     * @param  array<int, Follow>  $follows
     * @return array<int, array<string, mixed>>
     */
    public function summaries(array $follows): array
    {
        $sellerIds = array_map(static fn (Follow $follow) => (int) $follow->seller_id, $follows);

        $profiles = $sellerIds === []
            ? collect()
            : SellerProfile::query()->whereIn('user_id', $sellerIds)->get()->keyBy('user_id');

        $users = $sellerIds === []
            ? collect()
            : User::query()->whereKey($sellerIds)->get()->keyBy('user_id');

        return array_map(function (Follow $follow) use ($profiles, $users) {
            $profile = $profiles->get((int) $follow->seller_id);
            $user = $users->get((int) $follow->seller_id);

            return [
                'follow_id' => $follow->follow_id,
                'seller_id' => (int) $follow->seller_id,
                'shop_name' => $profile?->shop_name,
                'nickname' => $user?->nickname,
                'avatar_key' => $user?->avatar_key,
                'shop_status' => $profile?->status?->value,
                'created_at' => $follow->created_at?->toIso8601String(),
            ];
        }, $follows);
    }

    private function assertFollowable(int $sellerId): void
    {
        $profile = SellerProfile::query()->where('user_id', $sellerId)->first();

        if ($profile === null || $profile->status !== SellerStatus::Active) {
            throw SocialException::notFound('该卖家不存在或未开店');
        }
    }
}

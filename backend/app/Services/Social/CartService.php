<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Social;

use App\Enums\ListingStatus;
use App\Exceptions\SocialException;
use App\Models\CartItem;
use App\Models\Listing;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * 购物车（T6）。
 *
 * 口径：
 * 1. 加购不锁库——锁库只在下单事务里做，否则「放购物车」会长期占用可售商品；
 * 2. 只能加在售商品，且不能加自己的（自己拍自己没有意义）；
 * 3. 数量恒为 1（C2C 单件）；重复加购幂等，不累加；
 * 4. 列表会重算每个商品「当前是否仍可买」，购物车里的旧价格不作为凭证。
 */
class CartService
{
    /**
     * @return array{item: CartItem, created: bool}
     */
    public function add(int $userId, int $listingId): array
    {
        $listing = Listing::query()->whereKey($listingId)->first();

        if ($listing === null) {
            throw SocialException::notFound('商品不存在');
        }

        if ((int) $listing->seller_id === $userId) {
            throw SocialException::invalid('不能把自己发布的商品加入购物车');
        }

        if ($listing->status !== ListingStatus::Active) {
            throw SocialException::invalid('该商品当前不可购买（'.$listing->status->label().'）');
        }

        $existing = CartItem::query()
            ->where('user_id', $userId)
            ->where('listing_id', $listingId)
            ->first();

        if ($existing !== null) {
            return ['item' => $existing, 'created' => false];
        }

        try {
            $item = CartItem::query()->create([
                'user_id' => $userId,
                'listing_id' => $listingId,
                'quantity' => 1,
            ]);

            return ['item' => $item, 'created' => true];
        } catch (UniqueConstraintViolationException) {
            $item = CartItem::query()
                ->where('user_id', $userId)
                ->where('listing_id', $listingId)
                ->firstOrFail();

            return ['item' => $item, 'created' => false];
        }
    }

    public function remove(int $userId, int $listingId): bool
    {
        return CartItem::query()
            ->where('user_id', $userId)
            ->where('listing_id', $listingId)
            ->delete() > 0;
    }

    public function clear(int $userId): int
    {
        return CartItem::query()->where('user_id', $userId)->delete();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function items(int $userId): array
    {
        $rows = CartItem::query()
            ->where('user_id', $userId)
            ->orderByDesc('cart_item_id')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $listings = Listing::query()
            ->whereKey($rows->pluck('listing_id')->all())
            ->get()
            ->keyBy('listing_id');

        return array_map(function (CartItem $row) use ($listings) {
            /** @var Listing|null $listing */
            $listing = $listings->get((int) $row->listing_id);
            $purchasable = $listing !== null && $listing->status->isPurchasable();

            return [
                'cart_item_id' => (int) $row->cart_item_id,
                'listing_id' => (int) $row->listing_id,
                'quantity' => (int) $row->quantity,
                // 商品下架/售出后购物车行保留，但前端必须按 purchasable 置灰
                'purchasable' => $purchasable,
                'listing' => $listing === null ? null : [
                    'title' => $listing->title,
                    'price' => (string) $listing->price,
                    'currency' => $listing->currency,
                    'status' => $listing->status->value,
                    'cover_url' => $listing->coverUrl(),
                ],
                'created_at' => $row->created_at?->toIso8601String(),
            ];
        }, $rows->all());
    }
}

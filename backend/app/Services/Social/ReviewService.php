<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Social;

use App\Enums\OrderStatus;
use App\Exceptions\SocialException;
use App\Models\Order;
use App\Models\Review;
use App\Models\SellerProfile;
use App\Support\MediaUrl;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 交易评价（T6）。
 *
 * 写入口径（比「想给就能给」更严，是为了让评价有凭证价值）：
 * 1. 只有订单买家能评，且订单必须已完成（completed）——未收货就评价等于没见到货；
 * 2. 一单一评，重复提交返回 409，不覆盖历史（避免刷分/改口）；
 * 3. 评分 1~5 整数，内容与图片都可选，但二者不能同时为空（否则是空评价）；
 * 4. 卖家只能回复一次，回复后不可改（对话留证）。
 *
 * 卖家信用分：SellerProfile.rating_bp 为「百分比基点」（5 星 = 10000），
 * 每次新增评价后重算，避免读写两侧各算一遍出现不一致。
 */
class ReviewService
{
    /**
     * @param  array{rating: int, content?: string|null, images?: array<int, string>}  $data
     */
    public function create(int $buyerId, int $orderId, array $data): Review
    {
        $order = Order::query()->with('items')->whereKey($orderId)->first();

        if ($order === null) {
            throw SocialException::notFound('订单不存在');
        }

        if ((int) $order->buyer_id !== $buyerId) {
            throw SocialException::forbidden('只有下单买家可以评价');
        }

        if ($order->status !== OrderStatus::Completed) {
            throw SocialException::invalid('订单完成后才能评价（当前：'.$order->status->label().'）');
        }

        // 重复提交先判：一单一评是身份问题，不该因为「这次没写内容」被降级成入参错误
        if (Review::query()->where('order_id', (int) $order->order_id)->exists()) {
            throw SocialException::conflict('该订单已评价，不能重复提交');
        }

        $content = trim((string) ($data['content'] ?? ''));
        $images = array_values(array_filter((array) ($data['images'] ?? []), static fn ($key) => is_string($key) && trim($key) !== ''));

        if ($content === '' && $images === []) {
            throw SocialException::invalid('评价内容与图片不能同时为空');
        }

        try {
            return DB::transaction(function () use ($order, $buyerId, $data, $content, $images) {
                $review = Review::query()->create([
                    'order_id' => (int) $order->order_id,
                    'listing_id' => $order->items->first()?->listing_id,
                    'buyer_id' => $buyerId,
                    'seller_id' => (int) $order->seller_id,
                    'rating' => (int) $data['rating'],
                    'content' => $content === '' ? null : mb_substr($content, 0, 500),
                    'images' => $images === [] ? null : array_slice($images, 0, 9),
                    'status' => 'published',
                ]);

                $this->refreshSellerRating((int) $order->seller_id);

                return $review;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw SocialException::conflict('该订单已评价，不能重复提交');
        }
    }

    /** 卖家回复：一评一回复，重复回复 409（改口会破坏对话凭证） */
    public function reply(int $sellerId, int $reviewId, string $content): Review
    {
        $content = trim($content);

        if ($content === '') {
            throw SocialException::invalid('回复内容不能为空');
        }

        return DB::transaction(function () use ($sellerId, $reviewId, $content) {
            /** @var Review $review */
            $review = Review::query()->whereKey($reviewId)->lockForUpdate()->first();

            if ($review === null) {
                throw SocialException::notFound('评价不存在');
            }

            if ((int) $review->seller_id !== $sellerId) {
                throw SocialException::forbidden('只能回复自己店铺的评价');
            }

            if ($review->isReplied()) {
                throw SocialException::conflict('该评价已回复，不支持修改');
            }

            $review->forceFill([
                'seller_reply' => mb_substr($content, 0, 500),
                'replied_at' => Carbon::now(),
            ])->save();

            return $review->refresh();
        }, 3);
    }

    public function forSeller(int $sellerId, int $perPage = 20): LengthAwarePaginator
    {
        return Review::query()
            ->where('seller_id', $sellerId)
            ->where('status', 'published')
            ->orderByDesc('review_id')
            ->paginate($perPage);
    }

    /**
     * 店铺评分概览：均分 + 条数 + 星级分布（前端画评分条）。
     *
     * @return array{average: float, count: int, distribution: array<int, int>}
     */
    public function summary(int $sellerId): array
    {
        $rows = Review::query()
            ->where('seller_id', $sellerId)
            ->where('status', 'published')
            ->selectRaw('rating, COUNT(*) AS total')
            ->groupBy('rating')
            ->pluck('total', 'rating');

        $count = 0;
        $sum = 0;
        $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];

        foreach ($rows as $rating => $total) {
            $star = (int) $rating;
            $total = (int) $total;
            $count += $total;
            $sum += $star * $total;
            $distribution[$star] = $total;
        }

        return [
            'average' => $count === 0 ? 0.0 : round($sum / $count, 2),
            'count' => $count,
            'distribution' => $distribution,
        ];
    }

    /** @return array<string, mixed> */
    public function payload(Review $review): array
    {
        return [
            'review_id' => $review->review_id,
            'order_id' => (int) $review->order_id,
            'listing_id' => $review->listing_id === null ? null : (int) $review->listing_id,
            'rating' => (int) $review->rating,
            'content' => $review->content,
            'images' => array_values(array_filter(array_map(
                static fn ($key) => MediaUrl::for($key),
                (array) ($review->images ?? [])
            ))),
            'seller_reply' => $review->seller_reply,
            'replied_at' => $review->replied_at?->toIso8601String(),
            'created_at' => $review->created_at?->toIso8601String(),
        ];
    }

    /** 信用分重算：只算 published，隐藏的评价不进分 */
    private function refreshSellerRating(int $sellerId): void
    {
        $summary = $this->summary($sellerId);

        SellerProfile::query()->where('user_id', $sellerId)->update([
            // 5 星制 → 百分比基点：4.8 分 = 9600
            'rating_bp' => $summary['count'] === 0
                ? 0
                : (int) round($summary['average'] / 5 * 10000),
            'updated_at' => Carbon::now(),
        ]);
    }
}

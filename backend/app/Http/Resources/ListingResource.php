<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Resources;

use App\Models\Listing;

/**
 * 商品出参（公开浏览 / 卖家后台 / 审核队列共用）。
 *
 * 不继承 JsonResource：公开列表与后台详情字段一致但调用方式不同，
 * 静态方法能保证三处出参永远同源，不会出现「列表有价格、详情没价格」这类分裂。
 */
final class ListingResource
{
    /** @return array<string, mixed> */
    public static function make(Listing $listing, bool $detail = false): array
    {
        $data = [
            'listing_id' => $listing->listing_id,
            'title' => $listing->title,
            'price' => (string) $listing->price,
            'currency' => $listing->currency,
            'condition' => $listing->condition_enum,
            'status' => $listing->status->value,
            'status_label' => $listing->status->label(),
            'stock' => $listing->stock,
            'cover_url' => $listing->coverUrl(),
            'figure_id' => $listing->figure_id,
            'seller_id' => $listing->seller_id,
            'published_at' => $listing->published_at?->toIso8601String(),
        ];

        if ($detail) {
            $data['description'] = $listing->description;
            $data['gallery_urls'] = $listing->galleryUrls();
            $data['figure'] = $listing->figure?->only(['figure_id', 'name', 'series', 'maker', 'scale']);
            // 审核与卖家自查需要：驳回原因写在这里，前端不必再查审核流水
            $data['reject_reason'] = ($listing->meta ?? [])['reject_reason'] ?? null;
        }

        return $data;
    }

    /**
     * @param  iterable<int, Listing>  $listings
     * @return list<array<string, mixed>>
     */
    public static function collection(iterable $listings, bool $detail = false): array
    {
        $rows = [];

        foreach ($listings as $listing) {
            $rows[] = self::make($listing, $detail);
        }

        return $rows;
    }
}

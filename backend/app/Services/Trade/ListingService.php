<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Trade;

use App\Enums\FigureStatus;
use App\Enums\ListingStatus;
use App\Exceptions\TradeException;
use App\Models\Figure;
use App\Models\Listing;
use App\Models\User;
use App\Support\MediaUrl;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * 商品上架 / 编辑 / 审核 / 下架（T4 写侧）。
 *
 * 上架闸门（缺一不可）：店铺 active + 实名 approved + 图鑑条目已发布 + 价格与库存合法。
 * 状态流：draft ──submit──▶ pending_review ──approve──▶ active ──delist──▶ delisted
 *                                     └──reject──▶ rejected ──修改──▶ pending_review
 * 卖家不能自己把商品置为 active：绕过审核会让违规品直接面向买家（契约 §2.2）。
 */
class ListingService
{
    /** 允许卖家自由编辑的状态：已上架商品只允许改价/改库存/改描述，不能改图鑑绑定 */
    private const EDITABLE_FULL = [ListingStatus::Draft, ListingStatus::Rejected];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $seller, array $data): Listing
    {
        $profile = $seller->sellerProfile;

        if ($profile === null || ! $profile->canList()) {
            throw new TradeException('开店并通过实名核身后才能上架商品');
        }

        $figure = $this->resolveFigure($data['figure_id'] ?? null);
        $price = $this->normalizeAmount($data['price'] ?? null, '售价');

        $listing = Listing::create([
            'seller_id' => $seller->user_id,
            'figure_id' => $figure?->figure_id,
            'title' => trim((string) $data['title']),
            'description' => $data['description'] ?? null,
            'condition_enum' => (string) ($data['condition'] ?? 'used_good'),
            'price' => Money::fromCents($price),
            'stock' => max(1, (int) ($data['stock'] ?? 1)),
            'cover_key' => $this->toMediaKey($data['cover_url'] ?? $data['cover_key'] ?? null),
            'gallery_keys' => $this->toMediaKeys((array) ($data['gallery_urls'] ?? $data['gallery_keys'] ?? [])),
            'status' => ListingStatus::Draft,
            // 品类落快照：费率规则按品类定价，商品后续改挂图鑑也不该改变已成交订单的费率口径
            'meta' => ['category' => $figure?->category->value],
        ]);

        return $listing->load('figure');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $seller, Listing $listing, array $data): Listing
    {
        $this->assertOwner($seller, $listing);

        if (! in_array($listing->status, self::EDITABLE_FULL, true) && $listing->status !== ListingStatus::Active) {
            throw new TradeException('当前状态的商品不可编辑（已锁定/已售出/已下架）');
        }

        $isActive = $listing->status === ListingStatus::Active;
        $payload = [];

        // 已上架商品：只允许影响「卖什么价、还有几件」的字段，标题/图鑑/品相改动需先下架
        $allowed = $isActive
            ? ['price', 'stock', 'description']
            : ['title', 'description', 'condition_enum', 'price', 'stock', 'figure_id'];

        if (array_key_exists('title', $data) && in_array('title', $allowed, true)) {
            $payload['title'] = trim((string) $data['title']);
        }

        if (array_key_exists('description', $data)) {
            $payload['description'] = $data['description'];
        }

        if (array_key_exists('condition', $data)) {
            $payload['condition_enum'] = (string) $data['condition'];
        }

        if (array_key_exists('price', $data)) {
            $payload['price'] = Money::fromCents($this->normalizeAmount($data['price'], '售价'));
        }

        if (array_key_exists('stock', $data)) {
            $payload['stock'] = max(0, (int) $data['stock']);
        }

        if (! $isActive && array_key_exists('figure_id', $data)) {
            $figure = $this->resolveFigure($data['figure_id']);
            $payload['figure_id'] = $figure?->figure_id;
            $payload['meta'] = ['category' => $figure?->category->value];
        }

        if (! $isActive && (array_key_exists('cover_url', $data) || array_key_exists('cover_key', $data))) {
            $payload['cover_key'] = $this->toMediaKey($data['cover_url'] ?? $data['cover_key'] ?? null);
        }

        if (! $isActive && (array_key_exists('gallery_urls', $data) || array_key_exists('gallery_keys', $data))) {
            $payload['gallery_keys'] = $this->toMediaKeys((array) ($data['gallery_urls'] ?? $data['gallery_keys'] ?? []));
        }

        $listing->fill($payload)->save();

        return $listing->refresh()->load('figure');
    }

    /**
     * 提交审核：必须有封面（无图商品在列表页无法展示，直接挡在审核前）。
     *
     * 状态机里 rejected/delisted 不能直达 pending_review，必须经 draft 中转，
     * 这里按路径逐步 assert 而不是直接赋值，保证任何一次流转都受状态机校验。
     */
    public function submit(User $seller, Listing $listing): Listing
    {
        $this->assertOwner($seller, $listing);

        if ($listing->cover_key === null) {
            throw new TradeException('请先上传商品封面图再提交审核');
        }

        $path = match ($listing->status) {
            ListingStatus::Draft => [ListingStatus::PendingReview],
            ListingStatus::Rejected, ListingStatus::Delisted => [ListingStatus::Draft, ListingStatus::PendingReview],
            default => throw new TradeException('当前状态不能提交审核'),
        };

        return DB::transaction(function () use ($listing, $path) {
            $status = $listing->status;

            foreach ($path as $step) {
                $status = $status->assertTransitionTo($step);
            }

            $listing->status = $status;
            $listing->save();

            return $listing->refresh();
        });
    }

    /** 卖家下架：active → delisted（有未完成订单时不允许，避免订单履约时商品消失） */
    public function delist(User $seller, Listing $listing): Listing
    {
        $this->assertOwner($seller, $listing);

        if ($listing->status === ListingStatus::Reserved) {
            throw new TradeException('商品已被买家锁定，请等待订单关闭或完成后操作');
        }

        return $this->transit($listing, ListingStatus::Delisted);
    }

    /** 审核：通过 → active（补 published_at）；驳回 → rejected（带原因，卖家可改后重提） */
    public function review(Listing $listing, bool $approve, ?string $reason = null): Listing
    {
        if ($listing->status !== ListingStatus::PendingReview) {
            throw new TradeException('只有待审核商品可以审核');
        }

        $listing->status = $listing->status->assertTransitionTo(
            $approve ? ListingStatus::Active : ListingStatus::Rejected
        );

        if ($approve) {
            $listing->published_at ??= now();
        } else {
            $listing->meta = array_merge((array) $listing->meta, [
                'reject_reason' => mb_substr((string) ($reason ?? '不符合上架规范'), 0, 255),
            ]);
        }

        $listing->save();

        return $listing->refresh();
    }

    /** 下架商品退回草稿（delisted → draft），改完可重新提交审核 */
    public function reopen(User $seller, Listing $listing): Listing
    {
        $this->assertOwner($seller, $listing);

        $listing->status = $listing->status->assertTransitionTo(ListingStatus::Draft);
        $listing->save();

        return $listing->refresh();
    }

    private function transit(Listing $listing, ListingStatus $to): Listing
    {
        return DB::transaction(function () use ($listing, $to) {
            $listing->status = $listing->status->assertTransitionTo($to);
            $listing->save();

            return $listing->refresh();
        });
    }

    private function assertOwner(User $seller, Listing $listing): void
    {
        if ((int) $listing->seller_id !== (int) $seller->user_id) {
            throw new TradeException('只能操作自己发布的商品');
        }
    }

    /** 上架绑定的图鑑条目必须已发布：草稿条目的图/名随时会变，不能拿来卖货 */
    private function resolveFigure(mixed $figureId): ?Figure
    {
        if ($figureId === null || $figureId === '' || (int) $figureId === 0) {
            return null;
        }

        $figure = Figure::query()->whereKey((int) $figureId)->first();

        if ($figure === null) {
            throw new TradeException('图鑑条目不存在');
        }

        if ($figure->status !== FigureStatus::Published) {
            throw new TradeException('该图鑑条目尚未发布，不能绑定上架');
        }

        return $figure;
    }

    private function normalizeAmount(mixed $amount, string $label): int
    {
        if ($amount === null || $amount === '') {
            throw new TradeException("{$label}必填");
        }

        try {
            $cents = Money::toCents($amount);
        } catch (\Throwable) {
            throw new TradeException("{$label}格式非法（最多两位小数）");
        }

        if ($cents <= 0) {
            throw new TradeException("{$label}必须大于 0");
        }

        return $cents;
    }

    private function toMediaKey(mixed $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        $key = MediaUrl::keyOf((string) $value);

        if ($key === null) {
            throw new TradeException('图片必须是本站上传后的对象 key（不接受外站 URL）');
        }

        return $key;
    }

    /** @return array<int, string>|null */
    private function toMediaKeys(array $values): ?array
    {
        $keys = array_values(array_filter(array_map(fn ($value) => $this->toMediaKey($value), $values)));

        return $keys === [] ? null : $keys;
    }
}

<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Trade;

use App\Enums\AuctionStatus;
use App\Enums\ListingStatus;
use App\Exceptions\TradeException;
use App\Models\Auction;
use App\Models\Listing;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 竞拍场次创建 / 取消（T4 写侧；出价与结算在 BiddingService）。
 *
 * 分工原因：竞价的价格竞态只允许存在一处实现（BiddingService 的行锁 + 幂等键），
 * 这里只负责「场次配置是否正确」，不碰出价逻辑，避免两套规则打架。
 *
 * 创建约束：
 *  - 商品必须处于 active（草稿/锁定/已售商品不能开拍）；
 *  - 同一商品不允许同时存在两个未结束场次（重复开拍会让买家拍到同一件货）；
 *  - 防狙击窗口必须短于拍卖时长，否则一开拍就永久触发顺延。
 */
class AuctionService
{
    /** 未结束状态：这些状态下商品不能再次开拍 */
    private const OPEN_STATUSES = [
        AuctionStatus::Draft,
        AuctionStatus::Scheduled,
        AuctionStatus::Active,
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $seller, Listing $listing, array $data): Auction
    {
        if ((int) $listing->seller_id !== (int) $seller->user_id) {
            throw new TradeException('只能对自己的商品开拍');
        }

        if ($listing->status !== ListingStatus::Active) {
            throw new TradeException('只有「在售」状态的商品可以开拍');
        }

        $hasOpen = Auction::query()
            ->where('listing_id', $listing->listing_id)
            ->whereIn('status', array_map(static fn (AuctionStatus $s) => $s->value, self::OPEN_STATUSES))
            ->exists();

        if ($hasOpen) {
            throw new TradeException('该商品已有未结束的竞拍场次');
        }

        $startAt = Carbon::parse((string) $data['start_at']);
        $endAt = Carbon::parse((string) $data['end_at']);

        if ($endAt->lessThanOrEqualTo($startAt)) {
            throw new TradeException('结束时间必须晚于开始时间');
        }

        $startPrice = $this->amount($data['start_price'] ?? null, '起拍价');
        $reservePrice = $this->amount($data['reserve_price'] ?? null, '保留价', true);
        $minIncrement = $this->amount($data['min_increment'] ?? '1.00', '最小加价幅度');
        $deposit = $this->amount($data['deposit_amount'] ?? '0', '保证金', true);

        if ($reservePrice !== null && $reservePrice < $startPrice) {
            throw new TradeException('保留价不能低于起拍价');
        }

        if ($minIncrement <= 0) {
            throw new TradeException('最小加价幅度必须大于 0');
        }

        // 防狙击参数：默认取业务配置（60s 窗口 / 60s 顺延 / 最多 10 次）
        $antiSnipe = (int) ($data['anti_snipe_seconds'] ?? config('goat.trade.auction.anti_snipe_seconds', 60));
        $extension = (int) ($data['extension_seconds'] ?? config('goat.trade.auction.extension_seconds', 60));
        $maxExtensions = (int) ($data['max_extensions'] ?? config('goat.trade.auction.max_extensions', 10));

        if ($antiSnipe < 0 || $extension < 0 || $maxExtensions < 0) {
            throw new TradeException('防狙击参数不能为负数');
        }

        if ($antiSnipe >= $startAt->diffInSeconds($endAt)) {
            throw new TradeException('防狙击窗口必须短于拍卖总时长（否则一开始就处于顺延窗口）');
        }

        return Auction::create([
            'listing_id' => $listing->listing_id,
            'seller_id' => $seller->user_id,
            'figure_id' => $listing->figure_id,
            // 到点即开拍：start_at 已过就直接 active，否则 scheduled（由定时任务/首个出价推进）
            'status' => $startAt->isFuture() ? AuctionStatus::Scheduled : AuctionStatus::Active,
            'start_price' => Money::fromCents($startPrice),
            'reserve_price' => $reservePrice === null ? null : Money::fromCents($reservePrice),
            'min_increment' => Money::fromCents($minIncrement),
            'current_price' => Money::fromCents($startPrice),
            'deposit_amount' => Money::fromCents($deposit),
            'anti_snipe_seconds' => $antiSnipe,
            'extension_seconds' => $extension,
            'max_extensions' => $maxExtensions,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'original_end_at' => $endAt,
        ]);
    }

    /**
     * 卖家撤销场次：已有出价则不允许（买家已产生合理预期，撤拍属于失信行为）。
     */
    public function cancel(User $seller, Auction $auction, ?string $reason = null): Auction
    {
        if ((int) $auction->seller_id !== (int) $seller->user_id) {
            throw new TradeException('只能撤销自己的竞拍场次');
        }

        if (! in_array($auction->status, self::OPEN_STATUSES, true)) {
            throw new TradeException('已结束的场次不能撤销');
        }

        if ($auction->bid_count > 0) {
            throw new TradeException('已有买家出价，不能撤销场次');
        }

        return DB::transaction(function () use ($auction, $reason) {
            $auction->status = AuctionStatus::Cancelled;
            $auction->save();

            if ($reason !== null && $reason !== '') {
                // 撤销原因落审计日志，便于客服举证（不改表结构）
                Log::channel('goat.trade')->info('auction.cancelled', [
                    'auction_id' => $auction->auction_id,
                    'seller_id' => $auction->seller_id,
                    'reason' => mb_substr($reason, 0, 255),
                ]);
            }

            return $auction->refresh();
        });
    }

    private function amount(mixed $value, string $label, bool $nullable = false): ?int
    {
        if ($value === null || $value === '') {
            if ($nullable) {
                return null;
            }

            throw new TradeException("{$label}必填");
        }

        try {
            $cents = Money::toCents($value);
        } catch (\Throwable) {
            throw new TradeException("{$label}格式非法（最多两位小数）");
        }

        if ($cents < 0) {
            throw new TradeException("{$label}不能为负数");
        }

        return $cents;
    }
}

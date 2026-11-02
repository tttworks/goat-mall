<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Trade;

use App\Enums\AuctionStatus;
use App\Exceptions\TradeException;
use App\Models\Auction;
use App\Models\Bid;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 竞拍出价（T4 · 防狙击 + 竞态）。
 *
 * 并发唯一正确姿势：整个「读当前价 → 校验 → 写新价 → 可能顺延」必须在
 * 同一事务内持 auctions 行锁完成，否则两人同时出价会出现同价/丢更新。
 *
 * 防狙击（anti-snipe）：结束前 anti_snipe_seconds 内出价 → end_at += extension_seconds，
 * 顺延次数上限 max_extensions，防止无限延长造成竞价机器套利。
 */
class BiddingService
{
    /**
     * @return array{bid: Bid, auction: Auction, replayed: bool, extended: bool}
     */
    public function placeBid(int $auctionId, int $bidderId, string $amount, string $idempotencyKey): array
    {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 64) {
            throw new TradeException('幂等键必填且长度 ≤ 64');
        }

        if ($amount !== '' && preg_match('/^\d+(\.\d{1,2})?$/', $amount) !== 1) {
            throw new TradeException('出价金额格式非法（最多两位小数）');
        }

        $amountCents = Money::toCents($amount);

        return DB::transaction(function () use ($auctionId, $bidderId, $amountCents, $idempotencyKey) {
            /** @var Auction $auction */
            $auction = Auction::query()->whereKey($auctionId)->lockForUpdate()->firstOrFail();
            $now = Carbon::now();

            // 幂等：同一客户端重试直接回放结果，不重复抬价
            $existing = Bid::query()
                ->where('auction_id', $auction->auction_id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return [
                    'bid' => $existing,
                    'auction' => $auction,
                    'replayed' => true,
                    'extended' => (bool) $existing->extended,
                ];
            }

            $this->guardBiddable($auction, $bidderId, $now);

            $minCents = Money::toCents($auction->nextMinBid());
            if ($amountCents < $minCents) {
                throw new TradeException('出价低于最低有效出价 '.Money::fromCents($minCents));
            }

            // 上一口领先价降级为被超
            Bid::query()
                ->where('auction_id', $auction->auction_id)
                ->where('status', 'leading')
                ->update(['status' => 'outbid', 'updated_at' => $now]);

            $extended = false;
            $newEndAt = $auction->end_at?->copy();

            if ($auction->withinAntiSnipeWindow($now) && $auction->canExtend()) {
                $newEndAt = $newEndAt?->addSeconds($auction->extension_seconds);
                $auction->extension_count++;
                $extended = true;
            }

            $bid = Bid::create([
                'auction_id' => $auction->auction_id,
                'bidder_id' => $bidderId,
                'amount' => Money::fromCents($amountCents),
                'status' => 'leading',
                'idempotency_key' => $idempotencyKey,
                'extended' => $extended,
                'placed_at' => $now,
            ]);

            $auction->current_price = Money::fromCents($amountCents);
            $auction->bid_count = (int) $auction->bid_count + 1;
            $auction->winner_bidder_id = $bidderId;
            $auction->winning_bid_id = $bid->bid_id;
            if ($extended) {
                $auction->end_at = $newEndAt;
            }
            $auction->save();

            return ['bid' => $bid, 'auction' => $auction->fresh(), 'replayed' => false, 'extended' => $extended];
        }, 3);
    }

    /**
     * 结束并结算竞拍：有出价且达到保留价 → 成交；否则流拍。
     * 成交只置状态与赢家，后续生成订单由调用方（结算 job）用 OrderService 走担保交易。
     */
    public function finalize(Auction $auction): Auction
    {
        return DB::transaction(function () use ($auction) {
            /** @var Auction $locked */
            $locked = Auction::query()->whereKey($auction->auction_id)->lockForUpdate()->firstOrFail();
            $now = Carbon::now();

            if ($locked->status === AuctionStatus::Active) {
                $locked->transitTo(AuctionStatus::Ended, ['settled_at' => $now]);
            }

            if ($locked->status !== AuctionStatus::Ended) {
                return $locked;
            }

            $winning = $locked->winning_bid_id
                ? Bid::query()->whereKey($locked->winning_bid_id)->first()
                : null;

            $reserveCents = $locked->reserve_price === null ? null : Money::toCents((string) $locked->reserve_price);
            $reached = $winning !== null
                && ($reserveCents === null || Money::toCents((string) $winning->amount) >= $reserveCents);

            if (! $reached) {
                if ($winning) {
                    $winning->update(['status' => 'lost']);
                }

                $locked->transitTo(AuctionStatus::Failed, ['settled_at' => $now]);

                return $locked;
            }

            $winning->update(['status' => 'won']);
            $locked->transitTo(AuctionStatus::Settled, ['settled_at' => $now]);

            return $locked;
        }, 3);
    }

    private function guardBiddable(Auction $auction, int $bidderId, Carbon $now): void
    {
        if (! $auction->status->acceptsBids()) {
            throw new TradeException('竞拍不在竞价中（'.$auction->status->label().'）');
        }

        if ($now->lt($auction->start_at)) {
            throw new TradeException('竞拍尚未开始');
        }

        if ($auction->end_at === null || $now->gte($auction->end_at)) {
            throw new TradeException('竞拍已结束');
        }

        if ((int) $auction->seller_id === $bidderId) {
            throw new TradeException('卖家不得对自己的拍品出价');
        }
    }
}

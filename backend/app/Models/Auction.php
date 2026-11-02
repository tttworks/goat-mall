<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Models;

use App\Enums\AuctionStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * 竞拍（契约 §2.2 auctions，含 anti-snipe 字段）。owner：T4。
 *
 * 防狙击规则（唯一实现点：BiddingService）：
 *   剩余时间 ≤ anti_snipe_seconds 时有人出价 → end_at += extension_seconds，
 *   最多顺延 max_extensions 次；顺延必须同时锁行更新，否则并发下会丢更新。
 */
class Auction extends Model
{
    use SoftDeletes;

    protected $table = 'auctions';

    protected $primaryKey = 'auction_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => AuctionStatus::class,
            'start_price' => 'decimal:2',
            'reserve_price' => 'decimal:2',
            'min_increment' => 'decimal:2',
            'current_price' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            'bid_count' => 'integer',
            'anti_snipe_seconds' => 'integer',
            'extension_seconds' => 'integer',
            'max_extensions' => 'integer',
            'extension_count' => 'integer',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'original_end_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class, 'listing_id', 'listing_id');
    }

    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class, 'auction_id', 'auction_id');
    }

    public function winningBid(): BelongsTo
    {
        return $this->belongsTo(Bid::class, 'winning_bid_id', 'bid_id');
    }

    /** 是否已进入防狙击窗口（此时出价会顺延） */
    public function withinAntiSnipeWindow(?Carbon $now = null): bool
    {
        $now = $now ?? Carbon::now();

        return $this->end_at !== null
            && $now->lt($this->end_at)
            && $now->copy()->addSeconds($this->anti_snipe_seconds)->gte($this->end_at);
    }

    public function canExtend(): bool
    {
        return $this->extension_count < $this->max_extensions;
    }

    /** 下一口最低有效出价 = 当前价 + 最小加价幅度（无出价时为首价） */
    public function nextMinBid(): string
    {
        if ($this->bid_count === 0) {
            return (string) $this->start_price;
        }

        return Money::fromCents(
            Money::toCents((string) $this->current_price) + Money::toCents((string) $this->min_increment)
        );
    }

    public function transitTo(AuctionStatus $to, array $attributes = []): self
    {
        $this->status = $this->status->assertTransitionTo($to);
        $this->fill($attributes)->save();

        return $this;
    }
}

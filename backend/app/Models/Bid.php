<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 出价记录（契约 §2.2 bids）。owner：T4。
 * 幂等：uniq(auction_id, idempotency_key) —— 同一客户端重试不会重复抬价。
 */
class Bid extends Model
{
    protected $table = 'bids';

    protected $primaryKey = 'bid_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'extended' => 'boolean',
            'placed_at' => 'datetime',
        ];
    }

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class, 'auction_id', 'auction_id');
    }

    public function bidder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bidder_id', 'user_id');
    }
}

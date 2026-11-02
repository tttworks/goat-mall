<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Events;

use App\Models\Auction;
use App\Models\Bid;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 竞拍出价成功（T4 实时化）。
 *
 * 走**公开**频道（`goat.auction.{id}`）：看价不需要登录，出价才需要。
 * 隐私口径：广播里只给「出价人脱敏名 + 金额 + 是否顺延」，绝不给 bidder_id
 * ——公开频道任何人可订阅，带 id 等于把买家的竞价行为公开。
 */
class BidPlaced implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Auction $auction,
        public readonly Bid $bid,
        public readonly string $bidderMask,
        public readonly bool $extended,
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new Channel('goat.auction.'.(int) $this->auction->auction_id)];
    }

    public function broadcastAs(): string
    {
        return 'auction.bid';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'auction_id' => (int) $this->auction->auction_id,
            'current_price' => (string) $this->auction->current_price,
            'bid_count' => (int) $this->auction->bid_count,
            'end_at' => $this->auction->end_at?->toIso8601String(),
            'extended' => $this->extended,
            'bidder_mask' => $this->bidderMask,
            'next_min_bid' => (string) $this->auction->nextMinBid(),
        ];
    }
}

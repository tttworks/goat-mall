<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Enums;

use App\Support\Concerns\StateTransitions;

/**
 * 竞拍状态机（T4）。字面量与 auctions 表 CHECK 约束一致。
 *
 * draft ─→ scheduled ─→ active ─→ ended ─→ settled（有保留价且达成）
 *   │           │            │        └──→ failed（未达保留价/无人出价）
 *   └───────────┴────────────┴──→ cancelled
 */
enum AuctionStatus: string
{
    use StateTransitions;

    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Active = 'active';
    case Ended = 'ended';
    case Settled = 'settled';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function transitions(): array
    {
        return [
            self::Draft->value => [self::Scheduled->value, self::Cancelled->value],
            self::Scheduled->value => [self::Active->value, self::Cancelled->value],
            self::Active->value => [self::Ended->value, self::Cancelled->value],
            self::Ended->value => [self::Settled->value, self::Failed->value],
            self::Settled->value => [],
            self::Failed->value => [],
            self::Cancelled->value => [],
        ];
    }

    /** 是否接受出价：仅 active，且未到结束时间（时间判定在 BiddingService 事务内做） */
    public function acceptsBids(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => '草稿',
            self::Scheduled => '待开拍',
            self::Active => '竞价中',
            self::Ended => '已结束（待结算）',
            self::Settled => '已成交',
            self::Failed => '流拍',
            self::Cancelled => '已取消',
        };
    }
}

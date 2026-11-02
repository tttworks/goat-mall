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
 * 商品状态机（T4）。字面量与 listings 表 CHECK 约束一一对应。
 *
 * draft ──→ pending_review ──→ active ──→ reserved ──→ sold
 *                                │            │
 *                                └→ delisted ←┴→ active（锁库释放）
 * rejected ←── pending_review（驳回后回 draft 改稿）
 */
enum ListingStatus: string
{
    use StateTransitions;

    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Rejected = 'rejected';
    case Active = 'active';
    case Reserved = 'reserved';
    case Sold = 'sold';
    case Delisted = 'delisted';

    public function transitions(): array
    {
        return [
            self::Draft->value => [self::PendingReview->value, self::Delisted->value],
            self::PendingReview->value => [self::Active->value, self::Rejected->value],
            self::Rejected->value => [self::Draft->value, self::Delisted->value],
            self::Active->value => [self::Reserved->value, self::Sold->value, self::Delisted->value],
            self::Reserved->value => [self::Sold->value, self::Active->value],
            self::Sold->value => [],
            self::Delisted->value => [self::Draft->value],
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => '草稿',
            self::PendingReview => '待审',
            self::Rejected => '已驳回',
            self::Active => '在售',
            self::Reserved => '已锁定',
            self::Sold => '已售出',
            self::Delisted => '已下架',
        };
    }

    /** 是否可被买家下单（担保交易前置条件） */
    public function isPurchasable(): bool
    {
        return $this === self::Active;
    }
}

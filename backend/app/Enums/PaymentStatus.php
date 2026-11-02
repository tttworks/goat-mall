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
 * 支付状态机字面量（T4 只固化方向；收付通二级商户/冻结/分账的**流转实现归 T5**）。
 *
 * pending ─→ paid ─→ frozen ─→ splitting ─→ settled
 *    │         │        │           │
 *    └→ failed └────────┴───────────┴→ refunded / failed
 */
enum PaymentStatus: string
{
    use StateTransitions;

    case Pending = 'pending';
    case Paid = 'paid';
    case Frozen = 'frozen';
    case Splitting = 'splitting';
    case Settled = 'settled';
    case Refunded = 'refunded';
    case Failed = 'failed';

    public function transitions(): array
    {
        return [
            self::Pending->value => [self::Paid->value, self::Failed->value],
            self::Paid->value => [self::Frozen->value, self::Refunded->value, self::Failed->value],
            self::Frozen->value => [self::Splitting->value, self::Refunded->value, self::Failed->value],
            self::Splitting->value => [self::Settled->value, self::Failed->value],
            self::Settled->value => [self::Refunded->value],
            self::Refunded->value => [],
            self::Failed->value => [],
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => '待支付',
            self::Paid => '已支付',
            self::Frozen => '资金冻结',
            self::Splitting => '分账中',
            self::Settled => '已结算',
            self::Refunded => '已退款',
            self::Failed => '支付失败',
        };
    }
}

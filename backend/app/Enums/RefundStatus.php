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
 * 退款单状态机（T4 · 售后）。
 *
 * pending ─→ refunded（渠道退款成功）
 *    ├────→ rejected（管理员驳回）
 *    └────→ failed（渠道退款失败，可再次发起）
 *
 * 与 PaymentStatus 的关系：退款单是「申请/审核」凭据，资金动作仍由
 * PaymentService::refund 执行（退回支付单状态机），两者不互相内嵌。
 */
enum RefundStatus: string
{
    use StateTransitions;

    case Pending = 'pending';
    case Refunded = 'refunded';
    case Rejected = 'rejected';
    case Failed = 'failed';

    public function transitions(): array
    {
        return [
            self::Pending->value => [self::Refunded->value, self::Rejected->value, self::Failed->value],
            // 渠道失败后允许重新发起（复用同一条退款单，不新开一条）
            self::Failed->value => [self::Refunded->value, self::Rejected->value],
            self::Refunded->value => [],
            self::Rejected->value => [],
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => '待审核',
            self::Refunded => '已退款',
            self::Rejected => '已驳回',
            self::Failed => '退款失败',
        };
    }

    /** 是否仍在处理中（同一订单不允许并存两条） */
    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Failed], true);
    }
}

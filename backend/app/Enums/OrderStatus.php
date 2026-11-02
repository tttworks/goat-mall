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
 * 订单状态机（T4 · 担保交易）。字面量与 orders 表 CHECK 约束一致。
 *
 * pending_payment ─→ paid ─→ seller_shipped ─→ buyer_confirmed ─→ completed
 *        │             │            │                  │
 *        └→ cancelled  └────────────┴──────────────────┴→ refunding ─→ refunded / closed
 *
 * 资金口径：paid 后款项冻结（escrow=frozen），completed 才释放给卖家（released）。
 * 语言/结算语义待 GLM 5.3 复核（契约 §6 T4 归 GLM 主导），此处仅固化状态方向。
 */
enum OrderStatus: string
{
    use StateTransitions;

    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case SellerShipped = 'seller_shipped';
    case BuyerConfirmed = 'buyer_confirmed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Refunding = 'refunding';
    case Refunded = 'refunded';
    case Closed = 'closed';

    public function transitions(): array
    {
        return [
            self::PendingPayment->value => [self::Paid->value, self::Cancelled->value],
            self::Paid->value => [self::SellerShipped->value, self::Refunding->value, self::Closed->value],
            self::SellerShipped->value => [self::BuyerConfirmed->value, self::Refunding->value],
            self::BuyerConfirmed->value => [self::Completed->value, self::Refunding->value],
            self::Completed->value => [],
            self::Cancelled->value => [],
            self::Refunding->value => [self::Refunded->value, self::Closed->value],
            self::Refunded->value => [],
            self::Closed->value => [],
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => '待付款',
            self::Paid => '已付款（资金冻结）',
            self::SellerShipped => '卖家已发货',
            self::BuyerConfirmed => '买家已确认收货',
            self::Completed => '已完成',
            self::Cancelled => '已取消',
            self::Refunding => '退款中',
            self::Refunded => '已退款',
            self::Closed => '已关闭',
        };
    }

    /** 是否占用资金（担保冻结中，禁止重复下单/重复退款） */
    public function holdsFunds(): bool
    {
        return in_array($this, [self::Paid, self::SellerShipped, self::BuyerConfirmed], true);
    }
}

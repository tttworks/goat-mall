<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Enums;

/**
 * 店铺状态（T2）。
 * draft(未提交) → pending(待审/待实名) → active(可上架) → suspended(封禁)。
 * 只允许这条主链路；非法跃迁由 SellerOnboardingService 集中拦截。
 */
enum SellerStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Draft => '未提交',
            self::Pending => '待审核',
            self::Active => '正常营业',
            self::Suspended => '已封禁',
        };
    }

    public function canList(): bool
    {
        return $this === self::Active;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

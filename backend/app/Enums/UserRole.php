<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Enums;

/**
 * 用户角色（T2）。
 * 单一角色模型：注册默认 buyer，开店审核通过升为 seller，admin 只能由后台/运维授予。
 */
enum UserRole: string
{
    case Buyer = 'buyer';
    case Seller = 'seller';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Buyer => '买家',
            self::Seller => '卖家',
            self::Admin => '管理员',
        };
    }

    /** 是否具备上架/接单能力（T4 上架接口按此校验） */
    public function canSell(): bool
    {
        return $this === self::Seller || $this === self::Admin;
    }

    public function canModerate(): bool
    {
        return $this === self::Admin;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

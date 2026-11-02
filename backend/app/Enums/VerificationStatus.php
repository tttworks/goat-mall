<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Enums;

/**
 * 实名核身状态（T2）。none = 从未提交（店铺档案的 identity_status 复用本枚举值）。
 */
enum VerificationStatus: string
{
    case None = 'none';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::None => '未实名',
            self::Pending => '核身中',
            self::Approved => '已实名',
            self::Rejected => '核身失败',
        };
    }

    public function isApproved(): bool
    {
        return $this === self::Approved;
    }

    /** 是否允许（重新）提交：仅在未提交或上次失败时 */
    public function canSubmit(): bool
    {
        return $this === self::None || $this === self::Rejected;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Identity;

use App\Enums\VerificationStatus;

/**
 * 核身返回值对象（T2）。
 *
 * 为什么用独立值对象而不是裸数组：核身是**按次付费**的外部调用，
 * 结果必须完整携带「状态 + 服务商流水号 + 失败原因」三要素，对账与客诉都靠它。
 */
final class IdentityCheckResult
{
    public function __construct(
        public readonly VerificationStatus $status,
        public readonly ?string $channelRef = null,
        public readonly ?string $reason = null,
    ) {}

    public static function approved(?string $ref = null): self
    {
        return new self(VerificationStatus::Approved, $ref);
    }

    public static function rejected(string $reason, ?string $ref = null): self
    {
        return new self(VerificationStatus::Rejected, $ref, $reason);
    }

    public static function pending(?string $ref = null, ?string $reason = null): self
    {
        return new self(VerificationStatus::Pending, $ref, $reason);
    }
}

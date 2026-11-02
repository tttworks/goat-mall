<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Moderation\Contracts;

use App\Services\Moderation\ModerationResult;

/**
 * UGC 机审通道契约（T6）。
 *
 * 与支付通道同一套思路：业务层只依赖契约，换服务商（自建词表 → 云厂商内容安全）
 * 只改 GOAT_MODERATION_DRIVER 与驱动实现，PostService 不改。
 */
interface ModerationChannel
{
    public function name(): string;

    /**
     * @param  array<int, string>  $imageKeys
     */
    public function screen(string $content, array $imageKeys = []): ModerationResult;
}

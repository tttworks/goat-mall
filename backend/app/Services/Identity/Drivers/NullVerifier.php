<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Identity\Drivers;

use App\Services\Identity\Contracts\IdentityVerifier;
use App\Services\Identity\IdentityCheckResult;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * 空驱动（T2）：本地开发/演示用，直接放行。
 *
 * 生产禁用：若生产环境误配 driver=null，这里**主动抛错**而不是悄悄放行，
 * 避免「演示配置上线 = 全员免实名」的合规事故。
 */
class NullVerifier implements IdentityVerifier
{
    public function name(): string
    {
        return 'null';
    }

    public function verify(string $realName, string $idNumber): IdentityCheckResult
    {
        if (app()->isProduction()) {
            throw new RuntimeException('实名核身驱动为 null，生产环境禁止使用。请配置 GOAT_IDENTITY_DRIVER=http 与服务商凭据。');
        }

        return IdentityCheckResult::approved('null-'.Str::uuid()->toString());
    }
}

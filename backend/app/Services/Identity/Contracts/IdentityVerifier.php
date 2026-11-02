<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Identity\Contracts;

use App\Services\Identity\IdentityCheckResult;

/**
 * 实名核身驱动契约（T2）。
 *
 * 换服务商只实现本接口并在 config/goat_auth.php 切 driver，业务代码零改动。
 * 实现方**不得**自行落库、不得打日志带证件号明文。
 */
interface IdentityVerifier
{
    /** 驱动标识（写入 identity_verifications.provider，便于对账区分服务商） */
    public function name(): string;

    /**
     * @param  string  $realName  已 trim 的真实姓名
     * @param  string  $idNumber  已规范化的证件号（大写、去空格）
     */
    public function verify(string $realName, string $idNumber): IdentityCheckResult;
}

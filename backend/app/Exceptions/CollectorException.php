<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Exceptions;

use RuntimeException;

/**
 * 采集系统交互异常（契约 §4）。
 *
 * 与 TradeException 的区别：这是**跨系统故障**，不是用户操作不合法，
 * 因此默认 503（未配置/不可用）或 502（上游失败），由后台界面提示「稍后重试」。
 */
class CollectorException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 503,
        public readonly string $errorCode = 'COLLECTOR_UNAVAILABLE',
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self('采集系统未配置（COLLECTOR_BASE_URL / COLLECTOR_HMAC_KEY 为空）', 503, 'COLLECTOR_NOT_CONFIGURED');
    }

    public static function upstream(string $detail): self
    {
        return new self('采集系统调用失败：'.$detail, 502, 'COLLECTOR_UPSTREAM_FAILED');
    }
}

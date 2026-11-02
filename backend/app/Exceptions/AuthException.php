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
 * 认证/授权域异常（T2）。
 *
 * 约定：
 *  - 401 = 凭证无效或过期（客户端应跳登录）
 *  - 403 = 身份有效但无权限（封禁、越权）
 *  - 409 = 冲突（用户名/邮箱/店铺名已被占用）
 *  - 502 = 上游 OAuth 服务异常（微信/Apple）
 * 统一由 bootstrap/app.php 渲染成 {message, code} JSON，前端按 code 分支处理。
 */
class AuthException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 422,
        public readonly string $errorCode = 'AUTH_FAILED',
    ) {
        parent::__construct($message);
    }

    public static function invalidCredentials(): self
    {
        return new self('账号或密码不正确', 422, 'INVALID_CREDENTIALS');
    }

    public static function unauthorized(string $message = '请先登录'): self
    {
        return new self($message, 401, 'UNAUTHORIZED');
    }

    public static function forbidden(string $message = '当前账号无权执行该操作'): self
    {
        return new self($message, 403, 'FORBIDDEN');
    }

    public static function conflict(string $message): self
    {
        return new self($message, 409, 'CONFLICT');
    }

    public static function channelDisabled(string $channel): self
    {
        return new self("{$channel}登录通道未启用", 422, 'CHANNEL_DISABLED');
    }

    public static function provider(string $message, int $status = 502): self
    {
        return new self($message, $status, 'OAUTH_PROVIDER_ERROR');
    }

    public static function rejected(string $message, string $code = 'REJECTED'): self
    {
        return new self($message, 422, $code);
    }
}

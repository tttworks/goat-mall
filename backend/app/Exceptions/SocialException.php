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
 * 社群/互动域异常（T6）：收藏、关注、评价、帖子、私信。
 *
 * 与 TradeException(422) 分开的原因：互动域有「未完成订单不能评价」「帖子待审核不可见」
 * 这类 403/404 语义，前端要按状态码分支（引导去订单 / 提示等待审核），
 * 不能统一压成 422。
 *
 *  - 403 = 无权操作（评价他人订单、回复他人评价、读他人私信）
 *  - 404 = 对象不存在或对当前用户不可见
 *  - 409 = 重复提交（一单一评、重复关注）
 *  - 422 = 入参语义非法（评分越界、内容为空、命中机审）
 */
class SocialException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 422,
        public readonly string $errorCode = 'SOCIAL_INVALID',
    ) {
        parent::__construct($message);
    }

    public static function invalid(string $message): self
    {
        return new self($message, 422, 'SOCIAL_INVALID');
    }

    public static function forbidden(string $message = '无权执行该操作'): self
    {
        return new self($message, 403, 'SOCIAL_FORBIDDEN');
    }

    public static function notFound(string $message = '内容不存在或已失效'): self
    {
        return new self($message, 404, 'SOCIAL_NOT_FOUND');
    }

    public static function conflict(string $message): self
    {
        return new self($message, 409, 'SOCIAL_CONFLICT');
    }
}

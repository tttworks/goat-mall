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
 * 图鑑/媒体域异常（T3）。
 *
 * 为什么单独一个异常类：媒体上传与图鑑录入的失败原因前端要分支处理
 * （slug 冲突 → 提示换名；存储不可用 → 提示重试；外部 URL → 提示重新上传），
 * 全部塞进 422 会让前端无法区分。统一渲染为 {message, code} JSON。
 *
 *  - 404 = 条目不存在/未发布
 *  - 409 = slug 或外部 ID 冲突
 *  - 422 = 入参语义非法（如把外站 URL 当封面）
 *  - 503 = 对象存储不可用（UFile 抖动）
 */
class CatalogException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 422,
        public readonly string $errorCode = 'CATALOG_INVALID',
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $message = '图鑑条目不存在或未发布'): self
    {
        return new self($message, 404, 'FIGURE_NOT_FOUND');
    }

    public static function conflict(string $message): self
    {
        return new self($message, 409, 'CATALOG_CONFLICT');
    }

    public static function invalid(string $message): self
    {
        return new self($message, 422, 'CATALOG_INVALID');
    }

    public static function storageUnavailable(string $message = '图片存储服务暂不可用，请稍后重试'): self
    {
        return new self($message, 503, 'MEDIA_STORAGE_UNAVAILABLE');
    }
}

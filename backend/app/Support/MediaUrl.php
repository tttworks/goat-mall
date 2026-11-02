<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Support;

/**
 * 媒体 URL 拼接（契约 §2 + 项目约定 §2：DB 只存对象 key，不存完整 URL）。
 * 换存储域名时只改 MEDIA_BASE_URL / config('goat.media.base_url')，历史数据无需迁移。
 */
final class MediaUrl
{
    public static function base(): string
    {
        return rtrim((string) config('goat.media.base_url', ''), '/');
    }

    /** key → 完整 URL；空 key 返回 null（前端不渲染破图） */
    public static function for(?string $key): ?string
    {
        $key = trim((string) $key);
        if ($key === '') {
            return null;
        }

        // 防御：历史脏数据若已是绝对 URL，原样返回，避免拼出双域名
        if (preg_match('#^https?://#i', $key) === 1) {
            return $key;
        }

        return self::base().'/'.ltrim($key, '/');
    }

    /**
     * URL → key（后台粘贴完整 URL 时归一化为 key 再入库）。
     * 非本站基址的 URL 视为外链，不落库（返回 null，由调用方报校验错误）。
     */
    public static function keyOf(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        $base = self::base();
        if ($base !== '' && str_starts_with($url, $base)) {
            return ltrim(substr($url, strlen($base)), '/');
        }

        if (preg_match('#^https?://#i', $url) === 1) {
            return null;
        }

        return ltrim($url, '/');
    }
}

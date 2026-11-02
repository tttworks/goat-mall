<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Moderation\Drivers;

use App\Services\Moderation\Contracts\ModerationChannel;
use App\Services\Moderation\ModerationResult;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 云端内容安全驱动（T6）· 契约已就位，接入时只填 GOAT_MODERATION_BASE_URL / KEY。
 *
 * 关键取舍：任何异常（超时、5xx、返回体不认识）都转人工（review），不 fail-open。
 * fail-open 等于把违规内容直接推上 feed，代价远高于多一条待审。
 */
class HttpChannel implements ModerationChannel
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $key = '',
        private readonly int $timeout = 5,
    ) {}

    public function name(): string
    {
        return 'http';
    }

    public function screen(string $content, array $imageKeys = []): ModerationResult
    {
        if (trim($this->baseUrl) === '') {
            return ModerationResult::defer('未配置机审服务地址，转人工审核');
        }

        try {
            $response = Http::withToken($this->key)
                ->acceptJson()
                ->timeout($this->timeout)
                ->post(rtrim($this->baseUrl, '/').'/moderate', [
                    'content' => $content,
                    'images' => array_values($imageKeys),
                ]);
        } catch (Throwable) {
            // 不把底层异常（可能含地址/凭据）落库，只记结论
            return ModerationResult::defer('机审服务不可用，转人工审核');
        }

        if (! $response->successful()) {
            return ModerationResult::defer('机审服务返回异常状态，转人工审核');
        }

        $body = (array) $response->json();
        $labels = array_values(array_filter((array) ($body['labels'] ?? []), 'is_string'));
        $reason = (string) ($body['reason'] ?? '');

        return match ((string) ($body['verdict'] ?? ModerationResult::REVIEW)) {
            ModerationResult::PASS => ModerationResult::pass($labels),
            ModerationResult::REJECT => ModerationResult::reject($reason === '' ? '内容未通过机审' : $reason, $labels),
            default => ModerationResult::defer($reason === '' ? '机审置信度不足，转人工审核' : $reason),
        };
    }
}

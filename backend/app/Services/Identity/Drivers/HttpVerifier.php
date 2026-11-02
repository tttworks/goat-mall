<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Identity\Drivers;

use App\Enums\VerificationStatus;
use App\Services\Identity\Contracts\IdentityVerifier;
use App\Services\Identity\IdentityCheckResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * HTTP 核身驱动（T2）：对接二要素（姓名+证件号）核验服务商。
 *
 * 计费安全：请求带我方幂等号，网络超时**不自动重试**（重试即二次计费），
 * 由上层把记录留在 pending，人工/定时任务再查结果。
 */
class HttpVerifier implements IdentityVerifier
{
    public function name(): string
    {
        return 'http';
    }

    public function verify(string $realName, string $idNumber): IdentityCheckResult
    {
        $baseUrl = rtrim((string) config('goat.auth.identity.base_url', ''), '/');
        $key = (string) config('goat.auth.identity.key', '');

        if ($baseUrl === '' || $key === '') {
            throw new RuntimeException('未配置实名核身服务地址或密钥（IDENTITY_BASE_URL / IDENTITY_KEY）');
        }

        $response = Http::timeout((int) config('goat.auth.identity.timeout', 8))
            ->withHeaders([
                'X-Goat-Key' => $key,
                'X-Goat-Request-Id' => (string) \Illuminate\Support\Str::uuid(),
            ])
            ->acceptJson()
            ->post($baseUrl.'/v1/identity/verify2', [
                'name' => $realName,
                'id_number' => $idNumber,
            ]);

        if ($response->failed()) {
            // 不记录姓名/证件号，只记状态码，避免日志成为 PII 泄漏面
            Log::channel('security')->error('identity.http 调用失败', ['status' => $response->status()]);

            throw new RuntimeException('实名核身服务返回异常状态：'.$response->status());
        }

        $payload = $response->json() ?? [];
        $status = (string) ($payload['status'] ?? '');
        $ref = isset($payload['ref']) ? (string) $payload['ref'] : null;

        return match ($status) {
            'approved', 'pass' => IdentityCheckResult::approved($ref),
            'rejected', 'fail' => IdentityCheckResult::rejected((string) ($payload['reason'] ?? '姓名与证件号不一致'), $ref),
            default => new IdentityCheckResult(VerificationStatus::Pending, $ref, '服务商审核中'),
        };
    }
}

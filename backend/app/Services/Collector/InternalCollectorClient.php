<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Collector;

use App\Exceptions\CollectorException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 采集系统内部 API 客户端（契约 §4）。
 *
 * 方向只有一条：站点 → 采集（admin 触发）。反向上行一律走 Redis 队列 + 共享库，
 * 不做双向 HTTP，避免两个系统互相依赖、一起挂。
 *
 * 鉴权：共享 HMAC（密钥只在服务器 /root/goat_secrets.env），签名原文
 * `timestamp . "\n" . rawBody`，头 `X-Goat-Timestamp` / `X-Goat-Signature`。
 * 采集侧校验时间戳窗口（默认 300s）可防重放。
 *
 * 设计取舍：**只做透传，不做重试**。抓取是长任务，重试应该由采集侧的队列负责；
 * 站点侧重试只会把「一个任务」变成「三个任务」，反而放大重复抓取。
 */
class InternalCollectorClient
{
    public function crawlFigure(array $payload): array
    {
        return $this->send('crawl-figure', $payload);
    }

    public function crawlPrice(array $payload): array
    {
        return $this->send('crawl-price', $payload);
    }

    public function job(string $jobId): array
    {
        return $this->request('GET', '/internal/collector/job/'.rawurlencode($jobId), null);
    }

    // ---------------------------------------------------------------- 内部

    private function send(string $action, array $payload): array
    {
        return $this->request('POST', '/internal/collector/'.$action, $payload);
    }

    /** @param  array<string, mixed>|null  $payload */
    private function request(string $method, string $path, ?array $payload): array
    {
        $baseUrl = rtrim((string) config('goat.collector.base_url'), '/');
        $secret = (string) config('goat.collector.hmac_key');

        if ($baseUrl === '' || $secret === '') {
            throw CollectorException::notConfigured();
        }

        $body = $payload === null ? '' : (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp."\n".$body, $secret);

        try {
            $response = Http::withHeaders([
                'X-Goat-Timestamp' => $timestamp,
                'X-Goat-Signature' => $signature,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
                ->timeout((int) config('goat.collector.timeout', 10))
                ->withBody($body, 'application/json')
                ->send($method, $baseUrl.$path);
        } catch (ConnectionException $e) {
            Log::channel('security')->warning('collector.连接失败', ['path' => $path, 'error' => $e->getMessage()]);

            throw CollectorException::upstream('连接失败');
        }

        if ($response->failed()) {
            Log::channel('security')->warning('collector.上游返回错误', [
                'path' => $path,
                'status' => $response->status(),
            ]);

            throw CollectorException::upstream('HTTP '.$response->status());
        }

        return (array) $response->json();
    }
}

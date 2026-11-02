<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * 健康检查（T1）：给宝塔/监控/WAF 面板探活用。
 * 约定：依赖不通返回 503 + 具体 degraded 项，**不抛 500**，便于监控区分「进程挂了」与「DB 不通」。
 */
class HealthController
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'app' => $this->guard(fn () => true),
            'pgsql' => $this->guard(fn () => DB::select('select 1 as ok') !== []),
            'redis' => $this->guard(fn () => (string) Redis::connection()->ping() !== ''),
        ];

        $healthy = ! in_array(false, array_column($checks, 'ok'), true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'service' => 'goat-mall',
            'version' => (string) config('goat.version', '0.0.0'),
            'checks' => $checks,
            'time' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }

    /** @return array{ok: bool, detail?: string} */
    private function guard(callable $probe): array
    {
        try {
            return ['ok' => (bool) $probe()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => class_basename($e).': '.$e->getMessage()];
        }
    }
}

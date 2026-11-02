<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

/**
 * 应用引导（Laravel 12 精简式骨架，T1）。
 * 路由分三入口：web（极少，仅健康检查/回调页）、api（/api/v1，全部业务）、channels（Reverb WS 鉴权）。
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API 一律 JSON：前端（uni-app / PC 后台）不解析 HTML 错误页
        $middleware->api(prepend: [
            \App\Http\Middleware\ForceJson::class,
        ]);

        // 角色守卫（T2）：路由里写 ->middleware('role:admin')
        // 卖家守卫（T4）：店铺 active + 实名 approved，与角色是两套口径，不能合并
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
            'seller' => \App\Http\Middleware\EnsureSeller::class,
        ]);

        // 反代/Octane 场景下取真实 IP（WAF 与风控依赖）
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 业务异常 → 结构化 JSON，避免前端拿到 HTML/堆栈
        // 422：请求本身不合法（库存不足、出价过低、幂等键冲突…）
        $exceptions->render(function (\App\Exceptions\TradeException $e, Request $request) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'TRADE_REJECTED'], 422);
        });

        // 409：内部状态非法跃迁（必须被监控，不静默）
        $exceptions->render(function (\App\Exceptions\IllegalStateTransition $e, Request $request) {
            report($e);

            return response()->json(['message' => $e->getMessage(), 'code' => 'ILLEGAL_STATE_TRANSITION'], 409);
        });

        // 认证/授权域（T2）：401 凭证失效、403 无权限、409 冲突、502 上游 OAuth 异常
        $exceptions->render(function (\App\Exceptions\AuthException $e, Request $request) {
            $status = $e->statusCode;

            // 5xx 类（上游服务故障）需要进告警链路
            if ($status >= 500) {
                report($e);
            }

            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $status);
        });

        // 图鑑/媒体域（T3）：404 未发布、409 slug 冲突、422 非法入参、503 存储不可用
        $exceptions->render(function (\App\Exceptions\CatalogException $e, Request $request) {
            if ($e->statusCode >= 500) {
                report($e);
            }

            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->statusCode);
        });

        // 社群/互动域（T6）：403 越权、404 不可见、409 重复、422 入参非法
        $exceptions->render(function (\App\Exceptions\SocialException $e, Request $request) {
            if ($e->statusCode >= 500) {
                report($e);
            }

            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->statusCode);
        });
    })
    ->create();

<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API 组强制 JSON 协商（T1）。
 * 客户端（uni-app / PC 后台 / 采集器）统一拿 JSON，避免异常时被框架渲染成 HTML 页面。
 */
class ForceJson
{
    public function handle(Request $request, Closure $next): Response
    {
        // 不能只判断「有没有 Accept」：浏览器/uni-app 的 XHR 会自动带 `Accept: */*`，
        // 这种情况下 expectsJson() 为 false，未登录请求会被框架重定向到不存在的 login 路由，
        // 结果是 500 而不是 401。API 组内一律按 JSON 协商。
        if (! $request->wantsJson()) {
            $request->headers->set('Accept', 'application/json');
        }

        $request->headers->set('X-Request-Id', $request->headers->get('X-Request-Id') ?: (string) \Illuminate\Support\Str::uuid());

        return $next($request);
    }
}

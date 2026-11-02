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
        if (! $request->headers->has('Accept')) {
            $request->headers->set('Accept', 'application/json');
        }

        $request->headers->set('X-Request-Id', $request->headers->get('X-Request-Id') ?: (string) \Illuminate\Support\Str::uuid());

        return $next($request);
    }
}

<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * 实时推送端点配置（T6）。
 *
 * 为什么由服务端下发而不是打包进前端：Reverb 的 app key 与域名是**部署环境属性**，
 * 写进前端产物等于把临时站配置焊死在包里；换环境就得重新打包。
 *
 * app key 是公开标识（Pusher 协议本就明文），secret 绝不下发——签名只在服务端算。
 */
class RealtimeController extends Controller
{
    public function config(Request $request): JsonResponse
    {
        $enabled = (bool) config('goat.ws.enabled');
        $key = (string) config('goat.ws.app_key');

        return response()->json([
            'data' => [
                // 开关与 key 任一缺失都视为不可用：宁可前端走轮询，也不要连一个注定失败的 WS
                'enabled' => $enabled && $key !== '',
                'key' => $key,
                'host' => (string) config('goat.ws.host'),
                'port' => (int) config('goat.ws.port'),
                'scheme' => (string) config('goat.ws.scheme'),
                'auth_endpoint' => url('/api/v1/broadcasting/auth'),
                'channels' => [
                    'user' => 'private-goat.user.{userId}',
                    'auction' => 'goat.auction.{auctionId}',
                    'figure' => 'goat.figure.{figureId}',
                ],
            ],
        ]);
    }
}

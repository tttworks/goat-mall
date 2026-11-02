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
use Illuminate\Support\Facades\Broadcast;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reverb 频道鉴权（T6 实时化）。
 *
 * 为什么不用框架自带的 /broadcasting/auth：它默认走 web 会话守卫，
 * 而本项目 API 清一色 Bearer Token（Sanctum）。这里显式挂在 api 组 + auth:sanctum 下，
 * 前端只用一个鉴权地址，不需要为 WS 再维持一份 Cookie 会话。
 *
 * 鉴权规则仍在 routes/channels.php（单一事实来源），本控制器只做「转发 + 统一错误形状」。
 */
class BroadcastAuthController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'socket_id' => ['required', 'string', 'max:64', 'regex:/^\d+\.\d+$/'],
            'channel_name' => ['required', 'string', 'max:200'],
        ]);

        // 频道是否允许由 channels.php 的回调判定；不通过时框架抛 403
        $result = Broadcast::auth($request);

        // 驱动不同，返回形状不同：JsonResponse / 原始 Response / 数组 / 签名串。
        // 这里统一成 `{auth: "..."}`，前端只认这一种形状（Pusher 协议本身如此）。
        if ($result instanceof JsonResponse) {
            return $result;
        }

        if ($result instanceof Response) {
            return response()->json((array) json_decode((string) $result->getContent(), true));
        }

        if (is_array($result)) {
            return response()->json($result);
        }

        return response()->json(['auth' => (string) $result]);
    }
}

<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Auth;

use App\Exceptions\AuthException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 微信小程序登录换 openid（T2）。
 *
 * 安全约定：session_key 只用于当次解密（如手机号快捷绑定），**绝不落库、绝不回传前端**。
 * 服务端拿到 openid/unionid 即丢弃其余字段。
 */
class WechatClient
{
    /**
     * @return array{openid: string, unionid: string|null}
     */
    public function code2Session(string $code): array
    {
        $appId = (string) config('goat_auth.wechat.app_id');
        $secret = (string) config('goat_auth.wechat.app_secret');

        if ($appId === '' || $secret === '') {
            throw AuthException::provider('微信登录未配置（缺少 WECHAT_APP_ID / APP_SECRET）', 500);
        }

        try {
            $response = Http::timeout((int) config('goat_auth.wechat.timeout', 5))
                ->retry(2, 200)
                ->get('https://api.weixin.qq.com/sns/jscode2session', [
                    'appid' => $appId,
                    'secret' => $secret,
                    'js_code' => $code,
                    'grant_type' => 'authorization_code',
                ]);
        } catch (\Throwable $e) {
            Log::channel('security')->warning('wechat.code2session 网络失败', ['error' => $e->getMessage()]);

            throw AuthException::provider('微信登录服务暂不可用');
        }

        $payload = $response->json() ?? [];

        if (($payload['errcode'] ?? 0) !== 0 || empty($payload['openid'])) {
            // errcode 40029=code 无效/已用；45011=频率限制。只记 errcode，不记 code 与 secret
            Log::channel('security')->warning('wechat.code2session 拒绝', [
                'errcode' => $payload['errcode'] ?? null,
                'errmsg' => $payload['errmsg'] ?? null,
            ]);

            throw AuthException::provider('微信登录凭证无效，请重新授权');
        }

        return [
            'openid' => (string) $payload['openid'],
            'unionid' => isset($payload['unionid']) ? (string) $payload['unionid'] : null,
        ];
    }
}

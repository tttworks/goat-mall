<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

/**
 * 认证域配置（T2）。单独成文件而不并入 config/goat.php，是为了让认证/合规相关参数集中可审。
 * 敏感值（app_secret / 核身密钥 / hash_salt）只从 .env 读，仓库内不出现真实值。
 */
return [

    // Token 有效期（分钟）；0 = 永不过期（不建议）
    'token' => [
        'ttl_minutes' => (int) env('GOAT_AUTH_TOKEN_TTL', 43200), // 30 天
    ],

    // 四通道开关；phone 预留且默认关闭（零采购原则：短信未采购）
    'channels' => [
        'username' => (bool) env('GOAT_AUTH_USERNAME_ENABLED', true),
        'email' => (bool) env('GOAT_AUTH_EMAIL_ENABLED', true),
        'wechat' => (bool) env('GOAT_AUTH_WECHAT_ENABLED', true),
        'apple' => (bool) env('GOAT_AUTH_APPLE_ENABLED', true),
        'phone' => (bool) env('GOAT_AUTH_PHONE_ENABLED', false),
    ],

    'password' => [
        'min' => (int) env('GOAT_AUTH_PASSWORD_MIN', 8),
    ],

    // 限流：格式「次数,分钟」
    'throttle' => [
        'login' => env('GOAT_AUTH_THROTTLE_LOGIN', '10,1'),
        'register' => env('GOAT_AUTH_THROTTLE_REGISTER', '5,1'),
        'provider' => env('GOAT_AUTH_THROTTLE_PROVIDER', '20,1'),
    ],

    'wechat' => [
        'app_id' => env('WECHAT_APP_ID'),
        'app_secret' => env('WECHAT_APP_SECRET'),
        'timeout' => (int) env('WECHAT_TIMEOUT', 5),
    ],

    'apple' => [
        // iOS Bundle ID（Apple 令牌 aud 必须与之匹配）
        'client_id' => env('APPLE_CLIENT_ID', env('APPLE_BUNDLE_ID')),
        'issuer' => env('APPLE_ISSUER', 'https://appleid.apple.com'),
        'jwks_url' => env('APPLE_JWKS_URL', 'https://appleid.apple.com/auth/keys'),
        'jwks_ttl' => (int) env('APPLE_JWKS_TTL', 43200),
    ],

    // 实名核身（按次付费，仅开店触发）
    'identity' => [
        'driver' => env('GOAT_IDENTITY_DRIVER', 'null'), // null(仅本地) / http
        'base_url' => env('IDENTITY_BASE_URL'),
        'key' => env('IDENTITY_KEY'),
        'timeout' => (int) env('IDENTITY_TIMEOUT', 8),
        // 证件号去重散列盐；留空则退化为 APP_KEY。**上线后不要更换**，否则历史去重失效
        'hash_salt' => env('GOAT_IDENTITY_HASH_SALT'),
    ],

];

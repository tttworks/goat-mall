<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

/**
 * 第三方/内部服务凭据（T1）。所有密钥只从环境读取，仓库内永不出现真实值。
 */
return [

    // 采集系统内部 API（契约 §4）：站点 → goat-collector，HMAC 双向验签
    'collector' => [
        'base_url' => env('COLLECTOR_BASE_URL', 'http://127.0.0.1:8020'),
        'hmac_key' => env('COLLECTOR_HMAC_KEY'),
        'hmac_ttl' => (int) env('COLLECTOR_HMAC_TTL', 300),
        'timeout' => (int) env('COLLECTOR_TIMEOUT', 10),
    ],

    // UCloud UFile（S3 兼容；文件系统盘见 config/filesystems.php）
    'ufile' => [
        'access_key' => env('UFILE_ACCESS_KEY'),
        'secret_key' => env('UFILE_SECRET_KEY'),
        'bucket' => env('UFILE_BUCKET', 'goat'),
        'region' => env('UFILE_REGION', 'cn-sh2'),
    ],

    // 微信小程序/开放平台（登录由 T2 使用）
    'wechat' => [
        'app_id' => env('WECHAT_APP_ID'),
        'app_secret' => env('WECHAT_APP_SECRET'),
        'mch_id' => env('WECHAT_MCH_ID'),
    ],

    // 内容机审（T6 发帖审核；按次付费，仅命中时调用）
    'moderation' => [
        'base_url' => env('MODERATION_BASE_URL'),
        'key' => env('MODERATION_KEY'),
    ],

    // 实名核身（T2 开店触发；按次付费）
    'identity' => [
        'base_url' => env('IDENTITY_BASE_URL'),
        'key' => env('IDENTITY_KEY'),
    ],
];

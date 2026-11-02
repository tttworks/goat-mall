<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

/**
 * GOAT 自有配置（唯一允许出现产品级默认值的地方）。
 * 所有可运营参数（费率/防狙击/锁库时长）先落配置或 DB，禁止写死在业务代码里。
 * 环境变量前缀统一 GOAT_，凭据一律走 .env（不进仓库）。
 */
return [

    // 媒体 URL 基址：DB 只存 key，URL 由 MediaUrl 拼接（契约 §2）
    'media' => [
        'base_url' => env('MEDIA_BASE_URL', ''),
        'disk' => env('MEDIA_DISK', 'ufile'),
        // 直传签名有效期（分钟）：太短编辑器来不及传，太长等于给了长期写权限
        'presign_ttl' => (int) env('MEDIA_PRESIGN_TTL', 15),
        // 允许上传的业务目录白名单（顺序即后台选择器顺序）
        'folders' => ['figures', 'listings', 'posts', 'avatars'],
    ],

    // 交易域（T4）
    'trade' => [
        // 下单锁库时长（分钟）：超时释放，防「拍下不付款」占库存
        'reserve_minutes' => (int) env('GOAT_TRADE_RESERVE_MINUTES', 30),
        // 待付款订单自动关闭（分钟）
        'payment_timeout_minutes' => (int) env('GOAT_TRADE_PAYMENT_TIMEOUT_MINUTES', 30),
        // 已发货后自动确认收货（天），兜底买家不操作
        'auto_confirm_days' => (int) env('GOAT_TRADE_AUTO_CONFIRM_DAYS', 10),
        // 费率兜底（DB fee_rules 无命中时使用）；bip = 万分比，0 = 免费期
        'default_fee' => [
            'percent_bip' => (int) env('GOAT_TRADE_DEFAULT_FEE_BIP', 0),
            'cap_amount' => env('GOAT_TRADE_DEFAULT_FEE_CAP', null),
            'bearer' => env('GOAT_TRADE_DEFAULT_FEE_BEARER', 'seller'),
        ],
    ],

    // 竞拍域（T4 · 防狙击）
    'auction' => [
        'anti_snipe_seconds' => (int) env('GOAT_AUCTION_ANTI_SNIPE_SECONDS', 60),
        'extension_seconds' => (int) env('GOAT_AUCTION_EXTENSION_SECONDS', 60),
        'max_extensions' => (int) env('GOAT_AUCTION_MAX_EXTENSIONS', 10),
        'min_increment' => env('GOAT_AUCTION_MIN_INCREMENT', '1.00'),
    ],

    // 采集系统内部 API（契约 §4；HMAC 密钥只存服务器环境）
    'collector' => [
        'base_url' => env('COLLECTOR_BASE_URL', ''),
        'hmac_key' => env('COLLECTOR_HMAC_KEY', ''),
        'hmac_ttl' => (int) env('COLLECTOR_HMAC_TTL', 300),
    ],

];

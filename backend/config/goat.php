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

    // 认证域（T2）。集中一处可审：Token 生命周期、通道开关、限流、三通道凭据、核身驱动。
    // 敏感值（app_secret / 核身密钥 / hash_salt）只从 .env 读，仓库内不出现真实值。
    'auth' => [

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

    ],

    // 支付与担保交易（T5）。零采购：无商户号时 channel=sandbox，签名密钥自己定。
    'payment' => [

        // 通道：sandbox（本地/联调）/ wechat（收付通，商户号下来后新增驱动）
        'channel' => env('GOAT_PAYMENT_CHANNEL', 'sandbox'),

        'sandbox' => [
            // 回调验签密钥；生产环境必须换成强随机值，且与任何真实渠道密钥不同
            'secret' => env('GOAT_PAYMENT_SANDBOX_SECRET', 'sandbox-not-for-production'),
            // 异步通知时间戳容忍窗口（秒），防重放
            'notify_tolerance' => (int) env('GOAT_PAYMENT_NOTIFY_TOLERANCE', 300),
        ],

        // 担保规则：支付成功即冻结，买家确认收货后才分账给卖家
        'escrow' => [
            'auto_settle_on_confirm' => (bool) env('GOAT_PAYMENT_AUTO_SETTLE', true),
        ],

    ],

    // 社群 UGC（T6）：帖子审核口径
    'community' => [
        // 机审通过后是否直接发布；false = 全部转人工（灰度期建议 false，先攒人工样本）
        'auto_publish_on_pass' => (bool) env('GOAT_COMMUNITY_AUTO_PUBLISH', true),
        'post_max_images' => (int) env('GOAT_COMMUNITY_POST_MAX_IMAGES', 9),
        'post_max_length' => (int) env('GOAT_COMMUNITY_POST_MAX_LENGTH', 1000),
    ],

    // 内容机审通道（T6）：keyword（零采购默认，词表走环境变量）/ http（云厂商，填 base_url + key）
    // 词表不进代码：它属于运营策略，随时会变
    'moderation' => [
        'driver' => env('GOAT_MODERATION_DRIVER', 'keyword'),
        'keywords' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('GOAT_MODERATION_KEYWORDS', ''))
        ))),
        'base_url' => env('GOAT_MODERATION_BASE_URL', ''),
        'key' => env('GOAT_MODERATION_KEY', ''),
        'timeout' => (int) env('GOAT_MODERATION_TIMEOUT', 5),
    ],

    // 私信（T6）：HTTP 拉取为兜底，WS 只做「增量提示」，断开也不影响可用性
    'im' => [
        'max_length' => (int) env('GOAT_IM_MAX_LENGTH', 1000),
    ],

    // 实时推送总开关（T6）：Reverb 未部署时保持关闭，业务照旧走 HTTP 拉取。
    // 关闭时事件不派发 —— 不是「派发到黑洞」，避免队列里堆无意义的广播 job。
    'ws' => [
        'enabled' => (bool) env('GOAT_WS_ENABLED', false),
        // 客户端订阅用的公开地址（写入 /api/v1/realtime/config 下发给前端）
        'app_key' => env('REVERB_APP_KEY', ''),
        'host' => env('REVERB_HOST', ''),
        'port' => (int) env('REVERB_PORT', 443),
        'scheme' => env('REVERB_SCHEME', 'https'),
    ],

    // 采集系统内部 API（契约 §4；HMAC 密钥只存服务器环境）
    'collector' => [
        'base_url' => env('COLLECTOR_BASE_URL', ''),
        'hmac_key' => env('COLLECTOR_HMAC_KEY', ''),
        'hmac_ttl' => (int) env('COLLECTOR_HMAC_TTL', 300),
        // 触发是异步任务（202 + job_id），不需要长超时；卡住就快速失败，让运营重试
        'timeout' => (int) env('COLLECTOR_TIMEOUT', 10),
    ],

    // 首个运营管理员（仅 AdminUserSeeder 读取）
    // password 留空 = 随机生成并在 seed 输出里打印一次；显式配置 = 重置为该值
    'admin' => [
        'username' => env('GOAT_ADMIN_USERNAME', 'admin'),
        'email' => env('GOAT_ADMIN_EMAIL'),
        'password' => env('GOAT_ADMIN_PASSWORD'),
    ],

];

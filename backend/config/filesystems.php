<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

/**
 * 文件系统（T1）。核心约定：**DB 只存对象 key**，URL 由 App\Support\MediaUrl 按 MEDIA_BASE_URL 拼接。
 *
 * UCloud UFile 提供 S3 兼容端点，故用 s3 driver 直连（不引入 UFile 私有 SDK），
 * 换存储厂商只需改 UFILE_ENDPOINT / MEDIA_BASE_URL，代码零改动、历史数据零迁移。
 */
return [
    'default' => env('FILESYSTEM_DISK', 'ufile'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        // 生产默认：UCloud UFile（S3 协议，path-style）
        'ufile' => [
            'driver' => 's3',
            'key' => env('UFILE_ACCESS_KEY'),
            'secret' => env('UFILE_SECRET_KEY'),
            'region' => env('UFILE_REGION', 'cn-sh2'),
            'bucket' => env('UFILE_BUCKET', 'goat'),
            'endpoint' => env('UFILE_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('UFILE_USE_PATH_STYLE', true),
            'visibility' => 'public',
            'options' => [
                // UFile/S3 兼容层对 ACL 头支持不一致，统一用 bucket 策略控制可见性
                'ACL' => 'public-read',
            ],
            'throw' => true,
        ],

        // 本地开发兜底：没有 UFile 凭据时用 local 顶替，key 规则保持一致
        'ufile_local' => [
            'driver' => 'local',
            'root' => storage_path('app/ufile'),
            'url' => env('MEDIA_BASE_URL', env('APP_URL')).'/media',
            'visibility' => 'public',
            'throw' => false,
        ],
    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],
];

<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

/**
 * 队列（T1）：所有业务队列走 Redis 1 号库，与采集系统共用实例但**队列名隔离**（goat:*）。
 * after_commit=true：先落库再投递，避免事务回滚后出现「幽灵任务」。
 */
return [
    'default' => env('QUEUE_CONNECTION', 'redis'),

    'connections' => [
        'sync' => [
            'driver' => 'sync',
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'queue',
            'queue' => env('REDIS_QUEUE', 'goat:default'),
            'retry_after' => (int) env('QUEUE_RETRY_AFTER', 90),
            'block_for' => 5,
            'after_commit' => true,
        ],

        // 支付回调/分账等「绝不允许丢」的任务：独立队列 + 更长重试
        'redis_critical' => [
            'driver' => 'redis',
            'connection' => 'queue',
            'queue' => 'goat:critical',
            'retry_after' => 120,
            'block_for' => 5,
            'after_commit' => true,
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => 'goat_jobs',
            'queue' => 'goat:default',
            'retry_after' => 90,
            'after_commit' => true,
        ],
    ],

    'batching' => [
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'goat_job_batches',
    ],

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'goat_failed_jobs',
    ],
];

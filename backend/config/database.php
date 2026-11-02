<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

use Illuminate\Support\Str;

/**
 * 数据库 / Redis 连接（T1）。
 *
 * PG 经 PgBouncer 事务池（端口 6432）：事务池模式下服务端预处理语句会串连接，
 * 因此强制 EMULATE_PREPARES=true —— 这是本项目**必须**的配置，不要改成原生 prepare。
 * Redis 按用途分库：0 缓存 / 1 队列 / 2 广播，键前缀统一 goat: 便于隔离与审计。
 */
return [

    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [

        'pgsql' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '6432'),
            'database' => env('DB_DATABASE', 'goat_mall'),
            'username' => env('DB_USERNAME', 'goat'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => env('DB_SCHEMA', 'public'),
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            // PgBouncer 事务池必需：关闭服务端预处理语句
            'options' => extension_loaded('pdo_pgsql') ? [
                PDO::ATTR_EMULATE_PREPARES => true,
            ] : [],
        ],

        // 只读副本（行情/图鑑大表查询走这里，T9 灌数期间保护主库）
        'pgsql_read' => [
            'driver' => 'pgsql',
            'host' => env('DB_READ_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('DB_READ_PORT', '6432'),
            'database' => env('DB_DATABASE', 'goat_mall'),
            'username' => env('DB_READ_USERNAME', env('DB_USERNAME', 'goat')),
            'password' => env('DB_READ_PASSWORD', env('DB_PASSWORD', '')),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => env('DB_SCHEMA', 'public'),
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            'options' => extension_loaded('pdo_pgsql') ? [
                PDO::ATTR_EMULATE_PREPARES => true,
            ] : [],
        ],

        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ],

    'migrations' => [
        'table' => 'goat_migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [
        // predis：纯 PHP 实现，宝塔环境无需额外编译扩展即可用
        'client' => env('REDIS_CLIENT', 'predis'),

        'options' => [
            'prefix' => env('REDIS_PREFIX', 'goat:'),
            'persistent' => false,
            'timeout' => 3.0,
            'read_write_timeout' => 5.0,
        ],

        'default' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '0'),
        ],

        'queue' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_QUEUE_DB', '1'),
        ],

        'broadcast' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_BROADCAST_DB', '2'),
        ],

        // 采集系统写入的队列库（T9 只写不读，站点通过同一 Redis 实例协作）
        'collector' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_COLLECTOR_DB', '3'),
        ],
    ],

    'redelivery' => [
        'window' => (int) env('DB_DEADLOCK_RETRY', 3),
    ],

    'driver_key' => Str::slug(env('DB_CONNECTION', 'pgsql')),
];

<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

/**
 * Horizon（T1）。三条监督线：
 *   goat:critical  支付回调/分账/退款 —— 优先、并发高、失败即告警（T10 接 WAF 面板）
 *   goat:default   常规业务队列
 *   goat:collector 采集灌数与行情计算（低优先级，占用大时不影响交易）
 */
return [
    'domain' => env('HORIZON_DOMAIN'),
    'path' => env('HORIZON_PATH', 'goat/horizon'),

    'use' => 'default',

    'prefix' => env('HORIZON_PREFIX', 'goat_horizon:'),

    'middleware' => ['web'],

    'waits' => [
        'redis:goat:critical' => 10,
        'redis:goat:default' => 30,
        'redis:goat:collector' => 300,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 1440,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [],

    'metrics' => [
        'trim_snapshots' => ['job' => 24, 'queue' => 24],
    ],

    'fast_termination' => false,

    'memory_limit' => 256,

    'defaults' => [
        'goat-trade' => [
            'connection' => 'redis',
            'queue' => ['goat:critical', 'goat:default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 8,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 3,
            'timeout' => 60,
            'nice' => 0,
        ],
        'goat-collector' => [
            'connection' => 'redis',
            'queue' => ['goat:collector'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'size',
            'maxProcesses' => 4,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 512,
            'tries' => 1,
            'timeout' => 300,
            'nice' => 10,
        ],
    ],

    'environments' => [
        'production' => [
            'goat-trade' => ['maxProcesses' => 12, 'balanceMaxShift' => 2, 'balanceCooldown' => 3],
            'goat-collector' => ['maxProcesses' => 4],
        ],

        'local' => [
            'goat-trade' => ['maxProcesses' => 2],
            'goat-collector' => ['maxProcesses' => 1],
        ],
    ],
];

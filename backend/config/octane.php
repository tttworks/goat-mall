<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

/**
 * Octane 配置（T1）。用 Swoole 常驻进程顶 C 端流量，nginx 只做反代。
 *
 * 常驻模式注意（写给后来者）：
 *  - 单例/静态变量会在请求间残留，容器内的有状态对象一律 scope 绑定，别用 singleton 保存请求数据；
 *  - 长查询/上传要放大 package_max_length，默认 2M 传不了模型图；
 *  - max_requests 触发 worker 重启，防止内存缓慢上涨。
 */
return [
    'server' => env('OCTANE_SERVER', 'swoole'),

    'https' => (bool) env('OCTANE_HTTPS', false),

    'max_requests' => (int) env('OCTANE_MAX_REQUESTS', 500),

    'listeners' => [
        Laravel\Octane\Events\WorkerStarting::class => [],
        Laravel\Octane\Events\RequestReceived::class => [],
        Laravel\Octane\Events\RequestTerminated::class => [],
        Laravel\Octane\Events\WorkerError::class => [
            Laravel\Octane\Listeners\ReportException::class,
        ],
    ],

    'warm' => [
        'config',
        'routes',
    ],

    'cache' => [
        'rows' => 1000,
        'bytes' => 10000,
    ],

    'tables' => [],

    'swoole' => [
        'options' => [
            'log_file' => storage_path('logs/swoole_http.log'),
            'package_max_length' => 10 * 1024 * 1024,
            'buffer_output_size' => 4 * 1024 * 1024,
            'worker_num' => (int) env('OCTANE_WORKERS', 4),
            'task_worker_num' => (int) env('OCTANE_TASK_WORKERS', 2),
            'enable_coroutine' => true,
            'max_coroutine' => 20000,
            'socket_buffer_size' => 8 * 1024 * 1024,
            'reload_async' => true,
            'tcp_fastopen' => true,
        ],
    ],

    'frankenphp' => [
        'workers' => (int) env('OCTANE_WORKERS', 4),
    ],

    'roadrunner' => [
        'workers' => (int) env('OCTANE_WORKERS', 4),
        'max_jobs' => (int) env('OCTANE_MAX_REQUESTS', 500),
    ],
];

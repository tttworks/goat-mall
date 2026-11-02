<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

use Laravel\Octane\Contracts\OperationTerminated;
use Laravel\Octane\Events\RequestHandled;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Events\TickReceived;
use Laravel\Octane\Events\TickTerminated;
use Laravel\Octane\Events\WorkerErrorOccurred;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Events\WorkerStopping;
use Laravel\Octane\Listeners\CloseMonologHandlers;
use Laravel\Octane\Listeners\EnsureUploadedFilesAreValid;
use Laravel\Octane\Listeners\EnsureUploadedFilesCanBeMoved;
use Laravel\Octane\Listeners\FlushOnce;
use Laravel\Octane\Listeners\FlushTemporaryContainerInstances;
use Laravel\Octane\Listeners\ReportException;
use Laravel\Octane\Listeners\StopWorkerIfNecessary;
use Laravel\Octane\Octane;

/**
 * Octane 配置（T1）。用 Swoole 常驻进程顶 C 端流量，nginx 只做反代。
 *
 * 常驻模式注意（写给后来者）：
 *  - 单例/静态变量会在请求间残留，容器内的有状态对象一律 scope 绑定，别用 singleton 保存请求数据；
 *  - **listeners 不能留空**：`prepareApplicationForNextOperation()` / `prepareApplicationForNextRequest()`
 *    负责逐请求把 request 实例、auth guard、session、数据库连接等重置。留空会让容器里的
 *    `app('request')` 永远停在进程启动时那个空请求，表现为「所有带 Bearer token 的请求 401」，
 *    而 artisan 内部路由测试却正常，极难排查；
 *  - `FlushTemporaryContainerInstances` 只能挂 `OperationTerminated`（它要 `$event->app`），
 *    挂到 `RequestHandled` 会直接 500：`Undefined property: RequestHandled::$app`；
 *  - 长查询/上传要放大 package_max_length，默认 2M 传不了模型图；
 *  - max_requests 触发 worker 重启，防止内存缓慢上涨。
 */
return [
    'server' => env('OCTANE_SERVER', 'swoole'),

    'https' => (bool) env('OCTANE_HTTPS', false),

    'listeners' => [
        WorkerStarting::class => [
            EnsureUploadedFilesAreValid::class,
            EnsureUploadedFilesCanBeMoved::class,
        ],

        RequestReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
            ...Octane::prepareApplicationForNextRequest(),
        ],

        RequestHandled::class => [],

        RequestTerminated::class => [],

        TaskReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
        ],

        TaskTerminated::class => [],

        TickReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
        ],

        TickTerminated::class => [],

        OperationTerminated::class => [
            FlushOnce::class,
            FlushTemporaryContainerInstances::class,
        ],

        WorkerErrorOccurred::class => [
            ReportException::class,
            StopWorkerIfNecessary::class,
        ],

        WorkerStopping::class => [
            CloseMonologHandlers::class,
        ],
    ],

    'max_requests' => (int) env('OCTANE_MAX_REQUESTS', 500),

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

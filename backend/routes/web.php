<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 *
 * web 路由：只放「必须走浏览器」的极少数端点（探活、支付回调落地页）。
 * H5 站点默认禁止直访（T7 管制），C 端全部走 /api/v1。
 */

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'service' => 'goat-mall',
    'message' => 'GOAT 潮玩交易平台 · 站点后端',
    'api' => '/api/v1',
]));

Route::get('/healthz', HealthController::class)->name('goat.healthz');

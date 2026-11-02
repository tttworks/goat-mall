<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 *
 * 对外 API 路由（契约 §4 的「站点对外 API」部分）。
 * 本轮由 T4 落交易/竞价路由；T2 认证、T3 图鑑、T6 社群各自追加自己的路由块，
 * 追加时用独立 Route::prefix() 分组，避免与其他 agent 改同一行。
 */

use App\Http\Controllers\Api\AuctionController;
use App\Http\Controllers\Api\ListingController;
use App\Http\Controllers\Api\OrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    // 商品浏览（公开）
    Route::get('/listings', [ListingController::class, 'index'])->name('listings.index');
    Route::get('/listings/{listing}', [ListingController::class, 'show'])
        ->whereNumber('listing')->name('listings.show');

    // 竞拍浏览（公开）
    Route::get('/auctions', [AuctionController::class, 'index'])->name('auctions.index');
    Route::get('/auctions/{auction}', [AuctionController::class, 'show'])
        ->whereNumber('auction')->name('auctions.show');

    // 交易 + 出价（需登录；认证提供方 = T2）
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
        Route::get('/orders/{order}', [OrderController::class, 'show'])
            ->whereNumber('order')->name('orders.show');
        Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])
            ->whereNumber('order')->name('orders.cancel');

        Route::post('/auctions/{auction}/bids', [AuctionController::class, 'bid'])
            ->whereNumber('auction')->name('auctions.bid');
    });
});

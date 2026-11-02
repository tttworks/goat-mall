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

use App\Http\Controllers\Api\Admin\FigureController as AdminFigureController;
use App\Http\Controllers\Api\Admin\ListingController as AdminListingController;
use App\Http\Controllers\Api\Admin\MediaController as AdminMediaController;
use App\Http\Controllers\Api\AuctionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\FigureController;
use App\Http\Controllers\Api\FollowController;
use App\Http\Controllers\Api\IdentityController;
use App\Http\Controllers\Api\ListingController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\SellerAuctionController;
use App\Http\Controllers\Api\SellerController;
use App\Http\Controllers\Api\SellerListingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {

    // ---------------- 支付回调（T5）----------------
    // 渠道服务器不会带用户 Token，身份完全由签名证明；限流防刷但不过严，避免渠道重试被拦
    Route::post('/payments/notify', [PaymentController::class, 'notify'])
        ->middleware('throttle:120,1')
        ->name('payments.notify');

    // ---------------- 认证与用户（T2）----------------
    Route::prefix('auth')->name('auth.')->group(function () {
        // 公开通道（按 IP 限流，防撞库）
        Route::post('/register', [AuthController::class, 'register'])
            ->middleware('throttle:'.config('goat.auth.throttle.register', '5,1'))
            ->name('register');
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:'.config('goat.auth.throttle.login', '10,1'))
            ->name('login');
        Route::post('/wechat', [AuthController::class, 'wechat'])
            ->middleware('throttle:'.config('goat.auth.throttle.provider', '20,1'))
            ->name('wechat');
        Route::post('/apple', [AuthController::class, 'apple'])
            ->middleware('throttle:'.config('goat.auth.throttle.provider', '20,1'))
            ->name('apple');

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/me', [AuthController::class, 'me'])->name('me');
            Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        });
    });

    // 卖家开店 + 实名核身（T2）
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/seller', [SellerController::class, 'show'])->name('seller.show');
        Route::post('/seller/apply', [SellerController::class, 'apply'])->name('seller.apply');

        Route::get('/identity', [IdentityController::class, 'show'])->name('identity.show');
        Route::post('/identity', [IdentityController::class, 'submit'])->name('identity.submit');
    });

    // 后台审核（T2，仅 admin）
    Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
        Route::post('/sellers/{seller}/review', [SellerController::class, 'review'])
            ->whereNumber('seller')->name('sellers.review');
    });

    // ---------------- 图鑑（T3）----------------
    Route::prefix('figures')->name('figures.')->group(function () {
        Route::get('/', [FigureController::class, 'index'])->name('index');
        // 详情支持 id 或 slug：站内跳转用 id，分享链接用 slug
        Route::get('/{figure}', [FigureController::class, 'show'])
            ->where('figure', '[0-9]+|[a-zA-Z0-9\-_]+')
            ->name('show');
    });

    // 图鑑管理 + 媒体上传（T3，仅 admin）
    Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
        Route::get('/figures', [AdminFigureController::class, 'index'])->name('figures.index');
        Route::post('/figures', [AdminFigureController::class, 'store'])->name('figures.store');
        Route::get('/figures/{figure}', [AdminFigureController::class, 'show'])
            ->whereNumber('figure')->name('figures.show');
        Route::put('/figures/{figure}', [AdminFigureController::class, 'update'])
            ->whereNumber('figure')->name('figures.update');
        Route::patch('/figures/{figure}/status', [AdminFigureController::class, 'updateStatus'])
            ->whereNumber('figure')->name('figures.status');
        Route::delete('/figures/{figure}', [AdminFigureController::class, 'destroy'])
            ->whereNumber('figure')->name('figures.destroy');

        Route::post('/media/presign', [AdminMediaController::class, 'presign'])->name('media.presign');
        Route::post('/media', [AdminMediaController::class, 'store'])->name('media.store');
    });

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
        Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{order}', [OrderController::class, 'show'])
            ->whereNumber('order')->name('orders.show');
        Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])
            ->whereNumber('order')->name('orders.cancel');

        // 卖家发货 / 买家确认收货：角色由服务层按 buyer_id / seller_id 判定
        Route::post('/orders/{order}/ship', [OrderController::class, 'ship'])
            ->whereNumber('order')->name('orders.ship');
        Route::post('/orders/{order}/confirm', [OrderController::class, 'confirm'])
            ->whereNumber('order')->name('orders.confirm');

        // 支付（T5）：发起后拿到通道拉起参数
        Route::post('/orders/{order}/payments', [PaymentController::class, 'store'])
            ->whereNumber('order')->name('payments.store');
        Route::get('/payments/{payment}', [PaymentController::class, 'show'])
            ->whereNumber('payment')->name('payments.show');

        Route::post('/auctions/{auction}/bids', [AuctionController::class, 'bid'])
            ->whereNumber('auction')->name('auctions.bid');
    });

    // ---------------- 卖家经营（T4）----------------
    Route::middleware(['auth:sanctum', 'seller'])->prefix('seller')->name('seller.')->group(function () {
        Route::get('/listings', [SellerListingController::class, 'index'])->name('listings.index');
        Route::post('/listings', [SellerListingController::class, 'store'])->name('listings.store');
        Route::put('/listings/{listing}', [SellerListingController::class, 'update'])
            ->whereNumber('listing')->name('listings.update');

        // 状态流转独立成动作路由：一次请求只做一件事，避免「编辑 + 上架」混在一个 PUT 里
        Route::post('/listings/{listing}/submit', [SellerListingController::class, 'submit'])
            ->whereNumber('listing')->name('listings.submit');
        Route::post('/listings/{listing}/delist', [SellerListingController::class, 'delist'])
            ->whereNumber('listing')->name('listings.delist');
        Route::post('/listings/{listing}/reopen', [SellerListingController::class, 'reopen'])
            ->whereNumber('listing')->name('listings.reopen');

        Route::post('/listings/{listing}/auctions', [SellerAuctionController::class, 'store'])
            ->whereNumber('listing')->name('auctions.store');
        Route::post('/auctions/{auction}/cancel', [SellerAuctionController::class, 'cancel'])
            ->whereNumber('auction')->name('auctions.cancel');
    });

    // 商品审核（T4，仅 admin）
    Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
        Route::get('/listings', [AdminListingController::class, 'index'])->name('listings.index');
        Route::post('/listings/{listing}/review', [AdminListingController::class, 'review'])
            ->whereNumber('listing')->name('listings.review');
    });

    // ---------------- 社群互动（T6）----------------
    // 收藏 / 关注 / 评价 / 通知：user_id 一律取自 Token，路由参数只表示「操作对象」
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me/favorites', [FavoriteController::class, 'index'])->name('favorites.index');
        Route::post('/me/favorites', [FavoriteController::class, 'store'])->name('favorites.store');
        Route::delete('/me/favorites/{targetType}/{targetId}', [FavoriteController::class, 'destroy'])
            ->where('targetType', 'listing|figure')->whereNumber('targetId')->name('favorites.destroy');

        Route::get('/me/follows', [FollowController::class, 'index'])->name('follows.index');
        Route::post('/sellers/{seller}/follow', [FollowController::class, 'store'])
            ->whereNumber('seller')->name('follows.store');
        Route::delete('/sellers/{seller}/follow', [FollowController::class, 'destroy'])
            ->whereNumber('seller')->name('follows.destroy');

        // 评价：买家评订单，卖家回评价（角色由服务层按 buyer_id / seller_id 判定）
        Route::post('/orders/{order}/reviews', [ReviewController::class, 'store'])
            ->whereNumber('order')->name('reviews.store');
        Route::post('/reviews/{review}/reply', [ReviewController::class, 'reply'])
            ->whereNumber('review')->name('reviews.reply');

        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])
            ->name('notifications.read_all');
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])
            ->whereNumber('notification')->name('notifications.read');
    });

    // 店铺评价墙（公开）：未登录可看，登录后附带「是否已关注」
    Route::get('/sellers/{seller}/reviews', [ReviewController::class, 'sellerIndex'])
        ->whereNumber('seller')->name('sellers.reviews');
});

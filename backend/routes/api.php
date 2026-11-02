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

use App\Http\Controllers\Api\Admin\AuctionController as AdminAuctionController;
use App\Http\Controllers\Api\Admin\CollectorController as AdminCollectorController;
use App\Http\Controllers\Api\Admin\FeeRuleController as AdminFeeRuleController;
use App\Http\Controllers\Api\Admin\FigureController as AdminFigureController;
use App\Http\Controllers\Api\Admin\ListingController as AdminListingController;
use App\Http\Controllers\Api\Admin\MediaController as AdminMediaController;
use App\Http\Controllers\Api\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\Admin\PostController as AdminPostController;
use App\Http\Controllers\Api\Admin\RefundController as AdminRefundController;
use App\Http\Controllers\Api\AuctionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BroadcastAuthController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\FigureController;
use App\Http\Controllers\Api\FollowController;
use App\Http\Controllers\Api\IdentityController;
use App\Http\Controllers\Api\ListingController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\Api\RealtimeController;
use App\Http\Controllers\Api\RefundController;
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

    // ---------------- 实时推送（T6）----------------
    // 端点配置公开下发（只含公开 app key）；频道鉴权必须带 Token，与 HTTP 接口同一套身份
    Route::get('/realtime/config', [RealtimeController::class, 'config'])->name('realtime.config');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/broadcasting/auth', BroadcastAuthController::class)->name('broadcasting.auth');
    });

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
        Route::get('/sellers', [SellerController::class, 'adminIndex'])->name('sellers.index');
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

        // 行情（T9 读取）：多源概览 + 按天 K 线。只读，无公开写入端点
        Route::get('/{figure}/quotes', [QuoteController::class, 'summary'])
            ->whereNumber('figure')->name('quotes');
        Route::get('/{figure}/kline', [QuoteController::class, 'kline'])
            ->whereNumber('figure')->name('kline');
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
        // 沙箱模拟支付（非沙箱通道会明确报错，不会静默改状态）
        Route::post('/payments/{payment}/sandbox-pay', [PaymentController::class, 'sandboxPay'])
            ->whereNumber('payment')->name('payments.sandbox_pay');
        Route::get('/payments/{payment}', [PaymentController::class, 'show'])
            ->whereNumber('payment')->name('payments.show');

        Route::post('/auctions/{auction}/bids', [AuctionController::class, 'bid'])
            ->whereNumber('auction')->name('auctions.bid');

        // 售后（T4）：买家申请退款 + 我的退款单；实际退钱在后台审核通过时发生
        Route::post('/orders/{order}/refunds', [RefundController::class, 'store'])
            ->whereNumber('order')->name('refunds.store');
        Route::get('/me/refunds', [RefundController::class, 'index'])->name('refunds.index');
    });

    // 卖家商品图上传（T4）：卖家上架必须带封面，此前只有 admin 有上传入口
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/seller/media', [MediaController::class, 'storeListingImage'])->name('seller.media.store');
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

        // 购物车：加购不锁库，锁库只在下单事务里做
        Route::get('/me/cart', [CartController::class, 'index'])->name('cart.index');
        Route::post('/me/cart', [CartController::class, 'store'])->name('cart.store');
        Route::delete('/me/cart', [CartController::class, 'clear'])->name('cart.clear');
        Route::delete('/me/cart/{listing}', [CartController::class, 'destroy'])
            ->whereNumber('listing')->name('cart.destroy');

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

    // 店铺主页 + 评价墙（公开）：未登录可看，登录后附带「是否已关注」
    Route::get('/sellers/{seller}', [SellerController::class, 'publicShow'])
        ->whereNumber('seller')->name('sellers.show');
    Route::get('/sellers/{seller}/reviews', [ReviewController::class, 'sellerIndex'])
        ->whereNumber('seller')->name('sellers.reviews');

    // ---------------- 社群与私信（T6）----------------
    // 公开面：feed 与详情，只出「已过审且已发布」的帖子
    Route::get('/posts', [PostController::class, 'index'])->name('posts.index');
    Route::get('/posts/{post}', [PostController::class, 'show'])
        ->whereNumber('post')->name('posts.show');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/posts', [PostController::class, 'store'])->name('posts.store');
        // 我的帖子含待审/未通过，按 status 分 tab 展示
        Route::get('/me/posts', [PostController::class, 'mine'])->name('posts.mine');
        Route::delete('/posts/{post}', [PostController::class, 'destroy'])
            ->whereNumber('post')->name('posts.destroy');

        Route::get('/conversations', [ConversationController::class, 'index'])->name('conversations.index');
        Route::post('/conversations', [ConversationController::class, 'store'])->name('conversations.store');
        Route::get('/conversations/{conversation}/messages', [MessageController::class, 'index'])
            ->whereNumber('conversation')->name('messages.index');
        Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store'])
            ->whereNumber('conversation')->name('messages.store');
    });

    // 帖子人工审核（T6，仅 admin）：只处理 pending
    Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
        Route::get('/posts', [AdminPostController::class, 'index'])->name('posts.index');
        Route::post('/posts/{post}/review', [AdminPostController::class, 'review'])
            ->whereNumber('post')->name('posts.review');
    });

    // ---------------- 采集触发（T7，仅 admin，契约 §4）----------------
    Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/collector')->name('admin.collector.')->group(function () {
        Route::post('/crawl-figure', [AdminCollectorController::class, 'crawlFigure'])->name('figure');
        Route::post('/crawl-price', [AdminCollectorController::class, 'crawlPrice'])->name('price');
        Route::get('/job/{job}', [AdminCollectorController::class, 'job'])
            ->where('job', '[A-Za-z0-9\-_]{1,64}')->name('job');
    });

    // ---------------- 平台经营（T4/T8，仅 admin）----------------
    // 费率可配、退款可审、订单与竞价全平台可视：这三件事是「运营能自己解决问题」的前提
    Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
        Route::get('/fee-rules', [AdminFeeRuleController::class, 'index'])->name('fee_rules.index');
        Route::post('/fee-rules', [AdminFeeRuleController::class, 'store'])->name('fee_rules.store');
        Route::put('/fee-rules/{feeRule}', [AdminFeeRuleController::class, 'update'])
            ->whereNumber('feeRule')->name('fee_rules.update');
        Route::delete('/fee-rules/{feeRule}', [AdminFeeRuleController::class, 'destroy'])
            ->whereNumber('feeRule')->name('fee_rules.destroy');
        // 试算：后台改完规则立刻能看到「1 万块收多少」，不用真下单
        Route::get('/fee-quote', [AdminFeeRuleController::class, 'preview'])->name('fee_quote');

        Route::get('/refunds', [AdminRefundController::class, 'index'])->name('refunds.index');
        Route::post('/refunds/{refund}/review', [AdminRefundController::class, 'review'])
            ->whereNumber('refund')->name('refunds.review');

        Route::get('/orders', [AdminOrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{order}', [AdminOrderController::class, 'show'])
            ->whereNumber('order')->name('orders.show');
        Route::patch('/orders/{order}/remark', [AdminOrderController::class, 'remark'])
            ->whereNumber('order')->name('orders.remark');

        Route::get('/auctions', [AdminAuctionController::class, 'index'])->name('auctions.index');
        Route::get('/auctions/{auction}', [AdminAuctionController::class, 'show'])
            ->whereNumber('auction')->name('auctions.show');
        Route::post('/auctions/{auction}/cancel', [AdminAuctionController::class, 'cancel'])
            ->whereNumber('auction')->name('auctions.cancel');
    });
});

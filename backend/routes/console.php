<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 *
 * 定时任务（T1 提供骨架，具体业务动作由各域服务暴露）。
 * 生产由 Horizon + supervisor 跑 `php artisan schedule:work`；不允许在 web 请求里做定时扫描。
 */

use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Services\Trade\BiddingService;
use App\Services\Trade\OrderService;
use Illuminate\Support\Facades\Schedule;

// 超时未付款订单关闭并释放库存（每分钟）
Schedule::call(function (OrderService $orders): void {
    $orders->closeExpired(200);
})->name('goat:orders:close-expired')->withoutOverlapping()->everyMinute();

// 到点竞拍结束 + 结算（每分钟；逐条加锁，单条失败不影响批次）
Schedule::call(function (BiddingService $bidding): void {
    Auction::query()
        ->where('status', AuctionStatus::Active->value)
        ->where('end_at', '<=', now())
        ->orderBy('auction_id')
        ->limit(100)
        ->get()
        ->each(function (Auction $auction) use ($bidding): void {
            try {
                $bidding->finalize($auction);
            } catch (Throwable $e) {
                report($e);
            }
        });
})->name('goat:auctions:finalize')->withoutOverlapping()->everyMinute();

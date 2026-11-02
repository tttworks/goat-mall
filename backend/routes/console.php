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
use App\Models\Order;
use App\Services\Trade\BiddingService;
use App\Services\Trade\OrderCompletionService;
use App\Services\Trade\OrderService;
use Illuminate\Support\Facades\Schedule;

// 超时未付款订单关闭并释放库存（每分钟）
Schedule::call(function (OrderService $orders): void {
    $orders->closeExpired(200);
})->name('goat:orders:close-expired')->withoutOverlapping()->everyMinute();

// 发货后长期未确认收货：系统代买家确认并结算（每小时）。
// 买家可以一直不点，但担保款不能因此永久冻在平台；有未结退款单的订单会被 exclude。
Schedule::call(function (OrderService $orders, OrderCompletionService $completions): void {
    $days = (int) config('goat.trade.auto_confirm_days', 10);

    $orders->autoConfirmDue($days)->each(function (Order $order) use ($completions, $days): void {
        try {
            $completions->confirmByTimeout($order, $days);
        } catch (Throwable $e) {
            report($e);
        }
    });
})->name('goat:orders:auto-confirm')->withoutOverlapping()->hourly();

// 付款后长期未发货：催办双方（每天一次）。
// 只提醒不动资金——自动退款必须有人审，否则「已线下发货但没填单号」的卖家会货钱两空。
Schedule::call(function (OrderCompletionService $completions): void {
    $completions->nudgeUnshipped((int) config('goat.trade.unshipped_alert_days', 3));
})->name('goat:orders:nudge-unshipped')->withoutOverlapping()->dailyAt('10:30');

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

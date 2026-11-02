<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Figure;
use App\Models\Listing;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PricePoint;
use App\Models\Refund;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Trade\OrderCompletionService;
use App\Services\Trade\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 担保交易的超时兜底：发货后长期未确认 → 系统代确认并结算；付款后长期未发货 → 催办双方。
 *
 * 这一组守的是「钱不会被永久冻在平台」：买家不点确认不能让担保款无限期挂着，
 * 但买家正在申请退款时系统又不能替他把钱放出去；催办只提醒不动资金，
 * 且同一订单同一天只该有一条催办（调度重跑不能刷屏）。
 */
class TradeTimeoutTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function makeUser(string $username, string $role = 'buyer'): User
    {
        return User::query()->create([
            'username' => $username,
            'password' => 'Goat@2026',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function makeSeller(string $username = 'timeout_seller'): User
    {
        $user = $this->makeUser($username, 'seller');

        SellerProfile::query()->create([
            'user_id' => $user->user_id,
            'shop_name' => 'GOAT 超时店 '.$username,
            'status' => 'active',
            'identity_status' => 'approved',
            'applied_at' => now(),
            'activated_at' => now(),
        ]);

        return $user;
    }

    private function makeFigure(): Figure
    {
        return Figure::query()->create([
            'slug' => 'hatsune-miku-timeout-7',
            'name' => '初音未来 交响乐 1/7',
            'maker' => 'Good Smile',
            'category' => 'scale_figure',
            'status' => 'published',
            'cover_key' => null,
        ]);
    }

    private function makeListing(User $seller, Figure $figure): Listing
    {
        return Listing::query()->create([
            'seller_id' => $seller->user_id,
            'figure_id' => $figure->figure_id,
            'title' => '初音未来 交响乐 1/7 全新未拆',
            'condition_enum' => 'new',
            'price' => '1280.00',
            'currency' => 'CNY',
            'status' => 'sold',
            'stock' => 0,
            'published_at' => now(),
        ]);
    }

    private function makeOrder(
        User $buyer,
        User $seller,
        Listing $listing,
        OrderStatus $status,
        ?Carbon $paidAt = null,
        ?Carbon $shippedAt = null
    ): Order {
        $this->seq++;

        $order = Order::query()->create([
            'order_no' => 'GOT'.str_pad((string) $this->seq, 9, '0', STR_PAD_LEFT),
            'idempotency_key' => 'timeout-'.$this->seq,
            'buyer_id' => $buyer->user_id,
            'seller_id' => $seller->user_id,
            'status' => $status,
            'escrow_status' => 'frozen',
            'goods_amount' => '1280.00',
            'shipping_amount' => '0.00',
            'fee_amount' => '0.00',
            'payable_amount' => '1280.00',
            'currency' => 'CNY',
            'paid_at' => $paidAt,
            'shipped_at' => $shippedAt,
            'expire_at' => $paidAt?->copy()->addMinutes(30),
        ]);

        OrderItem::query()->create([
            'order_id' => $order->order_id,
            'listing_id' => $listing->listing_id,
            'figure_id' => $listing->figure_id,
            'title_snapshot' => $listing->title,
            'quantity' => 1,
            'unit_price' => '1280.00',
            'subtotal' => '1280.00',
        ]);

        Payment::query()->create([
            'payment_no' => 'PYT'.str_pad((string) $this->seq, 9, '0', STR_PAD_LEFT),
            'order_id' => $order->order_id,
            'idempotency_key' => 'pay-timeout-'.$this->seq,
            'channel' => 'sandbox',
            'status' => PaymentStatus::Frozen,
            'amount' => '1280.00',
            'currency' => 'CNY',
            'paid_at' => $paidAt ?? now(),
            'frozen_at' => $paidAt ?? now(),
        ]);

        return $order;
    }

    private function makeRefund(Order $order, RefundStatus $status): Refund
    {
        return Refund::query()->create([
            'refund_no' => Refund::nextNo(),
            'order_id' => $order->order_id,
            'buyer_id' => $order->buyer_id,
            'amount' => '1280.00',
            'currency' => 'CNY',
            'reason' => '商品与描述不符',
            'status' => $status,
        ]);
    }

    public function test_timed_out_shipment_is_auto_confirmed_and_settled(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeUser('timeout_buyer');
        $listing = $this->makeListing($seller, $this->makeFigure());
        $order = $this->makeOrder(
            $buyer,
            $seller,
            $listing,
            OrderStatus::SellerShipped,
            now()->subDays(12),
            now()->subDays(11)
        );

        $due = $this->app->make(OrderService::class)->autoConfirmDue(10);
        $this->assertSame([$order->order_id], $due->pluck('order_id')->all());

        $completed = $this->app->make(OrderCompletionService::class)->confirmByTimeout($order, 10);

        $this->assertNotNull($completed);
        $this->assertSame(OrderStatus::Completed, $completed->status);
        $this->assertSame('released', $completed->escrow_status);
        $this->assertNotNull($completed->confirmed_at);

        // 担保款必须真的解冻：状态到了 completed 而钱没结算是最坏的结果
        $this->assertSame(
            PaymentStatus::Settled,
            Payment::query()->where('order_id', $order->order_id)->firstOrFail()->status
        );

        // 成交价回写行情，成交时间取确认收货时间
        $this->assertSame(1, PricePoint::query()->where('figure_id', $listing->figure_id)->count());

        // 买卖双方各一条通知；卖家那条与手动确认共用去重键
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $buyer->user_id,
            'type' => 'order.completed',
            'dedupe_key' => 'order:'.$order->order_id.':auto-completed',
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $seller->user_id,
            'type' => 'order.completed',
            'dedupe_key' => 'order:'.$order->order_id.':completed',
        ]);
    }

    public function test_auto_confirm_skips_orders_with_open_refund(): void
    {
        $seller = $this->makeSeller('timeout_refund_seller');
        $buyer = $this->makeUser('timeout_refund_buyer');
        $listing = $this->makeListing($seller, $this->makeFigure());
        $refunding = $this->makeOrder(
            $buyer,
            $seller,
            $listing,
            OrderStatus::SellerShipped,
            now()->subDays(12),
            now()->subDays(11)
        );
        $this->makeRefund($refunding, RefundStatus::Pending);

        // 同一批里另有一单退款已成功（终态）：不构成阻塞，仍应被扫到
        $refunded = $this->makeOrder(
            $buyer,
            $seller,
            $listing,
            OrderStatus::SellerShipped,
            now()->subDays(12),
            now()->subDays(11)
        );
        $this->makeRefund($refunded, RefundStatus::Refunded);

        $ids = $this->app->make(OrderService::class)->autoConfirmDue(10)->pluck('order_id')->all();

        $this->assertNotContains($refunding->order_id, $ids);
        $this->assertContains($refunded->order_id, $ids);
    }

    public function test_recent_shipment_is_not_due_and_second_run_is_noop(): void
    {
        $seller = $this->makeSeller('timeout_recent_seller');
        $buyer = $this->makeUser('timeout_recent_buyer');
        $listing = $this->makeListing($seller, $this->makeFigure());
        $order = $this->makeOrder(
            $buyer,
            $seller,
            $listing,
            OrderStatus::SellerShipped,
            now()->subDays(2),
            now()->subDays(1)
        );

        $this->assertCount(0, $this->app->make(OrderService::class)->autoConfirmDue(10));
        $this->assertSame(OrderStatus::SellerShipped, $order->refresh()->status);

        // 已完成的订单再被扫到（并发/重跑）时返回 null，不重复结算、不重复通知
        $order->transitTo(OrderStatus::BuyerConfirmed, ['confirmed_at' => now()]);
        $order->transitTo(OrderStatus::Completed, ['escrow_status' => 'released']);

        $this->assertNull(
            $this->app->make(OrderCompletionService::class)->confirmByTimeout($order->refresh(), 10)
        );
        $this->assertSame(
            PaymentStatus::Frozen,
            Payment::query()->where('order_id', $order->order_id)->firstOrFail()->status
        );
    }

    public function test_unshipped_orders_are_nudged_once_per_day(): void
    {
        $seller = $this->makeSeller('timeout_nudge_seller');
        $buyer = $this->makeUser('timeout_nudge_buyer');
        $listing = $this->makeListing($seller, $this->makeFigure());

        $stale = $this->makeOrder($buyer, $seller, $listing, OrderStatus::Paid, now()->subDays(4));
        $fresh = $this->makeOrder($buyer, $seller, $listing, OrderStatus::Paid, now()->subDay());
        $shipped = $this->makeOrder(
            $buyer,
            $seller,
            $listing,
            OrderStatus::SellerShipped,
            now()->subDays(4),
            now()->subDays(1)
        );

        $ids = $this->app->make(OrderService::class)->paidUnshippedBefore(3)->pluck('order_id')->all();
        $this->assertContains($stale->order_id, $ids);
        $this->assertNotContains($fresh->order_id, $ids);
        $this->assertNotContains($shipped->order_id, $ids);

        $completions = $this->app->make(OrderCompletionService::class);
        $this->assertSame(1, $completions->nudgeUnshipped(3));

        $this->assertSame(2, UserNotification::query()->where('type', 'order.unshipped')->count());
        $this->assertSame(1, UserNotification::query()->where('user_id', $buyer->user_id)->count());
        $this->assertSame(1, UserNotification::query()->where('user_id', $seller->user_id)->count());

        // 同一天重跑调度：扫描照旧，但去重键挡住重复推送
        $this->assertSame(1, $completions->nudgeUnshipped(3));
        $this->assertSame(2, UserNotification::query()->where('type', 'order.unshipped')->count());
    }
}

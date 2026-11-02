<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace Tests\Feature;

use App\Models\Auction;
use App\Models\FeeRule;
use App\Models\Figure;
use App\Models\Listing;
use App\Models\Order;
use App\Models\PricePoint;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 交易收尾（T4/T8/T9）：退款售后、费率可配、后台订单与竞价、行情聚合。
 *
 * 这一组测试守的是「上线前能不能自己救火」：
 * 买家要退款能不能走完、运营改费率会不会动到历史单、后台能不能看见全平台订单、
 * 撤拍会不会把钱搞乱、行情空库会不会崩。
 */
class TradeClosureTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $username, string $role = 'buyer'): User
    {
        return User::query()->create([
            'username' => $username,
            'password' => 'Goat@2026',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function makeSeller(string $username = 'goat_seller'): User
    {
        $user = $this->makeUser($username, 'seller');

        SellerProfile::query()->create([
            'user_id' => $user->user_id,
            'shop_name' => 'GOAT 担保店 '.$username,
            'status' => 'active',
            'identity_status' => 'approved',
            'applied_at' => now(),
            'activated_at' => now(),
        ]);

        return $user;
    }

    private function makeListing(User $seller, string $price = '1000.00', ?int $figureId = null): Listing
    {
        return Listing::query()->create([
            'seller_id' => $seller->user_id,
            'figure_id' => $figureId,
            'title' => '初音未来 交响乐 1/7 全新未拆',
            'condition_enum' => 'new',
            'price' => $price,
            'currency' => 'CNY',
            'status' => 'active',
            'stock' => 1,
            'published_at' => now(),
        ]);
    }

    private function seedFeeRule(): void
    {
        FeeRule::query()->create([
            'scope' => 'global',
            'percent_bip' => 500,
            'bearer' => 'seller',
            'currency' => 'CNY',
            'is_active' => true,
        ]);
    }

    /** 走完「下单 → 发起支付 → 渠道回调成功」，返回 order_id */
    private function paidOrder(User $buyer, Listing $listing, string $suffix): int
    {
        Sanctum::actingAs($buyer);

        $created = $this->withHeader('Idempotency-Key', 'order-'.$suffix)
            ->postJson('/api/v1/orders', ['listing_id' => $listing->listing_id])
            ->assertCreated();

        $orderId = (int) $created->json('data.order_id');
        // 回调金额必须等于订单应付金额（费率承担方可能是买家，不等于商品价）
        $payable = (string) $created->json('data.payable_amount');

        $paymentNo = (string) $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'idempotency_key' => 'pay-'.$suffix,
        ])->assertCreated()->json('data.payment_no');

        $payload = [
            'payment_no' => $paymentNo,
            'provider_trade_no' => 'SBX-'.$suffix,
            'amount' => $payable,
            'succeeded' => true,
            'paid_at' => Carbon::now()->toIso8601String(),
        ];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $timestamp = (string) Carbon::now()->getTimestamp();

        $this->call(
            'POST',
            '/api/v1/payments/notify',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_PAYMENT_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, (string) config('goat.payment.sandbox.secret')),
                'HTTP_X_PAYMENT_TIMESTAMP' => $timestamp,
            ],
            $body
        )->assertOk();

        return $orderId;
    }

    private function publishedFigure(User $admin): int
    {
        Sanctum::actingAs($admin);

        $figureId = (int) $this->postJson('/api/v1/admin/figures', [
            'name' => '初音未来 交响乐 1/7',
            'maker' => 'Good Smile Company',
            'series' => 'Character Vocal Series 01',
            'scale' => '1/7',
            'category' => 'scale_figure',
            'release_date' => '2024-12-20',
            'cover_key' => 'figures/2026/11/cover.jpg',
        ])->assertCreated()->json('data.figure_id');

        $this->patchJson("/api/v1/admin/figures/{$figureId}/status", ['status' => 'published'])
            ->assertOk();

        return $figureId;
    }

    public function test_refund_flow_apply_then_admin_approve_moves_money_back(): void
    {
        $this->seedFeeRule();
        $seller = $this->makeSeller();
        $buyer = $this->makeUser('goat_buyer');
        $admin = $this->makeUser('goat_admin', 'admin');

        $orderId = $this->paidOrder($buyer, $this->makeListing($seller), 'rf1');

        Sanctum::actingAs($buyer);
        $refundId = (int) $this->postJson("/api/v1/orders/{$orderId}/refunds", ['reason' => '收到时外盒有压痕'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->json('data.refund_id');

        // 待审核期间钱仍在冻结：退款申请不能自己给自己退钱
        $this->assertDatabaseHas('orders', ['order_id' => $orderId, 'status' => 'paid']);
        $this->assertDatabaseHas('payments', ['order_id' => $orderId, 'status' => 'frozen']);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/refunds/{$refundId}/review", ['approve' => true, 'remark' => '已核实，全额退款'])
            ->assertOk()
            ->assertJsonPath('data.status', 'refunded');

        $this->assertDatabaseHas('refunds', ['refund_id' => $refundId, 'status' => 'refunded']);
        $this->assertDatabaseHas('payments', ['order_id' => $orderId, 'status' => 'refunded']);
        $this->assertDatabaseHas('orders', ['order_id' => $orderId, 'status' => 'refunded', 'escrow_status' => 'refunded']);

        // 连点审核不二次退款：仍是 refunded，且只有一条退款单
        $this->postJson("/api/v1/admin/refunds/{$refundId}/review", ['approve' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'refunded');
        $this->assertSame(1, \App\Models\Refund::query()->where('order_id', $orderId)->count());
    }

    public function test_refund_reject_requires_remark_and_keeps_order_paid(): void
    {
        $this->seedFeeRule();
        $seller = $this->makeSeller();
        $buyer = $this->makeUser('goat_buyer2');
        $admin = $this->makeUser('goat_admin2', 'admin');

        $orderId = $this->paidOrder($buyer, $this->makeListing($seller), 'rf2');

        Sanctum::actingAs($buyer);
        $refundId = (int) $this->postJson("/api/v1/orders/{$orderId}/refunds", ['reason' => '不想要了'])
            ->assertCreated()->json('data.refund_id');

        Sanctum::actingAs($admin);
        // 驳回必须写理由：买家会看到这句话
        $this->postJson("/api/v1/admin/refunds/{$refundId}/review", ['approve' => false])
            ->assertStatus(422);

        $this->postJson("/api/v1/admin/refunds/{$refundId}/review", [
            'approve' => false,
            'remark' => '已与买家沟通，商品已发货',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseHas('orders', ['order_id' => $orderId, 'status' => 'paid']);
        $this->assertDatabaseHas('payments', ['order_id' => $orderId, 'status' => 'frozen']);
    }

    public function test_fee_rules_are_configurable_and_protected_once_referenced(): void
    {
        $admin = $this->makeUser('goat_admin3', 'admin');
        Sanctum::actingAs($admin);

        $ruleId = (int) $this->postJson('/api/v1/admin/fee-rules', [
            'scope' => 'global',
            'percent_bip' => 800,
            'cap_amount' => '50.00',
            'bearer' => 'buyer',
            'remark' => '推广期 0.8% 封顶 50',
        ])->assertCreated()
            ->assertJsonPath('data.percent_label', '8%')
            ->json('data.fee_rule_id');

        // 试算：1 万 → 80，但封顶 50（买家承担）
        $this->getJson('/api/v1/admin/fee-quote?goods_amount=10000')
            ->assertOk()
            ->assertJsonPath('data.fee_amount', '50.00')
            ->assertJsonPath('data.bearer', 'buyer');

        // 免费期：0 合法
        $this->postJson('/api/v1/admin/fee-rules', [
            'scope' => 'seller',
            'scope_value' => '42',
            'percent_bip' => 0,
            'bearer' => 'seller',
        ])->assertCreated();

        // 规模值缺失必须报错（否则会静默变成一条永远匹配不到的规则）
        $this->postJson('/api/v1/admin/fee-rules', [
            'scope' => 'category',
            'percent_bip' => 500,
            'bearer' => 'seller',
        ])->assertStatus(422);

        // 被历史订单引用 → 只停用不删除
        $seller = $this->makeSeller('goat_seller3');
        $buyer = $this->makeUser('goat_buyer3');
        $orderId = $this->paidOrder($buyer, $this->makeListing($seller, '100.00'), 'fee3');
        Order::query()->whereKey($orderId)->update(['fee_rule_id' => $ruleId]);

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/v1/admin/fee-rules/{$ruleId}")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('fee_rules', ['fee_rule_id' => $ruleId, 'is_active' => false]);
    }

    public function test_admin_order_console_lists_platform_orders_and_allows_remark(): void
    {
        $this->seedFeeRule();
        $seller = $this->makeSeller('goat_seller4');
        $buyer = $this->makeUser('goat_buyer4');
        $admin = $this->makeUser('goat_admin4', 'admin');

        $orderId = $this->paidOrder($buyer, $this->makeListing($seller, '888.00'), 'adm4');

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/orders?status=paid')
            ->assertOk()
            ->assertJsonPath('data.0.order_id', $orderId)
            ->assertJsonPath('data.0.buyer.user_id', $buyer->user_id)
            ->assertJsonStructure(['data' => [['items', 'buyer', 'seller', 'status_label']], 'meta' => ['statuses']]);

        $this->getJson("/api/v1/admin/orders/{$orderId}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['payments', 'refunds', 'fee_snapshot']]);

        $this->patchJson("/api/v1/admin/orders/{$orderId}/remark", ['remark' => '买家申请改地址'])
            ->assertOk();

        $this->assertDatabaseHas('orders', ['order_id' => $orderId, 'remark' => '买家申请改地址']);

        // 普通买家看不到后台
        Sanctum::actingAs($buyer);
        $this->getJson('/api/v1/admin/orders')->assertForbidden();
    }

    public function test_admin_cannot_cancel_auction_with_bids(): void
    {
        $seller = $this->makeSeller('goat_seller5');
        $admin = $this->makeUser('goat_admin5', 'admin');
        $listing = $this->makeListing($seller, '2000.00');

        $auction = Auction::query()->create([
            'listing_id' => $listing->listing_id,
            'seller_id' => $seller->user_id,
            'status' => 'active',
            'start_price' => '2000.00',
            'min_increment' => '50.00',
            'current_price' => '2100.00',
            'bid_count' => 3,
            'start_at' => Carbon::now()->subHour(),
            'end_at' => Carbon::now()->addHour(),
            'original_end_at' => Carbon::now()->addHour(),
        ]);

        Sanctum::actingAs($admin);
        // 有出价 = 有保证金在飞，直接撤会把钱搞乱
        $this->postJson("/api/v1/admin/auctions/{$auction->auction_id}/cancel", ['reason' => '卖家要求'])
            ->assertStatus(422);

        $auction->update(['bid_count' => 0]);
        $this->postJson("/api/v1/admin/auctions/{$auction->auction_id}/cancel", ['reason' => '卖家要求'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_quotes_aggregate_multi_source_and_confirm_writes_internal_point(): void
    {
        $admin = $this->makeUser('goat_admin6', 'admin');
        $seller = $this->makeSeller('goat_seller6');
        $buyer = $this->makeUser('goat_buyer6');

        $figureId = $this->publishedFigure($admin);
        $listing = $this->makeListing($seller, '3000.00', $figureId);

        // 空库也要能出页面：available=false 而不是 500
        $this->getJson("/api/v1/figures/{$figureId}/quotes")
            ->assertOk()
            ->assertJsonPath('data.headline.available', false);

        PricePoint::query()->create([
            'figure_id' => $figureId,
            'source' => 'mercari',
            'currency' => 'CNY',
            'price' => '5200.00',
            'sold_at' => Carbon::now()->subDays(10),
        ]);
        PricePoint::query()->create([
            'figure_id' => $figureId,
            'source' => 'mercari',
            'currency' => 'CNY',
            'price' => '4800.00',
            'sold_at' => Carbon::now()->subDays(9),
        ]);
        // 钓鱼价：中位数必须把它挡在外面（均值会被拉到 3.4k 附近，中位数不会）
        PricePoint::query()->create([
            'figure_id' => $figureId,
            'source' => 'mercari',
            'currency' => 'CNY',
            'price' => '1.00',
            'sold_at' => Carbon::now()->subDays(8),
        ]);

        $quotes = $this->getJson("/api/v1/figures/{$figureId}/quotes")->assertOk();
        $mercari = collect($quotes->json('data.sources'))->firstWhere('source', 'mercari');
        $this->assertSame(3, $mercari['count']);
        $this->assertSame('4800.00', $mercari['median_price']);

        // K 线默认看站内成交（internal）；外部源要显式指定
        $kline = $this->getJson("/api/v1/figures/{$figureId}/kline?days=30&source=mercari")->assertOk();
        $this->assertCount(3, $kline->json('data.candles'));
        $this->assertContains('mercari', $kline->json('data.available_sources'));

        // 确认收货 → 站内成交回写行情（供后续多源对比）
        $orderId = $this->paidOrder($buyer, $listing, 'q6');

        Sanctum::actingAs($seller);
        $this->postJson("/api/v1/orders/{$orderId}/ship", [
            'shipping_company' => 'SF',
            'tracking_no' => 'SF-Q6-0001',
        ])->assertOk();

        Sanctum::actingAs($buyer);
        $this->postJson("/api/v1/orders/{$orderId}/confirm")->assertOk();

        $this->assertDatabaseHas('price_points', [
            'figure_id' => $figureId,
            'source' => 'internal',
            'price' => '3000.00',
        ]);

        // 站内成交出现后 headline 才有主价签
        $this->getJson("/api/v1/figures/{$figureId}/quotes")
            ->assertOk()
            ->assertJsonPath('data.headline.available', true)
            ->assertJsonPath('data.headline.sample', 1)
            ->assertJsonPath('data.headline.median', '3000.00');
    }
}

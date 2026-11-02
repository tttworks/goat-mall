<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace Tests\Feature;

use App\Models\FeeRule;
use App\Models\Listing;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 担保交易全链路（T5）：下单 → 发起支付 → 渠道回调 → 冻结 → 发货 → 确认收货 → 分账。
 *
 * 重点验证三件事：
 * 1. 回调必须验签且金额一致，篡改一个数字就拒；
 * 2. 支付与订单状态同步跃迁（payment=frozen 时 order=paid）；
 * 3. 确认收货后资金才解冻（settled），未确认前卖家拿不到钱。
 */
class PaymentFlowTest extends TestCase
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

    private function makeListing(User $seller, string $price = '1000.00'): Listing
    {
        return Listing::query()->create([
            'seller_id' => $seller->user_id,
            'title' => '初音未来 交响乐 1/7 全新未拆',
            'condition_enum' => 'new',
            'price' => $price,
            'currency' => 'CNY',
            'status' => 'active',
            'stock' => 1,
            'published_at' => now(),
        ]);
    }

    /** 平台抽 5%，锁定到「发起支付时」的费率，后续改费率不影响在途订单 */
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

    private function placeOrder(User $buyer, Listing $listing, string $key): int
    {
        Sanctum::actingAs($buyer);

        return (int) $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/orders', ['listing_id' => $listing->listing_id])
            ->assertCreated()
            ->json('data.order_id');
    }

    /** 模拟渠道回调：签名覆盖「时间戳 + 原文」，与本项目 SandboxChannel 的验签规则一致 */
    private function notify(array $payload): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $timestamp = (string) Carbon::now()->getTimestamp();
        $secret = (string) config('goat.payment.sandbox.secret');
        // 拼接规则必须与 SandboxChannel 完全一致，否则测试会「假通过」
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return $this->call(
            'POST',
            '/api/v1/payments/notify',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_PAYMENT_SIGNATURE' => $signature,
                'HTTP_X_PAYMENT_TIMESTAMP' => $timestamp,
            ],
            $body
        );
    }

    public function test_escrow_end_to_end_settles_only_after_confirm(): void
    {
        $this->seedFeeRule();

        $seller = $this->makeSeller();
        $buyer = $this->makeUser('goat_buyer');
        $listing = $this->makeListing($seller);

        $orderId = $this->placeOrder($buyer, $listing, 'order-key-1');

        $payment = $this->postJson("/api/v1/orders/{$orderId}/payments", ['idempotency_key' => 'pay-key-1'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $paymentNo = (string) $payment->json('data.payment_no');

        $this->notify([
            'payment_no' => $paymentNo,
            'provider_trade_no' => 'SBX-20261103-0001',
            'amount' => '1000.00',
            'succeeded' => true,
            'paid_at' => Carbon::now()->toIso8601String(),
        ])->assertOk()->assertJsonPath('code', 'SUCCESS');

        // 钱到了但还不能给卖家：支付单冻结、订单已付款
        $this->assertDatabaseHas('payments', ['payment_no' => $paymentNo, 'status' => 'frozen']);
        $this->assertDatabaseHas('orders', ['order_id' => $orderId, 'status' => 'paid', 'escrow_status' => 'frozen']);

        Sanctum::actingAs($seller);
        $this->postJson("/api/v1/orders/{$orderId}/ship", [
            'shipping_company' => 'SF',
            'tracking_no' => 'SF1234567890',
        ])->assertOk()->assertJsonPath('data.status', 'seller_shipped');

        Sanctum::actingAs($buyer);
        $this->postJson("/api/v1/orders/{$orderId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        // 确认收货后才分账
        $this->assertDatabaseHas('payments', ['payment_no' => $paymentNo, 'status' => 'settled']);
    }

    public function test_notify_with_tampered_amount_is_rejected(): void
    {
        $this->seedFeeRule();

        $seller = $this->makeSeller();
        $buyer = $this->makeUser('goat_buyer2');
        $listing = $this->makeListing($seller);

        $orderId = $this->placeOrder($buyer, $listing, 'order-key-2');

        $paymentNo = (string) $this->postJson("/api/v1/orders/{$orderId}/payments", ['idempotency_key' => 'pay-key-2'])
            ->assertCreated()
            ->json('data.payment_no');

        // 签名合法，但金额被改：必须拒，且订单不得进入已付款
        $this->notify([
            'payment_no' => $paymentNo,
            'provider_trade_no' => 'SBX-20261103-0002',
            'amount' => '0.01',
            'succeeded' => true,
            'paid_at' => Carbon::now()->toIso8601String(),
        ])->assertStatus(422);

        $this->assertDatabaseHas('payments', ['payment_no' => $paymentNo, 'status' => 'pending']);
        $this->assertDatabaseHas('orders', ['order_id' => $orderId, 'status' => 'pending_payment']);
    }

    public function test_notify_with_bad_signature_is_rejected(): void
    {
        $this->seedFeeRule();

        $seller = $this->makeSeller();
        $buyer = $this->makeUser('goat_buyer3');
        $listing = $this->makeListing($seller);

        $orderId = $this->placeOrder($buyer, $listing, 'order-key-3');

        $paymentNo = (string) $this->postJson("/api/v1/orders/{$orderId}/payments", ['idempotency_key' => 'pay-key-3'])
            ->assertCreated()
            ->json('data.payment_no');

        $body = json_encode([
            'payment_no' => $paymentNo,
            'provider_trade_no' => 'SBX-20261103-0003',
            'amount' => '1000.00',
            'succeeded' => true,
        ]);

        $this->call('POST', '/api/v1/payments/notify', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYMENT_SIGNATURE' => str_repeat('0', 64),
            'HTTP_X_PAYMENT_TIMESTAMP' => (string) Carbon::now()->getTimestamp(),
        ], $body)->assertStatus(400);

        $this->assertDatabaseHas('payments', ['payment_no' => $paymentNo, 'status' => 'pending']);
    }

    public function test_initiating_payment_twice_returns_same_payment(): void
    {
        $this->seedFeeRule();

        $seller = $this->makeSeller();
        $buyer = $this->makeUser('goat_buyer4');
        $listing = $this->makeListing($seller);

        $orderId = $this->placeOrder($buyer, $listing, 'order-key-4');

        $first = (string) $this->postJson("/api/v1/orders/{$orderId}/payments", ['idempotency_key' => 'pay-key-4'])
            ->assertCreated()
            ->json('data.payment_no');

        $second = (string) $this->postJson("/api/v1/orders/{$orderId}/payments", ['idempotency_key' => 'pay-key-4'])
            ->assertCreated()
            ->json('data.payment_no');

        $this->assertSame($first, $second);
        $this->assertSame(1, Payment::query()->where('order_id', $orderId)->count());
    }

    public function test_other_buyer_cannot_pay_or_view_the_order(): void
    {
        $this->seedFeeRule();

        $seller = $this->makeSeller();
        $buyer = $this->makeUser('goat_buyer5');
        $stranger = $this->makeUser('goat_stranger');
        $listing = $this->makeListing($seller);

        $orderId = $this->placeOrder($buyer, $listing, 'order-key-5');

        Sanctum::actingAs($stranger);

        $this->postJson("/api/v1/orders/{$orderId}/payments", ['idempotency_key' => 'pay-key-5'])
            ->assertStatus(422);

        $this->getJson("/api/v1/orders/{$orderId}")->assertStatus(403);
        $this->assertDatabaseHas('orders', ['order_id' => $orderId, 'status' => 'pending_payment']);
    }

    /**
     * 沙箱模拟支付：演示/联调走的也必须是「验签 + 金额比对」的正规链路，
     * 否则等上线换真渠道时才暴露回调缺陷。
     */
    public function test_sandbox_pay_goes_through_real_notify_pipeline(): void
    {
        $this->seedFeeRule();

        $seller = $this->makeSeller();
        $buyer = $this->makeUser('goat_buyer4');
        $other = $this->makeUser('goat_other4');
        $listing = $this->makeListing($seller);

        $orderId = $this->placeOrder($buyer, $listing, 'order-key-4');

        $paymentId = (int) $this->postJson("/api/v1/orders/{$orderId}/payments", ['idempotency_key' => 'pay-key-4'])
            ->assertCreated()
            ->json('data.payment_id');

        // 越权：别人不能替买家付款
        Sanctum::actingAs($other);
        $this->postJson("/api/v1/payments/{$paymentId}/sandbox-pay")->assertStatus(422);

        Sanctum::actingAs($buyer);
        $this->postJson("/api/v1/payments/{$paymentId}/sandbox-pay")
            ->assertOk()
            ->assertJsonPath('data.status', 'frozen');

        // 幂等方向：已付款的支付单不能再付一次
        $this->postJson("/api/v1/payments/{$paymentId}/sandbox-pay")->assertStatus(422);

        $this->assertDatabaseHas('orders', ['order_id' => $orderId, 'status' => 'paid', 'escrow_status' => 'frozen']);
        $this->assertSame(1, Payment::query()->where('order_id', $orderId)->count());

        // 双方各一条通知（重复回调靠 dedupe_key 吸掉）
        $this->assertSame(1, UserNotification::query()
            ->where('user_id', $buyer->user_id)->where('type', 'order.paid')->count());
        $this->assertSame(1, UserNotification::query()
            ->where('user_id', $seller->user_id)->where('type', 'order.pending_ship')->count());
    }
}

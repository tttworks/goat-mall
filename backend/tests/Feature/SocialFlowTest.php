<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Review;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 互动层（T6）：收藏 / 关注 / 评价 / 通知中心。
 *
 * 重点验证四条业务口径：
 * 1. 收藏与关注幂等（前端连点不产生重复数据、不弹错）；
 * 2. 评价只能由买家对已完成订单发起，一单一评（重复 409）；
 * 3. 卖家只能回复一次自己的评价，回复后买家收到通知；
 * 4. 通知只发一次：同一事件重复推送被 dedupe_key 吸收。
 */
class SocialFlowTest extends TestCase
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
            'shop_name' => 'GOAT 互动店 '.$username,
            'status' => 'active',
            'identity_status' => 'approved',
            'applied_at' => now(),
            'activated_at' => now(),
        ]);

        return $user;
    }

    private function makeListing(User $seller): Listing
    {
        return Listing::query()->create([
            'seller_id' => $seller->user_id,
            'title' => '初音未来 交响乐 1/7 全新未拆',
            'condition_enum' => 'new',
            'price' => '1280.00',
            'currency' => 'CNY',
            'status' => 'active',
            'stock' => 1,
            'published_at' => now(),
        ]);
    }

    /** 直接落一张已完成订单：评价只关心「完成后可评」，不需要重跑支付链路 */
    private function makeOrder(User $buyer, User $seller, Listing $listing, string $status = 'completed'): Order
    {
        $order = Order::query()->create([
            'order_no' => 'GO'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'idempotency_key' => 'order-'.uniqid(),
            'buyer_id' => $buyer->user_id,
            'seller_id' => $seller->user_id,
            'status' => $status,
            'escrow_status' => $status === 'completed' ? 'released' : 'frozen',
            'goods_amount' => '1280.00',
            'shipping_amount' => '0.00',
            'fee_amount' => '0.00',
            'payable_amount' => '1280.00',
            'currency' => 'CNY',
        ]);

        OrderItem::query()->create([
            'order_id' => $order->order_id,
            'listing_id' => $listing->listing_id,
            'title_snapshot' => $listing->title,
            'quantity' => 1,
            'unit_price' => '1280.00',
            'subtotal' => '1280.00',
        ]);

        return $order;
    }

    public function test_favorite_is_idempotent_and_lists_target_summary(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeUser('favorite_buyer');
        $listing = $this->makeListing($seller);

        Sanctum::actingAs($buyer);

        $this->postJson('/api/v1/me/favorites', [
            'target_type' => 'listing',
            'target_id' => $listing->listing_id,
        ])->assertCreated()->assertJsonPath('data.favorited', true);

        // 重复收藏：幂等返回 200，不新建记录
        $this->postJson('/api/v1/me/favorites', [
            'target_type' => 'listing',
            'target_id' => $listing->listing_id,
        ])->assertOk();

        $this->assertSame(1, \App\Models\Favorite::query()->count());

        $this->getJson('/api/v1/me/favorites')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.target.title', $listing->title);

        // 收藏不存在的对象：直接 404，不埋死链
        $this->postJson('/api/v1/me/favorites', ['target_type' => 'listing', 'target_id' => 999999])
            ->assertStatus(404);

        // 取消收藏幂等
        $this->deleteJson("/api/v1/me/favorites/listing/{$listing->listing_id}")
            ->assertOk()
            ->assertJsonPath('data.favorited', false);

        $this->deleteJson("/api/v1/me/favorites/listing/{$listing->listing_id}")->assertOk();
        $this->assertSame(0, \App\Models\Favorite::query()->count());
    }

    public function test_follow_requires_active_seller_and_is_idempotent(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeUser('follow_buyer');
        $noShop = $this->makeUser('no_shop_user');

        Sanctum::actingAs($buyer);

        $this->postJson("/api/v1/sellers/{$seller->user_id}/follow")
            ->assertCreated()
            ->assertJsonPath('data.following', true)
            ->assertJsonPath('data.followers', 1);

        $this->postJson("/api/v1/sellers/{$seller->user_id}/follow")->assertOk();
        $this->assertSame(1, \App\Models\Follow::query()->count());

        // 不能关注自己 / 不能关注未开店账号
        $this->postJson("/api/v1/sellers/{$buyer->user_id}/follow")->assertStatus(422);
        $this->postJson("/api/v1/sellers/{$noShop->user_id}/follow")->assertStatus(404);

        $this->getJson('/api/v1/me/follows')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.shop_name', 'GOAT 互动店 goat_seller');

        $this->deleteJson("/api/v1/sellers/{$seller->user_id}/follow")
            ->assertOk()
            ->assertJsonPath('data.following', false)
            ->assertJsonPath('data.followers', 0);
    }

    public function test_only_buyer_of_completed_order_can_review_once(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeUser('review_buyer');
        $stranger = $this->makeUser('review_stranger');
        $listing = $this->makeListing($seller);

        $paidOrder = $this->makeOrder($buyer, $seller, $listing, 'paid');
        $completed = $this->makeOrder($buyer, $seller, $listing, 'completed');

        Sanctum::actingAs($buyer);

        // 未完成订单不能评价
        $this->postJson("/api/v1/orders/{$paidOrder->order_id}/reviews", ['rating' => 5])
            ->assertStatus(422);

        // 非买家不能评价他人订单
        Sanctum::actingAs($stranger);
        $this->postJson("/api/v1/orders/{$completed->order_id}/reviews", ['rating' => 5])
            ->assertStatus(403);

        Sanctum::actingAs($buyer);
        $this->postJson("/api/v1/orders/{$completed->order_id}/reviews", [
            'rating' => 5,
            'content' => '包装很稳，实物和描述一致',
        ])->assertCreated()->assertJsonPath('data.rating', 5);

        // 一单一评：重复提交 409，不覆盖历史
        $this->postJson("/api/v1/orders/{$completed->order_id}/reviews", ['rating' => 1])
            ->assertStatus(409);

        // 内容与图片同时为空：拒绝
        $emptyOrder = $this->makeOrder($buyer, $seller, $listing, 'completed');
        $this->postJson("/api/v1/orders/{$emptyOrder->order_id}/reviews", ['rating' => 4])
            ->assertStatus(422);

        // 店铺信用分按 5 星 = 10000 基点写入
        $this->assertSame(
            10000,
            (int) SellerProfile::query()->where('user_id', $seller->user_id)->value('rating_bp')
        );
    }

    public function test_seller_replies_once_and_buyer_gets_notified(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeUser('reply_buyer');
        $other = $this->makeSeller('other_seller');
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $seller, $listing);

        Sanctum::actingAs($buyer);
        $reviewId = (int) $this->postJson("/api/v1/orders/{$order->order_id}/reviews", [
            'rating' => 4,
            'content' => '整体不错，物流略慢',
        ])->assertCreated()->json('data.review_id');

        // 评价落库后卖家收到一条通知
        Sanctum::actingAs($seller);
        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('data.0.type', 'review.received');

        // 他人不能回复本店评价
        Sanctum::actingAs($other);
        $this->postJson("/api/v1/reviews/{$reviewId}/reply", ['content' => '抱歉'])
            ->assertStatus(403);

        Sanctum::actingAs($seller);
        $this->postJson("/api/v1/reviews/{$reviewId}/reply", ['content' => '物流已换承运商，感谢反馈'])
            ->assertOk()
            ->assertJsonPath('data.seller_reply', '物流已换承运商，感谢反馈');

        // 只能回复一次
        $this->postJson("/api/v1/reviews/{$reviewId}/reply", ['content' => '再补充一句'])
            ->assertStatus(409);

        // 买家收到「卖家回复」通知
        Sanctum::actingAs($buyer);
        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'review.replied');

        // 评价墙（公开）：无需登录，带评分概览
        $this->getJson("/api/v1/sellers/{$seller->user_id}/reviews")
            ->assertOk()
            ->assertJsonPath('summary.count', 1)
            ->assertJsonPath('summary.average', 4);
    }

    public function test_cart_only_accepts_purchasable_listings_and_is_idempotent(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeUser('cart_buyer');
        $listing = $this->makeListing($seller);

        Sanctum::actingAs($buyer);

        $this->postJson('/api/v1/me/cart', ['listing_id' => $listing->listing_id])
            ->assertCreated()
            ->assertJsonPath('data.listing_id', (int) $listing->listing_id);

        // 重复加购幂等，不累加数量
        $this->postJson('/api/v1/me/cart', ['listing_id' => $listing->listing_id])->assertOk();
        $this->assertSame(1, \App\Models\CartItem::query()->count());

        $this->getJson('/api/v1/me/cart')
            ->assertOk()
            ->assertJsonPath('data.0.purchasable', true)
            ->assertJsonPath('data.0.listing.price', '1280.00');

        // 卖家下架后购物车行保留但置灰，不能直接下单
        Listing::query()->whereKey($listing->listing_id)->update(['status' => 'delisted']);

        $this->getJson('/api/v1/me/cart')
            ->assertOk()
            ->assertJsonPath('data.0.purchasable', false);

        // 已下架的商品不能再加购（已在车内的行不受影响）
        $this->postJson('/api/v1/me/cart', ['listing_id' => $listing->listing_id])->assertStatus(422);

        // 卖家不能把自己商品加购（用另一张在售商品验证）
        $other = $this->makeListing($seller);
        Sanctum::actingAs($seller);
        $this->postJson('/api/v1/me/cart', ['listing_id' => $other->listing_id])->assertStatus(422);
        $this->postJson('/api/v1/me/cart', ['listing_id' => 999999])->assertStatus(404);

        // 删除 + 清空
        Sanctum::actingAs($buyer);
        $this->deleteJson("/api/v1/me/cart/{$listing->listing_id}")
            ->assertOk()
            ->assertJsonPath('data.removed', true);

        $this->deleteJson('/api/v1/me/cart')->assertOk();
        $this->assertSame(0, \App\Models\CartItem::query()->count());
    }

    public function test_notification_read_flow_and_dedupe(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeUser('notify_buyer');
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $seller, $listing);

        Sanctum::actingAs($buyer);
        $this->postJson("/api/v1/orders/{$order->order_id}/reviews", [
            'rating' => 5,
            'content' => '完美',
        ])->assertCreated();

        Sanctum::actingAs($seller);

        // 同一事件重复推送（模拟渠道重推）：dedupe_key 吸收，不会产生第二条
        app(\App\Services\Notify\NotificationService::class)->push(
            (int) $seller->user_id,
            'review.received',
            '收到新的买家评价',
            '重复推送',
            ['review_id' => 1],
            'review:1:received'
        );

        $this->assertSame(1, UserNotification::query()->where('user_id', $seller->user_id)->count());

        $notificationId = (int) UserNotification::query()->where('user_id', $seller->user_id)->value('notification_id');

        $this->postJson("/api/v1/notifications/{$notificationId}/read")
            ->assertOk()
            ->assertJsonPath('data.is_read', true);

        $this->getJson('/api/v1/notifications?unread=1')
            ->assertOk()
            ->assertJsonPath('unread_count', 0)
            ->assertJsonPath('meta.total', 0);

        // 他人的通知不可读（按 user_id 收口，返回 404 不泄露存在性）
        Sanctum::actingAs($buyer);
        $this->postJson("/api/v1/notifications/{$notificationId}/read")->assertStatus(404);

        $this->assertSame(1, Review::query()->count());
    }
}

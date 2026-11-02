<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace Tests\Feature;

use App\Models\Address;
use App\Models\FeeRule;
use App\Models\Listing;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 收货地址簿与运费口径（T4）。
 *
 * 两条最容易被改坏的规则：
 * 1. 「默认地址每人至多一条」——写默认必须走服务层，前端连点两下不能出现两条默认；
 * 2. 「运费由挂牌方定」——客户端传 shipping_amount 一律不认，否则买家可以自己免运费。
 */
class AddressTest extends TestCase
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

    private function makeListing(User $seller, string $price, string $shippingFee): Listing
    {
        return Listing::query()->create([
            'seller_id' => $seller->user_id,
            'title' => '初音未来 交响乐 1/7 全新未拆',
            'condition_enum' => 'new',
            'price' => $price,
            'shipping_fee' => $shippingFee,
            'currency' => 'CNY',
            'status' => 'active',
            'stock' => 1,
            'published_at' => now(),
        ]);
    }

    /** @return array<string, string> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'receiver_name' => '罗先生',
            'receiver_phone' => '13800138000',
            'province' => '天津市',
            'city' => '天津市',
            'district' => '和平区',
            'detail' => '南京路 1 号 101',
        ], $overrides);
    }

    public function test_first_address_is_default_and_default_stays_unique(): void
    {
        $buyer = $this->makeUser('addr_buyer');
        Sanctum::actingAs($buyer);

        $first = $this->postJson('/api/v1/me/addresses', $this->payload())->assertCreated();
        // 第一条强制默认：新用户下单时不该还要再点一次「设为默认」
        $this->assertTrue($first->json('data.is_default'));

        $second = $this->postJson('/api/v1/me/addresses', $this->payload(['detail' => '赤峰道 2 号']))
            ->assertCreated();
        $this->assertFalse($second->json('data.is_default'));

        // 设第二条为默认，第一条必须自动取消
        $this->postJson('/api/v1/me/addresses/'.$second->json('data.address_id').'/default')->assertOk();

        $this->assertSame(1, Address::query()->where('user_id', $buyer->user_id)->where('is_default', true)->count());
        $this->assertFalse(Address::query()->whereKey($first->json('data.address_id'))->value('is_default'));

        // 列表按默认优先排序，下单页直接取第一条即可
        $list = $this->getJson('/api/v1/me/addresses')->assertOk();
        $this->assertSame($second->json('data.address_id'), $list->json('data.0.address_id'));
        $this->assertSame(2, $list->json('meta.total'));
        $this->assertSame('天津市天津市和平区', $list->json('data.0.region'));
    }

    public function test_deleting_default_promotes_latest_one(): void
    {
        $buyer = $this->makeUser('addr_del');
        Sanctum::actingAs($buyer);

        $first = $this->postJson('/api/v1/me/addresses', $this->payload())->assertCreated();
        $second = $this->postJson('/api/v1/me/addresses', $this->payload(['detail' => '赤峰道 2 号']))->assertCreated();

        // 删掉默认地址后，剩下的最新一条自动顶上来，否则下单页没有默认可选
        $deleted = $this->deleteJson('/api/v1/me/addresses/'.$first->json('data.address_id'))->assertOk();

        $this->assertSame($second->json('data.address_id'), $deleted->json('data.address_id'));
        $this->assertTrue($deleted->json('data.is_default'));
        $this->assertSame(1, Address::query()->where('user_id', $buyer->user_id)->count());
    }

    public function test_phone_format_and_limit_are_enforced(): void
    {
        $buyer = $this->makeUser('addr_limit');
        Sanctum::actingAs($buyer);

        $this->postJson('/api/v1/me/addresses', $this->payload(['receiver_phone' => '12345']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('receiver_phone');

        for ($i = 1; $i <= 10; $i++) {
            $this->postJson('/api/v1/me/addresses', $this->payload(['detail' => '南京路 '.$i.' 号']))->assertCreated();
        }

        $this->postJson('/api/v1/me/addresses', $this->payload(['detail' => '第 11 条']))
            ->assertStatus(422)
            ->assertJsonPath('message', '收货地址最多保存 10 条，请先删除不用的');
    }

    public function test_cannot_touch_other_users_address(): void
    {
        $owner = $this->makeUser('addr_owner');
        $other = $this->makeUser('addr_other');

        Sanctum::actingAs($owner);
        $addressId = $this->postJson('/api/v1/me/addresses', $this->payload())->json('data.address_id');

        Sanctum::actingAs($other);
        // 越权一律 404：返回 403 等于承认这条地址存在
        $this->putJson('/api/v1/me/addresses/'.$addressId, $this->payload(['detail' => '改别人地址']))
            ->assertNotFound();
        $this->deleteJson('/api/v1/me/addresses/'.$addressId)->assertNotFound();
        $this->postJson('/api/v1/me/addresses/'.$addressId.'/default')->assertNotFound();

        $this->assertSame(1, Address::query()->where('user_id', $owner->user_id)->count());
        $this->assertSame('南京路 1 号 101', Address::query()->whereKey($addressId)->value('detail'));
    }

    public function test_order_requires_own_address_and_ignores_client_shipping(): void
    {
        FeeRule::query()->create([
            'scope' => 'global',
            'percent_bip' => 0,
            'bearer' => 'seller',
            'currency' => 'CNY',
            'is_active' => true,
        ]);

        $seller = $this->makeSeller();
        $buyer = $this->makeUser('order_buyer');
        $otherBuyer = $this->makeUser('order_other');
        $listing = $this->makeListing($seller, '1000.00', '12.50');

        Sanctum::actingAs($buyer);

        // 缺地址直接拒：没有收件人，订单发了也寄不出去
        $this->withHeader('Idempotency-Key', 'addr-missing')
            ->postJson('/api/v1/orders', ['listing_id' => $listing->listing_id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('address_id');

        Sanctum::actingAs($otherBuyer);
        $foreignAddress = $this->postJson('/api/v1/me/addresses', $this->payload())->json('data.address_id');

        Sanctum::actingAs($buyer);
        // 用别人的地址同样拒：不泄露他人地址是否存在
        $this->withHeader('Idempotency-Key', 'addr-foreign')
            ->postJson('/api/v1/orders', [
                'listing_id' => $listing->listing_id,
                'address_id' => $foreignAddress,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', '收货地址不存在，请重新选择');

        $address = $this->postJson('/api/v1/me/addresses', $this->payload())->json('data.address_id');

        // 客户端传 shipping_amount=0 也没用：运费只认商品挂牌值
        $created = $this->withHeader('Idempotency-Key', 'addr-ok')
            ->postJson('/api/v1/orders', [
                'listing_id' => $listing->listing_id,
                'address_id' => $address,
                'shipping_amount' => '0',
            ])
            ->assertCreated();

        $this->assertSame('12.50', $created->json('data.shipping_amount'));
        $this->assertSame('1012.50', $created->json('data.payable_amount'));
        $this->assertSame('罗先生', $created->json('data.receiver.name'));
        $this->assertSame('天津市天津市和平区', $created->json('data.receiver.region'));
    }

    public function test_order_keeps_receiver_snapshot_after_address_edit(): void
    {
        $seller = $this->makeSeller('snap_seller');
        $buyer = $this->makeUser('snap_buyer');
        $listing = $this->makeListing($seller, '500.00', '0');

        Sanctum::actingAs($buyer);
        $addressId = $this->postJson('/api/v1/me/addresses', $this->payload())->json('data.address_id');

        $orderId = $this->withHeader('Idempotency-Key', 'snap-order')
            ->postJson('/api/v1/orders', ['listing_id' => $listing->listing_id, 'address_id' => $addressId])
            ->assertCreated()
            ->json('data.order_id');

        // 下单后买家把地址改成别处：卖家仍按下单那一刻的快照发货
        $this->putJson('/api/v1/me/addresses/'.$addressId, ['detail' => '搬去北京了'])->assertOk();
        $this->deleteJson('/api/v1/me/addresses/'.$addressId)->assertOk();

        $this->getJson('/api/v1/orders/'.$orderId)
            ->assertOk()
            ->assertJsonPath('data.receiver.detail', '南京路 1 号 101')
            ->assertJsonPath('data.receiver.name', '罗先生');
    }
}

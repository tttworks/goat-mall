<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 卖家上架主链路（T4 写侧）。
 *
 * 覆盖验收点：无店铺不能上架、草稿→提交→审核→在售的状态机、
 * 越权编辑他人商品被拒（按 seller_id 收口，而非「先取后判」）。
 */
class TradeFlowTest extends TestCase
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
            'shop_name' => 'GOAT 官方店 '.$username,
            'status' => 'active',
            'identity_status' => 'approved',
            'applied_at' => now(),
            'activated_at' => now(),
        ]);

        return $user;
    }

    /** @return array<string, mixed> */
    private function listingPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => '初音未来 交响乐 1/7 全新未拆',
            'description' => '含原盒与限定特典，未拆封',
            'condition' => 'new',
            'price' => '1280.00',
            'stock' => 1,
            'cover_key' => 'listings/2026/11/cover.jpg',
        ], $overrides);
    }

    public function test_buyer_without_shop_cannot_create_listing(): void
    {
        Sanctum::actingAs($this->makeUser('plain_buyer'));

        $this->postJson('/api/v1/seller/listings', $this->listingPayload())
            ->assertStatus(403);
    }

    public function test_listing_goes_draft_submit_approve_active(): void
    {
        $seller = $this->makeSeller();
        Sanctum::actingAs($seller);

        $created = $this->postJson('/api/v1/seller/listings', $this->listingPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        $listingId = (int) $created->json('data.listing_id');

        // 草稿不对外可见
        $this->getJson('/api/v1/listings')->assertOk()->assertJsonPath('meta.total', 0);

        $this->postJson("/api/v1/seller/listings/{$listingId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_review');

        // 待审核同样不对外可见
        $this->getJson('/api/v1/listings')->assertOk()->assertJsonPath('meta.total', 0);

        Sanctum::actingAs($this->makeUser('goat_admin', 'admin'));

        $this->postJson("/api/v1/admin/listings/{$listingId}/review", ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->getJson('/api/v1/listings')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_reject_requires_reason_and_keeps_listing_out_of_shelf(): void
    {
        $seller = $this->makeSeller();
        Sanctum::actingAs($seller);

        $listingId = (int) $this->postJson('/api/v1/seller/listings', $this->listingPayload())
            ->assertCreated()
            ->json('data.listing_id');

        $this->postJson("/api/v1/seller/listings/{$listingId}/submit")->assertOk();

        Sanctum::actingAs($this->makeUser('goat_admin2', 'admin'));

        // 驳回必须给理由：不给就是 422，避免卖家拿到一个无信息量的驳回
        $this->postJson("/api/v1/admin/listings/{$listingId}/review", ['action' => 'reject'])
            ->assertStatus(422);

        $this->postJson("/api/v1/admin/listings/{$listingId}/review", [
            'action' => 'reject',
            'reason' => '图片疑似盗用他人实拍',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertSame(
            '图片疑似盗用他人实拍',
            Listing::query()->whereKey($listingId)->firstOrFail()->meta['reject_reason'] ?? null
        );
    }

    public function test_seller_cannot_edit_others_listing(): void
    {
        $owner = $this->makeSeller('owner_seller');
        Sanctum::actingAs($owner);

        $listingId = (int) $this->postJson('/api/v1/seller/listings', $this->listingPayload())
            ->assertCreated()
            ->json('data.listing_id');

        Sanctum::actingAs($this->makeSeller('other_seller'));

        // 用 where(seller_id) 收口：他人商品等同不存在，返回 404 而不是 403，
        // 避免通过状态码探测「这个 id 是否存在」
        $this->putJson("/api/v1/seller/listings/{$listingId}", $this->listingPayload(['price' => '1.00']))
            ->assertStatus(404);

        $this->postJson("/api/v1/seller/listings/{$listingId}/delist")->assertStatus(404);
    }

    public function test_delist_and_reopen_keep_seller_in_control(): void
    {
        $seller = $this->makeSeller();
        Sanctum::actingAs($seller);

        $listingId = (int) $this->postJson('/api/v1/seller/listings', $this->listingPayload())
            ->assertCreated()
            ->json('data.listing_id');

        $this->postJson("/api/v1/seller/listings/{$listingId}/submit")->assertOk();

        Sanctum::actingAs($this->makeUser('goat_admin3', 'admin'));
        $this->postJson("/api/v1/admin/listings/{$listingId}/review", ['action' => 'approve'])->assertOk();

        Sanctum::actingAs($seller);

        $this->postJson("/api/v1/seller/listings/{$listingId}/delist")
            ->assertOk()
            ->assertJsonPath('data.status', 'delisted');

        $this->postJson("/api/v1/seller/listings/{$listingId}/reopen")
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');
    }
}

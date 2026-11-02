<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace Tests\Feature;

use App\Models\Auction;
use App\Models\Figure;
use App\Models\Listing;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * C 端补齐（T3/T4/T7）时踩出来的四个连接点：搜索、实名、卖家上传、竞拍列表。
 *
 * 这四个都不是「新功能」，而是既有能力在真机上点了没反应的位置：
 * 搜索用了 PostgreSQL 才有的 ilike、实名方法声明了不存在的类、卖家没有上传入口、
 * 竞拍列表只回 listing_id。任何一条复发，C 端就会出现空白页。
 */
class MobileGapTest extends TestCase
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

    private function makeListing(User $seller, string $title, string $price = '1000.00'): Listing
    {
        return Listing::query()->create([
            'seller_id' => $seller->user_id,
            'title' => $title,
            'condition_enum' => 'new',
            'price' => $price,
            'currency' => 'CNY',
            'status' => 'active',
            'stock' => 1,
            'published_at' => now(),
        ]);
    }

    /** 搜索走的是 listings / figures 两个公开索引，测试库里必须能跑（SQLite 没有 ILIKE） */
    public function test_keyword_search_works_on_both_indexes(): void
    {
        $seller = $this->makeSeller('goat_seller_search');
        $this->makeListing($seller, '初音未来 交响乐 1/7 全新未拆');
        $this->makeListing($seller, '假面骑士 W 限定色');

        Figure::query()->create([
            'slug' => 'hatsune-miku-symphony-7',
            'name' => '初音未来 交响乐 1/7',
            'maker' => 'Good Smile',
            'category' => 'scale_figure',
            'status' => 'published',
            'cover_key' => null,
        ]);

        $listings = $this->getJson('/api/v1/listings?keyword=交响乐')->assertOk();
        $this->assertCount(1, $listings->json('data'));
        $this->assertStringContainsString('交响乐', (string) $listings->json('data.0.title'));

        $figures = $this->getJson('/api/v1/figures?keyword=初音')->assertOk();
        $this->assertGreaterThanOrEqual(1, count($figures->json('data')));

        // 大小写不敏感：SQLite 的 LIKE 对 ASCII 天生如此，PG 侧由 whereLike 生成 ILIKE
        $this->getJson('/api/v1/listings?keyword=w')->assertOk();
    }

    /** 实名提交曾经因类型声明指向不存在的 SubmitRequest 而必 500 */
    public function test_identity_submit_returns_verification(): void
    {
        $buyer = $this->makeUser('goat_buyer_identity');
        Sanctum::actingAs($buyer);

        $response = $this->postJson('/api/v1/identity', [
            'real_name' => '罗小串',
            'id_number' => '110101199001011234',
        ])->assertSuccessful();

        $status = (string) $response->json('verification.status');
        $this->assertContains($status, ['pending', 'approved', 'rejected']);

        $this->assertDatabaseHas('identity_verifications', [
            'user_id' => $buyer->user_id,
        ]);

        // 同一证件号不能挂到第二个账号：服务层抛 AuthException::conflict → 409
        $second = $this->makeUser('goat_buyer_identity2');
        Sanctum::actingAs($second);
        $this->postJson('/api/v1/identity', [
            'real_name' => '罗小串',
            'id_number' => '110101199001011234',
        ])->assertStatus(409);
    }

    /** 卖家侧没有上传入口时，submit 永远卡在「请先上传封面」 */
    public function test_seller_can_upload_listing_image(): void
    {
        Storage::fake((string) config('goat.media.disk', 'ufile'));

        $seller = $this->makeSeller('goat_seller_media');
        Sanctum::actingAs($seller);

        // 用真实 1x1 PNG 字节流：CI 没装 GD，UploadedFile::fake()->image() 会直接抛异常
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );

        $response = $this->post('/api/v1/seller/media', [
            'file' => UploadedFile::fake()->createWithContent('cover.png', $png),
        ], ['Accept' => 'application/json'])->assertCreated();

        $key = (string) $response->json('data.key');
        $this->assertStringStartsWith('listings/', $key);
        Storage::disk((string) config('goat.media.disk', 'ufile'))->assertExists($key);

        // 目录白名单：文件名里带 ../ 也只会落在 listings 下
        $this->assertStringNotContainsString('..', $key);
    }

    /** 竞拍列表必须能渲染「卖的是什么」，否则 C 端只剩一行数字 */
    public function test_auction_list_exposes_listing_snapshot(): void
    {
        $seller = $this->makeSeller('goat_seller_auction');
        $listing = $this->makeListing($seller, 'BANDAI 龙珠 超赛悟空 景品', '2000.00');

        Auction::query()->create([
            'listing_id' => $listing->listing_id,
            'seller_id' => $seller->user_id,
            'status' => 'active',
            'start_price' => '2000.00',
            'min_increment' => '50.00',
            'current_price' => '2000.00',
            'bid_count' => 0,
            'start_at' => Carbon::now()->subHour(),
            'end_at' => Carbon::now()->addHours(3),
            'original_end_at' => Carbon::now()->addHours(3),
        ]);

        $response = $this->getJson('/api/v1/auctions')->assertOk();

        $this->assertSame('BANDAI 龙珠 超赛悟空 景品', $response->json('data.0.title'));
        $this->assertSame('new', $response->json('data.0.condition'));
        $this->assertSame('active', $response->json('data.0.listing_status'));
        $this->assertArrayHasKey('cover_url', $response->json('data.0'));
    }

    /** 店铺主页：买家点卖家必须能落到一页完整信息，且不得泄露实名/驳回原因 */
    public function test_public_seller_shop_exposes_only_public_fields(): void
    {
        $seller = $this->makeSeller('goat_seller_shop');
        $this->makeListing($seller, '寿屋 EVA 初号机 1/7', '880.00');

        $guest = $this->getJson('/api/v1/sellers/'.$seller->user_id)->assertOk();

        $this->assertSame('GOAT 担保店 goat_seller_shop', $guest->json('data.shop_name'));
        $this->assertSame(1, $guest->json('data.listing_count'));
        $this->assertNull($guest->json('data.rating'));
        $this->assertSame(0, $guest->json('data.review_count'));
        // 未登录不给关注态，也不该出现经营内部字段
        $this->assertNull($guest->json('following'));
        $this->assertArrayNotHasKey('identity_status', $guest->json('data'));
        $this->assertArrayNotHasKey('reject_reason', $guest->json('data'));

        $buyer = $this->makeUser('goat_buyer_shop');
        Sanctum::actingAs($buyer);
        $this->assertFalse($this->getJson('/api/v1/sellers/'.$seller->user_id)->json('following'));

        // 卖家看自己的店铺：不显示关注按钮
        Sanctum::actingAs($seller);
        $this->assertTrue($this->getJson('/api/v1/sellers/'.$seller->user_id)->json('is_self'));

        $this->getJson('/api/v1/sellers/999999')->assertNotFound();
    }
}

<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace Tests\Feature;

use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 站点 ↔ 采集系统桥接（契约 §4）+ 后台店铺队列（T2 补齐列表接口）。
 *
 * 采集侧只测「站点该做的三件事」：鉴权头算对、错误码分类正确、不重试。
 * 抓取逻辑属于采集仓，不在本站测试范围（权属与职责都要分开）。
 */
class CollectorBridgeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::query()->create([
            'username' => 'goat_admin_'.uniqid(),
            'password' => 'Goat@2026',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function configureCollector(string $baseUrl = 'http://collector.test', string $key = 'k3y'): void
    {
        config([
            'goat.collector.base_url' => $baseUrl,
            'goat.collector.hmac_key' => $key,
            'goat.collector.timeout' => 5,
        ]);
    }

    public function test_crawl_price_signs_request_and_returns_job(): void
    {
        $this->configureCollector();
        Sanctum::actingAs($this->admin());

        Http::fake([
            'collector.test/internal/collector/crawl-price' => Http::response(['job_id' => 'job_01H'], 202),
        ]);

        $this->postJson('/api/v1/admin/collector/crawl-price', [
            'figure_id' => 7,
            'sources' => ['mercari_jp', 'ebay'],
        ])->assertStatus(202)->assertJsonPath('data.job_id', 'job_01H');

        Http::assertSent(function (ClientRequest $request): bool {
            $timestamp = $request->header('X-Goat-Timestamp')[0] ?? '';
            $signature = $request->header('X-Goat-Signature')[0] ?? '';
            $body = $request->body();

            // 签名原文 = timestamp \n rawBody，密钥只在服务端
            return $timestamp !== ''
                && hash_equals(hash_hmac('sha256', $timestamp."\n".$body, 'k3y'), $signature)
                && str_contains($body, '"figure_id":7');
        });
    }

    public function test_unconfigured_collector_returns_503_with_stable_code(): void
    {
        $this->configureCollector('', '');
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/admin/collector/crawl-figure', ['figure_id' => 1])
            ->assertStatus(503)
            ->assertJsonPath('code', 'COLLECTOR_NOT_CONFIGURED');
    }

    public function test_upstream_failure_is_reported_as_502(): void
    {
        $this->configureCollector();
        Sanctum::actingAs($this->admin());

        Http::fake([
            'collector.test/*' => Http::response(['message' => 'boom'], 500),
        ]);

        $this->postJson('/api/v1/admin/collector/crawl-figure', ['figure_id' => 1])
            ->assertStatus(502)
            ->assertJsonPath('code', 'COLLECTOR_UPSTREAM_FAILED');
    }

    public function test_non_admin_cannot_trigger_crawl(): void
    {
        $this->configureCollector();
        Sanctum::actingAs(User::query()->create([
            'username' => 'goat_buyer_'.uniqid(),
            'password' => 'Goat@2026',
            'role' => 'buyer',
            'status' => 'active',
        ]));

        $this->postJson('/api/v1/admin/collector/crawl-price', ['figure_id' => 1])->assertStatus(403);
    }

    public function test_admin_seller_queue_defaults_to_pending_and_filters_by_status(): void
    {
        Sanctum::actingAs($this->admin());

        $pendingOwner = User::query()->create([
            'username' => 'goat_seller_pending',
            'password' => 'Goat@2026',
            'role' => 'seller',
            'status' => 'active',
        ]);
        $activeOwner = User::query()->create([
            'username' => 'goat_seller_active',
            'password' => 'Goat@2026',
            'role' => 'seller',
            'status' => 'active',
        ]);

        SellerProfile::query()->create([
            'user_id' => $pendingOwner->user_id,
            'shop_name' => '待审核小铺',
            'status' => 'pending',
            'identity_status' => 'pending',
            'applied_at' => now(),
        ]);
        SellerProfile::query()->create([
            'user_id' => $activeOwner->user_id,
            'shop_name' => '已开业小铺',
            'status' => 'active',
            'identity_status' => 'approved',
            'applied_at' => now(),
        ]);

        // 默认队列 = 待审核：运营打开就是干活的地方，不需要先选筛选条件
        $this->getJson('/api/v1/admin/sellers')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.shop_name', '待审核小铺')
            ->assertJsonPath('data.0.owner.nickname', $pendingOwner->nickname);

        $this->getJson('/api/v1/admin/sellers?status=active')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.shop_name', '已开业小铺');
    }
}

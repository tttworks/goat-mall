<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace Tests\Feature;

use App\Models\PricePoint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 行情源配置：后台能配，C 端要真的跟着变。
 *
 * 这一组的重点是「配置生效」而不只是「字段能存」：
 * 停用 eBay 之后，买家侧的价签里 eBay 必须消失；但历史 K 线仍要能按 source 查，
 * 否则运营一停源，客服就连历史成交价都看不到了。
 */
class MarketSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_listing_seeds_default_sources_with_ingest_stats(): void
    {
        $admin = $this->makeUser('goat_admin_ms', 'admin');
        $figureId = $this->publishedFigure($admin);

        $this->point($figureId, 'ebay', '1280.00', 2);
        $this->point($figureId, 'ebay', '1350.00', 1);
        $this->point($figureId, 'mercari', '980.00', 3);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/admin/market-sources')->assertOk();

        $this->assertSame(4, $response->json('meta.total'));
        $this->assertSame(4, $response->json('meta.enabled'));
        $this->assertSame(3, $response->json('meta.points_total'));

        $rows = collect($response->json('data'));
        $ebay = $rows->firstWhere('source', 'ebay');

        $this->assertSame('eBay', $ebay['label']);
        $this->assertSame('collector', $ebay['channel']);
        // 采集侧标识是站点与采集系统的连线：后台停用后，采集触发页的勾选项要跟着消失
        $this->assertSame('ebay', $ebay['collector_key']);
        $this->assertSame('mercari_jp', $rows->firstWhere('source', 'mercari')['collector_key']);
        $this->assertNull($rows->firstWhere('source', 'manual')['collector_key']);
        $this->assertSame(2, $ebay['stats']['total']);
        $this->assertSame(1, $ebay['stats']['figures']);
        $this->assertSame(2, $ebay['stats']['recent']);
        $this->assertFalse($ebay['locked']);
        // 刚建的源没同步过：不算中断，交给 last_status=idle 表达「未同步」
        $this->assertSame('idle', $ebay['last_status']);
        $this->assertFalse($ebay['stale']);

        $internal = $rows->firstWhere('source', 'internal');
        $this->assertTrue($internal['locked']);
        $this->assertSame('站内成交回写', $internal['channel_label']);
    }

    public function test_disabled_source_leaves_buyer_quotes_but_history_stays_queryable(): void
    {
        $admin = $this->makeUser('goat_admin_ms', 'admin');
        $figureId = $this->publishedFigure($admin);

        $this->point($figureId, 'ebay', '1280.00', 1);
        $this->point($figureId, 'internal', '1500.00', 1);

        Sanctum::actingAs($admin);
        $sourceId = collect($this->getJson('/api/v1/admin/market-sources')->json('data'))
            ->firstWhere('source', 'ebay')['market_source_id'];

        $this->patchJson("/api/v1/admin/market-sources/{$sourceId}", ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        // 买家侧：多源对比里不该再出现 eBay
        $summary = $this->getJson("/api/v1/figures/{$figureId}/quotes")->assertOk();
        $this->assertNotContains('ebay', array_column($summary->json('data.sources'), 'source'));
        $this->assertContains('internal', array_column($summary->json('data.sources'), 'source'));

        // 历史仍可查：停用是「不再展示」，不是「删数据」
        $kline = $this->getJson("/api/v1/figures/{$figureId}/kline?source=ebay")->assertOk();
        $this->assertCount(1, $kline->json('data.candles'));
        $this->assertNotContains('ebay', $kline->json('data.available_sources'));

        $this->assertDatabaseHas('price_points', ['figure_id' => $figureId, 'source' => 'ebay']);
    }

    public function test_renamed_source_shows_up_in_buyer_quote_labels(): void
    {
        $admin = $this->makeUser('goat_admin_ms', 'admin');
        $figureId = $this->publishedFigure($admin);

        $this->point($figureId, 'mercari', '980.00', 1);

        Sanctum::actingAs($admin);
        $sourceId = collect($this->getJson('/api/v1/admin/market-sources')->json('data'))
            ->firstWhere('source', 'mercari')['market_source_id'];

        $this->patchJson("/api/v1/admin/market-sources/{$sourceId}", ['label' => '煤炉（日本）'])
            ->assertOk()
            ->assertJsonPath('data.label', '煤炉（日本）');

        $summary = $this->getJson("/api/v1/figures/{$figureId}/quotes")->assertOk();
        $mercari = collect($summary->json('data.sources'))->firstWhere('source', 'mercari');

        $this->assertSame('煤炉（日本）', $mercari['label']);
    }

    public function test_internal_source_cannot_be_disabled(): void
    {
        $admin = $this->makeUser('goat_admin_ms', 'admin');

        Sanctum::actingAs($admin);
        $sourceId = collect($this->getJson('/api/v1/admin/market-sources')->json('data'))
            ->firstWhere('source', 'internal')['market_source_id'];

        $this->patchJson("/api/v1/admin/market-sources/{$sourceId}", ['enabled' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('enabled');

        $this->assertDatabaseHas('market_sources', ['source' => 'internal', 'enabled' => true]);
    }

    public function test_source_registry_is_admin_only(): void
    {
        $buyer = $this->makeUser('goat_buyer_ms');
        Sanctum::actingAs($buyer);

        $this->getJson('/api/v1/admin/market-sources')->assertForbidden();
        $this->patchJson('/api/v1/admin/market-sources/1', ['enabled' => false])->assertForbidden();
    }

    public function test_interval_must_stay_inside_collector_window(): void
    {
        $admin = $this->makeUser('goat_admin_ms', 'admin');

        Sanctum::actingAs($admin);
        $sourceId = collect($this->getJson('/api/v1/admin/market-sources')->json('data'))
            ->firstWhere('source', 'ebay')['market_source_id'];

        $this->patchJson("/api/v1/admin/market-sources/{$sourceId}", ['interval_minutes' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('interval_minutes');

        $this->patchJson("/api/v1/admin/market-sources/{$sourceId}", ['interval_minutes' => 720])
            ->assertOk()
            ->assertJsonPath('data.interval_minutes', 720);
    }

    private function point(int $figureId, string $source, string $price, int $daysAgo): void
    {
        PricePoint::query()->create([
            'figure_id' => $figureId,
            'source' => $source,
            'price' => $price,
            'currency' => 'CNY',
            'condition' => 'used_a',
            'sold_at' => Carbon::now()->subDays($daysAgo),
        ]);
    }

    private function makeUser(string $nickname, string $role = 'buyer'): User
    {
        return User::query()->create([
            'nickname' => $nickname,
            'phone' => '139'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'role' => $role,
            'status' => 'active',
        ]);
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
}

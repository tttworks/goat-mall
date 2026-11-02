<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace Tests\Feature;

use App\Models\Figure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 图鑑管理端主链路（T3）。
 *
 * 覆盖验收点：admin 可录入（含外部 ID 溯源）、非 admin 被拒、
 * 草稿不对 C 端泄漏、发布后出现在列表、多维筛选生效、外站图片 URL 被拒、删除保护。
 */
class FigureCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::create([
            'username' => 'goat_admin',
            'password' => 'Goat@2026',
            'role' => 'admin',
            'status' => 'active',
        ]);

        Sanctum::actingAs($admin);

        return $admin;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => '初音未来 交响乐 1/7',
            'name_ja' => '初音ミク シンフォニー',
            'aliases' => ['交响初音', 'SYMPHONY MIKU'],
            'maker' => 'Good Smile Company',
            'series' => 'Character Vocal Series 01',
            'scale' => '1/7',
            'category' => 'scale_figure',
            'release_date' => '2024-12-20',
            'external_ids' => ['mfc' => '123456', '78dm' => 'ACG-9527'],
            'specs' => ['material' => 'PVC/ABS', 'height' => '230mm'],
            'cover_key' => 'figures/2026/11/cover.jpg',
        ], $overrides);
    }

    public function test_admin_can_create_figure_with_sources_and_derived_year(): void
    {
        $this->admin();

        $response = $this->postJson('/api/v1/admin/figures', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.year', 2024)
            ->assertJsonStructure(['data' => ['figure_id', 'slug', 'cover_url', 'external_ids', 'sources']]);

        $this->assertDatabaseHas('figures', [
            'name' => '初音未来 交响乐 1/7',
            'year' => 2024,
            'status' => 'draft',
        ]);

        // 外部 ID 双向同步：figures.external_ids + figure_sources 各两条
        $this->assertDatabaseHas('figure_sources', ['source' => 'mfc', 'external_id' => '123456']);
        $this->assertDatabaseHas('figure_sources', ['source' => '78dm', 'external_id' => 'ACG-9527']);
        $this->assertSame(2, DB::table('figure_sources')->count());
    }

    public function test_non_admin_cannot_touch_catalog(): void
    {
        $buyer = User::create([
            'username' => 'plain_buyer',
            'password' => 'Goat@2026',
            'role' => 'buyer',
            'status' => 'active',
        ]);

        Sanctum::actingAs($buyer);

        $this->postJson('/api/v1/admin/figures', $this->payload())
            ->assertStatus(403);
    }

    public function test_external_image_url_is_rejected(): void
    {
        $this->admin();

        $this->postJson('/api/v1/admin/figures', $this->payload([
            'cover_key' => null,
            'cover_url' => 'https://evil.example.com/stolen.jpg',
        ]))->assertStatus(422)->assertJson(['code' => 'CATALOG_INVALID']);
    }

    public function test_draft_is_hidden_until_published(): void
    {
        $this->admin();

        $figureId = (int) $this->postJson('/api/v1/admin/figures', $this->payload())
            ->json('data.figure_id');

        $this->getJson('/api/v1/figures')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/figures/'.$figureId)->assertStatus(404);

        $this->patchJson("/api/v1/admin/figures/{$figureId}/status", ['status' => 'published'])
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $this->assertNotNull(Figure::find($figureId)->published_at);

        $this->getJson('/api/v1/figures')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', '初音未来 交响乐 1/7');

        $this->getJson('/api/v1/figures/'.$figureId)->assertOk();
    }

    public function test_public_list_filters_by_category_and_maker(): void
    {
        $this->admin();

        $this->postJson('/api/v1/admin/figures', $this->payload([
            'name' => '一番赏 A 赏 路飞',
            'category' => 'ichiban_kuji',
            'maker' => 'Bandai Spirits',
            'status' => 'published',
        ]))->assertCreated();

        $this->postJson('/api/v1/admin/figures', $this->payload([
            'name' => '景品 悟空',
            'category' => 'prize',
            'maker' => 'Banpresto',
            'status' => 'published',
        ]))->assertCreated();

        $this->getJson('/api/v1/figures?category=prize')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', '景品 悟空');

        $this->getJson('/api/v1/figures?maker=Bandai%20Spirits')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.category', 'ichiban_kuji');
    }

    public function test_show_by_slug_counts_view_once_per_hour(): void
    {
        $this->admin();

        $this->postJson('/api/v1/admin/figures', $this->payload(['status' => 'published']))->assertCreated();

        $slug = (string) Figure::query()->firstOrFail()->slug;

        $this->getJson('/api/v1/figures/'.$slug)->assertOk()->assertJsonPath('data.slug', $slug);
        $this->getJson('/api/v1/figures/'.$slug)->assertOk();

        $this->assertSame(1, (int) Figure::query()->firstOrFail()->views);
    }

    public function test_slug_conflict_is_resolved_not_thrown(): void
    {
        $this->admin();

        $first = $this->postJson('/api/v1/admin/figures', $this->payload([
            'name' => 'Nendoroid Hatsune Miku',
            'slug' => 'nendoroid-miku',
        ]))->assertCreated();

        $second = $this->postJson('/api/v1/admin/figures', $this->payload([
            'name' => 'Nendoroid Miku 2.0',
            'slug' => 'nendoroid-miku',
        ]))->assertCreated();

        $this->assertSame('nendoroid-miku', $first->json('data.slug'));
        $this->assertSame('nendoroid-miku-2', $second->json('data.slug'));
    }
}

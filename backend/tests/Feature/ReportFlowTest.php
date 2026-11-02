<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Models\Figure;
use App\Models\Listing;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 举报与发帖带图（T6 · 社群治理）。
 *
 * 核心口径：
 * 1. 只举报「真实存在且对自己可见」的目标，举报自己 422，重复待处理 409；
 * 2. 处置完成后再举报复用同一条工单（唯一约束），并重新置为 pending——
 *    「举报一次被驳回」不能变成对方内容的护身符；
 * 3. 后台队列默认只列 pending，判定后回告举报人；
 * 4. 帖子图片只认 posts/ 目录下的 key，不能把商品图/头像 key 塞进来。
 */
class ReportFlowTest extends TestCase
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

    private function makeListing(User $seller): Listing
    {
        $figure = Figure::query()->create([
            'slug' => 'report-figure-'.$seller->user_id,
            'name' => '举报测试手办',
            'maker' => 'Good Smile',
            'category' => 'scale_figure',
            'status' => 'published',
            'cover_key' => null,
        ]);

        return Listing::query()->create([
            'seller_id' => $seller->user_id,
            'figure_id' => $figure->figure_id,
            'title' => '全新未拆 现货',
            'condition_enum' => 'new',
            'price' => '980.00',
            'currency' => 'CNY',
            'status' => 'active',
            'stock' => 1,
            'published_at' => now(),
        ]);
    }

    private function makePost(User $author, PostStatus $status = PostStatus::Approved, string $content = '今天开箱，涂装很稳'): Post
    {
        return Post::query()->create([
            'user_id' => $author->user_id,
            'content' => $content,
            'status' => $status,
            'published_at' => $status === PostStatus::Approved ? now() : null,
        ]);
    }

    public function test_report_submit_lands_in_admin_queue_and_notifies_reporter(): void
    {
        $seller = $this->makeUser('report_seller', 'seller');
        $buyer = $this->makeUser('report_buyer');
        $admin = $this->makeUser('report_admin', 'admin');
        $listing = $this->makeListing($seller);

        Sanctum::actingAs($buyer);

        $this->postJson('/api/v1/reports', [
            'target_type' => 'listing',
            'target_id' => $listing->listing_id,
            'reason' => 'fake',
            'detail' => '详情图与实拍不符，疑似仿品',
        ])->assertCreated()->assertJsonPath('data.status', 'pending');

        $this->assertSame(1, Report::query()->count());

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/reports')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.target_type_label', '商品')
            ->assertJsonPath('data.0.target_title', '全新未拆 现货')
            ->assertJsonPath('data.0.target_owner_id', (int) $seller->user_id)
            ->assertJsonPath('data.0.reason_label', '假货 / 仿品')
            ->assertJsonPath('data.0.reporter.user_id', (int) $buyer->user_id);

        $reportId = (int) Report::query()->value('report_id');

        $this->postJson("/api/v1/admin/reports/{$reportId}/handle", [
            'result' => 'handled',
            'note' => '已核实为仿品，商品已下架',
        ])->assertOk()
            ->assertJsonPath('data.status', 'handled')
            ->assertJsonPath('data.handler.user_id', (int) $admin->user_id)
            ->assertJsonPath('data.handle_note', '已核实为仿品，商品已下架');

        // 结论回告举报人
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $buyer->user_id,
            'type' => 'report.handled',
        ]);

        // 已出结论的工单不能被处置第二次
        $this->postJson("/api/v1/admin/reports/{$reportId}/handle", ['result' => 'dismissed'])
            ->assertStatus(409);
    }

    public function test_duplicate_pending_report_and_self_report_are_rejected(): void
    {
        $seller = $this->makeUser('dup_seller', 'seller');
        $buyer = $this->makeUser('dup_buyer');
        $listing = $this->makeListing($seller);

        Sanctum::actingAs($buyer);

        $payload = [
            'target_type' => 'listing',
            'target_id' => $listing->listing_id,
            'reason' => 'spam',
        ];

        $this->postJson('/api/v1/reports', $payload)->assertCreated();
        $this->postJson('/api/v1/reports', $payload)->assertStatus(409);

        // 卖家举报自己的商品：422
        Sanctum::actingAs($seller);
        $this->postJson('/api/v1/reports', $payload)->assertStatus(422);

        // 举报自己这个用户：同样 422
        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/reports', [
            'target_type' => 'user',
            'target_id' => $buyer->user_id,
            'reason' => 'other',
        ])->assertStatus(422);

        $this->assertSame(1, Report::query()->count());
    }

    public function test_invisible_or_missing_targets_are_404(): void
    {
        $author = $this->makeUser('hidden_author');
        $reporter = $this->makeUser('hidden_reporter');
        $pending = $this->makePost($author, PostStatus::Pending);

        Sanctum::actingAs($reporter);

        // 待审帖对外不可见，举报它等同不存在（不泄露内容是否存在）
        $this->postJson('/api/v1/reports', [
            'target_type' => 'post',
            'target_id' => $pending->post_id,
            'reason' => 'abuse',
        ])->assertStatus(404);

        $this->postJson('/api/v1/reports', [
            'target_type' => 'listing',
            'target_id' => 999999,
            'reason' => 'fraud',
        ])->assertStatus(404);

        $this->postJson('/api/v1/reports', [
            'target_type' => 'user',
            'target_id' => 999999,
            'reason' => 'other',
        ])->assertStatus(404);

        $this->assertSame(0, Report::query()->count());
    }

    public function test_dismissed_report_can_be_reopened_with_new_evidence(): void
    {
        $seller = $this->makeUser('reopen_seller', 'seller');
        $buyer = $this->makeUser('reopen_buyer');
        $admin = $this->makeUser('reopen_admin', 'admin');
        $listing = $this->makeListing($seller);

        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/reports', [
            'target_type' => 'listing',
            'target_id' => $listing->listing_id,
            'reason' => 'fake',
        ])->assertCreated();

        $report = Report::query()->firstOrFail();

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/reports/{$report->report_id}/handle", [
            'result' => 'dismissed',
            'note' => '暂未发现违规',
        ])->assertOk()->assertJsonPath('data.status', 'dismissed');

        // 同一目标再次举报：复用原工单，不新增记录，状态回到 pending 且证据被覆盖
        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/reports', [
            'target_type' => 'listing',
            'target_id' => $listing->listing_id,
            'reason' => 'fraud',
            'detail' => '补充聊天记录：收钱不发货',
        ])->assertCreated()->assertJsonPath('data.report_id', (int) $report->report_id);

        $this->assertSame(1, Report::query()->count());

        $report->refresh();
        $this->assertSame('pending', $report->status->value);
        $this->assertSame('fraud', $report->reason->value);
        $this->assertNull($report->handled_at);
        $this->assertNull($report->handled_by);
    }

    public function test_report_queue_is_admin_only(): void
    {
        Sanctum::actingAs($this->makeUser('plain_buyer'));

        $this->getJson('/api/v1/admin/reports')->assertStatus(403);
        $this->postJson('/api/v1/admin/reports/1/handle', ['result' => 'handled'])->assertStatus(403);
    }

    public function test_post_only_accepts_keys_from_posts_folder(): void
    {
        config(['goat.moderation.driver' => 'keyword', 'goat.moderation.keywords' => ['外挂']]);

        $author = $this->makeUser('image_author');
        Sanctum::actingAs($author);

        // 混用商品图 key：拒绝（不能让帖子蹭别的目录对象）
        $this->postJson('/api/v1/posts', [
            'content' => '好看',
            'images' => ['listings/2026/11/stolen.jpg'],
        ])->assertStatus(422);

        $this->assertSame(0, Post::query()->count());

        $this->postJson('/api/v1/posts', [
            'content' => '',
            'images' => ['posts/2026/11/aaa-111.jpg', 'posts/2026/11/bbb-222.png'],
        ])->assertCreated()->assertJsonPath('data.status', 'approved')
            ->assertJsonCount(2, 'data.images');
    }

    public function test_post_image_upload_stores_under_posts_folder(): void
    {
        Storage::fake('local');
        config(['goat.media.disk' => 'local']);

        $author = $this->makeUser('upload_author');

        // 本机 PHP 未装 GD，用带 MIME 的假文件即可满足 image 规则
        $this->post('/api/v1/posts/media', [
            'file' => UploadedFile::fake()->create('shot.jpg', 120, 'image/jpeg'),
        ])->assertStatus(401);

        Sanctum::actingAs($author);

        $response = $this->post('/api/v1/posts/media', [
            'file' => UploadedFile::fake()->create('shot.jpg', 120, 'image/jpeg'),
        ])->assertCreated();

        $key = (string) $response->json('data.key');

        $this->assertStringStartsWith('posts/', $key);
        $this->assertMatchesRegularExpression('#^posts/[0-9]{4}/[0-9]{2}/[A-Za-z0-9_\-]+\.jpg$#', $key);
        Storage::disk('local')->assertExists($key);
    }
}

<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 社群帖与机审管线（T6）。
 *
 * 核心口径：
 * 1. 命中词表 → 落库 rejected（留痕），但绝不出现在 feed；
 * 2. 机审通过默认直接发布；关掉自动发布后必须人工审；
 * 3. pending 帖对他人 404、对作者可见（能看到审核状态）；
 * 4. 人工审核只处理 pending，重复审核 409；
 * 5. 作者可撤回，他人不可。
 */
class CommunityFlowTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();

        // 词表来自环境变量，测试里显式注入，避免依赖本机 .env
        config([
            'goat.moderation.driver' => 'keyword',
            'goat.moderation.keywords' => ['外挂', '代拍'],
        ]);
    }

    public function test_keyword_hit_is_rejected_and_never_reaches_feed(): void
    {
        $user = $this->makeUser('post_user');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/posts', [
            'content' => '出个外挂脚本，稳定不封号',
        ])->assertStatus(422);

        $response->assertJsonPath('data.status', 'rejected');
        $this->assertStringContainsString('外挂', (string) $response->json('data.reject_reason'));

        // 违规帖留痕（可申诉复盘），但 feed 与公开详情都看不到
        $this->assertSame(1, Post::query()->count());
        $this->getJson('/api/v1/posts')->assertOk()->assertJsonPath('meta.total', 0);

        $postId = (int) Post::query()->value('post_id');
        $this->getJson("/api/v1/posts/{$postId}")->assertStatus(404);

        // 作者在「我的帖子」里能看到未通过原因
        $this->getJson('/api/v1/me/posts')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'rejected')
            ->assertJsonPath('data.0.reject_reason', $response->json('data.reject_reason'));
    }

    public function test_clean_post_publishes_immediately_and_shows_in_feed(): void
    {
        $author = $this->makeUser('clean_author');
        Sanctum::actingAs($author);

        $this->postJson('/api/v1/posts', [
            'content' => '今天开箱：初音未来交响乐 1/7，涂装细节很稳',
            'images' => ['posts/2026/11/abc.jpg'],
        ])->assertCreated()->assertJsonPath('data.status', 'approved');

        $this->getJson('/api/v1/posts')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.author.user_id', (int) $author->user_id)
            ->assertJsonPath('data.0.status_label', '已发布');

        $postId = (int) Post::query()->value('post_id');

        // 关键词搜索命中
        $this->getJson('/api/v1/posts?keyword=初音')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        // LIKE 通配符被转义：输入 % 不该变成全表匹配
        $this->getJson('/api/v1/posts?keyword=%')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->getJson("/api/v1/posts/{$postId}")->assertOk()->assertJsonPath('data.post_id', $postId);
    }

    public function test_manual_review_queue_when_auto_publish_disabled(): void
    {
        config(['goat.community.auto_publish_on_pass' => false]);

        $author = $this->makeUser('pending_author');
        $admin = $this->makeUser('site_admin', 'admin');

        Sanctum::actingAs($author);
        $postId = (int) $this->postJson('/api/v1/posts', ['content' => '求鉴定：这只有没有补色'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->json('data.post_id');

        // 待审帖不进 feed，他人详情 404，作者自己可见
        $this->getJson('/api/v1/posts')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson("/api/v1/posts/{$postId}")->assertStatus(404);
        $this->getJson('/api/v1/me/posts')->assertOk()->assertJsonPath('data.0.status', 'pending');

        // 普通用户不能进审核队列
        $this->getJson('/api/v1/admin/posts')->assertStatus(403);

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/posts')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.moderation.verdict', 'pass');

        $this->postJson("/api/v1/admin/posts/{$postId}/review", ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        // 重复审核：已出结论的帖子不再处理
        $this->postJson("/api/v1/admin/posts/{$postId}/review", ['action' => 'reject', 'reason' => '改判'])
            ->assertStatus(409);

        $this->getJson('/api/v1/posts')->assertOk()->assertJsonPath('meta.total', 1);

        // 作者收到审核通过通知
        $this->assertSame(1, UserNotification::query()
            ->where('user_id', $author->user_id)
            ->where('type', 'post.approved')
            ->count());
    }

    public function test_reject_requires_reason_and_author_can_withdraw_only_own_post(): void
    {
        config(['goat.community.auto_publish_on_pass' => false]);

        $author = $this->makeUser('withdraw_author');
        $stranger = $this->makeUser('withdraw_stranger');
        $admin = $this->makeUser('review_admin', 'admin');

        Sanctum::actingAs($author);
        $postId = (int) $this->postJson('/api/v1/posts', ['content' => '这条会被驳回'])
            ->assertCreated()
            ->json('data.post_id');

        // 驳回必须给理由
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/posts/{$postId}/review", ['action' => 'reject'])
            ->assertStatus(422);

        $this->postJson("/api/v1/admin/posts/{$postId}/review", [
            'action' => 'reject',
            'reason' => '疑似广告',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        // 他人不能撤回
        Sanctum::actingAs($stranger);
        $this->deleteJson("/api/v1/posts/{$postId}")->assertStatus(403);

        // 作者撤回：幂等，重复撤回仍 200
        Sanctum::actingAs($author);
        $this->deleteJson("/api/v1/posts/{$postId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'withdrawn');

        $this->deleteJson("/api/v1/posts/{$postId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'withdrawn');

        // 通知只发一条（dedupe_key）
        $this->assertSame(1, UserNotification::query()
            ->where('user_id', $author->user_id)
            ->where('type', 'post.rejected')
            ->count());
    }

    public function test_empty_post_is_rejected_by_validation(): void
    {
        $user = $this->makeUser('empty_post_user');
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/posts', ['content' => '   '])->assertStatus(422);
        $this->assertSame(0, Post::query()->count());
    }
}

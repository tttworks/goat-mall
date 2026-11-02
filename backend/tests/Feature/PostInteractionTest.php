<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace Tests\Feature;

use App\Enums\CommentStatus;
use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\Report;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 帖子互动：点赞与评论（T6）。
 *
 * 这一组守的是三件容易做错的事：
 * 1. 点赞必须幂等（POST 重复点击不报错不重复计数），取消后计数归零；
 * 2. 评论计数只算「已通过机审」的，否则会出现「计数 5、列表 3 条」；
 * 3. 未过审/已撤回的帖子不能被评论（404），删除评论只能删自己的。
 */
class PostInteractionTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $username): User
    {
        return User::query()->create([
            'username' => $username,
            'password' => 'Goat@2026',
            'role' => 'buyer',
            'status' => 'active',
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

    public function test_like_is_idempotent_and_reports_viewer_state(): void
    {
        $author = $this->makeUser('like_author');
        $fan = $this->makeUser('like_fan');
        $post = $this->makePost($author);

        Sanctum::actingAs($fan);

        $this->postJson("/api/v1/posts/{$post->post_id}/like")
            ->assertOk()
            ->assertJsonPath('data.liked', true)
            ->assertJsonPath('data.likes_count', 1);

        // 重复点赞：幂等，不报错也不重复计数
        $this->postJson("/api/v1/posts/{$post->post_id}/like")
            ->assertOk()
            ->assertJsonPath('data.likes_count', 1);

        // feed 里带当前用户的 liked 标记
        $this->getJson('/api/v1/posts?per_page=10')
            ->assertOk()
            ->assertJsonPath('data.0.liked', true)
            ->assertJsonPath('data.0.likes_count', 1);

        // 换个查看者：liked=false，但计数一致
        Sanctum::actingAs($author);
        $this->getJson('/api/v1/posts?per_page=10')
            ->assertOk()
            ->assertJsonPath('data.0.liked', false)
            ->assertJsonPath('data.0.likes_count', 1);

        // 取消点赞：计数归零
        Sanctum::actingAs($fan);
        $this->deleteJson("/api/v1/posts/{$post->post_id}/like")
            ->assertOk()
            ->assertJsonPath('data.liked', false)
            ->assertJsonPath('data.likes_count', 0);

        $this->deleteJson("/api/v1/posts/{$post->post_id}/like")
            ->assertOk()
            ->assertJsonPath('data.likes_count', 0);
    }

    public function test_comments_are_listed_and_counted_only_when_approved(): void
    {
        config(['goat.moderation.driver' => 'keyword', 'goat.moderation.keywords' => ['外挂']]);

        $author = $this->makeUser('comment_author');
        $fan = $this->makeUser('comment_fan');
        $post = $this->makePost($author);

        Sanctum::actingAs($fan);

        $this->postJson("/api/v1/posts/{$post->post_id}/comments", ['content' => '这个配色真好看'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.author.user_id', (int) $fan->user_id);

        // 命中词表：422，但仍留档（rejected），不进列表也不计数
        $this->postJson("/api/v1/posts/{$post->post_id}/comments", ['content' => '我这有外挂'])
            ->assertStatus(422);

        $this->assertSame(2, PostComment::query()->count());

        $this->getJson("/api/v1/posts/{$post->post_id}/comments")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.content', '这个配色真好看');

        // 详情页的评论数必须与列表一致
        $this->getJson("/api/v1/posts/{$post->post_id}")
            ->assertOk()
            ->assertJsonPath('data.comments_count', 1);
    }

    public function test_cannot_comment_on_invisible_post(): void
    {
        $author = $this->makeUser('hidden_post_author');
        $fan = $this->makeUser('hidden_post_fan');
        $pending = $this->makePost($author, PostStatus::Pending);

        Sanctum::actingAs($fan);

        $this->postJson("/api/v1/posts/{$pending->post_id}/comments", ['content' => '在吗'])
            ->assertStatus(404);

        $this->getJson("/api/v1/posts/{$pending->post_id}/comments")
            ->assertStatus(404);

        // 作者本人能看到自己的待审帖，也允许自己先评论（不会外泄）
        Sanctum::actingAs($author);
        $this->getJson("/api/v1/posts/{$pending->post_id}/comments")->assertOk();
    }

    public function test_only_author_can_delete_own_comment(): void
    {
        $author = $this->makeUser('del_author');
        $fan = $this->makeUser('del_fan');
        $other = $this->makeUser('del_other');
        $post = $this->makePost($author);

        Sanctum::actingAs($fan);
        $commentId = (int) $this->postJson("/api/v1/posts/{$post->post_id}/comments", ['content' => '排队等开箱'])
            ->assertCreated()
            ->json('data.comment_id');

        Sanctum::actingAs($other);
        $this->deleteJson("/api/v1/posts/{$post->post_id}/comments/{$commentId}")
            ->assertStatus(403);

        Sanctum::actingAs($fan);
        $this->deleteJson("/api/v1/posts/{$post->post_id}/comments/{$commentId}")
            ->assertOk();

        // 软删：列表消失，但记录还在（运营复盘用）
        $this->getJson("/api/v1/posts/{$post->post_id}/comments")
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->assertSoftDeleted('post_comments', ['comment_id' => $commentId]);
        $this->assertSame(1, PostComment::withTrashed()->count());

        $this->deleteJson("/api/v1/posts/{$post->post_id}/comments/{$commentId}")
            ->assertStatus(404);
    }

    public function test_my_posts_include_pending_and_withdrawn_with_counts(): void
    {
        $author = $this->makeUser('mine_author');
        $fan = $this->makeUser('mine_fan');

        $approved = $this->makePost($author, PostStatus::Approved, '第一条');
        $this->makePost($author, PostStatus::Pending, '第二条待审');

        Sanctum::actingAs($fan);
        $this->postJson("/api/v1/posts/{$approved->post_id}/like")->assertOk();
        $this->postJson("/api/v1/posts/{$approved->post_id}/comments", ['content' => '好看'])->assertCreated();

        Sanctum::actingAs($author);
        $this->getJson('/api/v1/me/posts?per_page=10')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.status', 'pending');

        $approvedRow = collect($this->getJson('/api/v1/me/posts?per_page=10')->json('data'))
            ->firstWhere('post_id', (int) $approved->post_id);

        $this->assertSame(1, $approvedRow['likes_count']);
        $this->assertSame(1, $approvedRow['comments_count']);

        // 撤回后仍在「我的帖子」里，状态可解释
        $this->deleteJson("/api/v1/posts/{$approved->post_id}")->assertOk();
        $this->getJson('/api/v1/me/posts?per_page=10')
            ->assertOk()
            ->assertJsonPath('data.1.status', 'withdrawn');

        // 状态筛选在服务端做：分页下前端筛会漏帖
        $this->getJson('/api/v1/me/posts?status=pending')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.status', 'pending');

        // 撤回后已不在 approved 里
        $this->getJson('/api/v1/me/posts?status=approved')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->getJson('/api/v1/me/posts?status=withdrawn')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.status', 'withdrawn');

        $this->getJson('/api/v1/me/posts?status=unknown')->assertStatus(422);
    }

    public function test_interactions_notify_author_but_never_self(): void
    {
        $author = $this->makeUser('notify_author');
        $fan = $this->makeUser('notify_fan');
        $post = $this->makePost($author);

        Sanctum::actingAs($fan);

        $this->postJson("/api/v1/posts/{$post->post_id}/like")->assertOk();
        // 重复点赞不再产生第二条（幂等 + 去重键）
        $this->postJson("/api/v1/posts/{$post->post_id}/like")->assertOk();

        $this->postJson("/api/v1/posts/{$post->post_id}/comments", ['content' => '这条我也想要'])
            ->assertCreated();

        $this->assertSame(1, UserNotification::query()
            ->where('user_id', $author->user_id)
            ->where('type', 'post.liked')
            ->count());

        $commented = UserNotification::query()
            ->where('user_id', $author->user_id)
            ->where('type', 'post.commented')
            ->firstOrFail();

        $this->assertStringContainsString('这条我也想要', (string) $commented->body);
        $this->assertSame((int) $post->post_id, (int) ($commented->payload['post_id'] ?? 0));

        // 自己赞自己 / 自己评自己：不提醒，避免自娱自乐刷满通知中心
        Sanctum::actingAs($author);
        $before = UserNotification::query()->where('user_id', $author->user_id)->count();

        $this->postJson("/api/v1/posts/{$post->post_id}/like")->assertOk();
        $this->postJson("/api/v1/posts/{$post->post_id}/comments", ['content' => '自评一句'])->assertCreated();

        $this->assertSame($before, UserNotification::query()->where('user_id', $author->user_id)->count());
    }

    public function test_comment_can_be_reported_and_hidden_targets_return_404(): void
    {
        $author = $this->makeUser('creport_author');
        $fan = $this->makeUser('creport_fan');
        $post = $this->makePost($author);

        Sanctum::actingAs($fan);
        $commentId = (int) $this->postJson("/api/v1/posts/{$post->post_id}/comments", ['content' => '加我微信卖你低价'])
            ->assertCreated()
            ->json('data.comment_id');

        // 别人举报该评论：工单只记 target_type=comment
        Sanctum::actingAs($author);
        $this->postJson('/api/v1/reports', [
            'target_type' => 'comment',
            'target_id' => $commentId,
            'reason' => 'spam',
        ])->assertCreated()->assertJsonPath('data.status', 'pending');

        // 重复举报待处理中的同一评论：409
        $this->postJson('/api/v1/reports', [
            'target_type' => 'comment',
            'target_id' => $commentId,
            'reason' => 'spam',
        ])->assertStatus(409);

        // 评论作者举报自己的评论：422
        Sanctum::actingAs($fan);
        $this->postJson('/api/v1/reports', [
            'target_type' => 'comment',
            'target_id' => $commentId,
            'reason' => 'other',
        ])->assertStatus(422);

        $this->assertSame(1, Report::query()->where('target_type', 'comment')->count());

        // 已删除的评论按「不存在」处理，不用 403 泄露它曾经存在
        Sanctum::actingAs($fan);
        $this->deleteJson("/api/v1/posts/{$post->post_id}/comments/{$commentId}")->assertOk();

        Sanctum::actingAs($author);
        $this->postJson('/api/v1/reports', [
            'target_type' => 'comment',
            'target_id' => $commentId,
            'reason' => 'spam',
        ])->assertStatus(404);

        // 未过审的评论同样不可举报
        $hidden = PostComment::query()->create([
            'post_id' => $post->post_id,
            'user_id' => $fan->user_id,
            'content' => '待审评论',
            'status' => CommentStatus::Pending,
        ]);

        Sanctum::actingAs($author);
        $this->postJson('/api/v1/reports', [
            'target_type' => 'comment',
            'target_id' => $hidden->comment_id,
            'reason' => 'abuse',
        ])->assertStatus(404);
    }
}

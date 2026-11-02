<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 私信（T6）。
 *
 * 核心口径：
 * 1. 会话按 (小 id, 大 id) 归一化，双向前后开都只有一条；
 * 2. 非成员一律 404（不泄露会话存在性）；
 * 3. 拉取历史即已读，未读数随之归零；
 * 4. 会话列表按最后消息时间倒序，未读数按对端消息计。
 */
class ImFlowTest extends TestCase
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

    public function test_conversation_open_is_idempotent_in_both_directions(): void
    {
        $a = $this->makeUser('im_a');
        $b = $this->makeUser('im_b');

        Sanctum::actingAs($a);
        $first = (int) $this->postJson('/api/v1/conversations', ['peer_id' => $b->user_id])
            ->assertCreated()
            ->json('data.conversation_id');

        // 反向再开：落到同一条会话，返回 200
        Sanctum::actingAs($b);
        $second = (int) $this->postJson('/api/v1/conversations', ['peer_id' => $a->user_id])
            ->assertOk()
            ->json('data.conversation_id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Conversation::query()->count());

        // 归一化约束：小 id 在前
        $conversation = Conversation::query()->firstOrFail();
        $this->assertLessThan((int) $conversation->user_b_id, (int) $conversation->user_a_id);

        // 不能和自己开会话
        $this->postJson('/api/v1/conversations', ['peer_id' => $b->user_id])->assertStatus(422);
        // 对方不存在
        $this->postJson('/api/v1/conversations', ['peer_id' => 999999])->assertStatus(404);
    }

    public function test_messages_flow_marks_read_and_counts_unread(): void
    {
        $a = $this->makeUser('im_sender');
        $b = $this->makeUser('im_receiver');

        Sanctum::actingAs($a);
        $conversationId = (int) $this->postJson('/api/v1/conversations', ['peer_id' => $b->user_id])
            ->assertCreated()
            ->json('data.conversation_id');

        $this->postJson("/api/v1/conversations/{$conversationId}/messages", [
            'body' => '这只还在吗？能否小刀',
        ])->assertCreated()->assertJsonPath('data.is_mine', true);

        // 空消息拒绝
        $this->postJson("/api/v1/conversations/{$conversationId}/messages", ['body' => '   '])
            ->assertStatus(422);

        // 接收方列表：未读 1，预览为最后一句
        Sanctum::actingAs($b);
        $this->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.unread_count', 1)
            ->assertJsonPath('data.0.last_message_preview', '这只还在吗？能否小刀')
            ->assertJsonPath('data.0.peer.user_id', (int) $a->user_id);

        // 拉取历史即已读
        $this->getJson("/api/v1/conversations/{$conversationId}/messages")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.is_mine', false);

        $this->getJson('/api/v1/conversations')->assertOk()->assertJsonPath('data.0.unread_count', 0);

        // 回复后，发送方看到未读 1
        $this->postJson("/api/v1/conversations/{$conversationId}/messages", ['body' => '在的，可以小刀 50'])
            ->assertCreated();

        Sanctum::actingAs($a);
        $this->getJson('/api/v1/conversations')->assertOk()->assertJsonPath('data.0.unread_count', 1);

        // 历史按时间正序返回，便于前端直接追加渲染
        $bodies = $this->getJson("/api/v1/conversations/{$conversationId}/messages")
            ->assertOk()
            ->json('data.*.body');

        $this->assertSame(['这只还在吗？能否小刀', '在的，可以小刀 50'], $bodies);
    }

    public function test_non_member_gets_404_and_cannot_send(): void
    {
        $a = $this->makeUser('im_owner');
        $b = $this->makeUser('im_peer');
        $outsider = $this->makeUser('im_outsider');

        Sanctum::actingAs($a);
        $conversationId = (int) $this->postJson('/api/v1/conversations', ['peer_id' => $b->user_id])
            ->assertCreated()
            ->json('data.conversation_id');

        $this->postJson("/api/v1/conversations/{$conversationId}/messages", ['body' => 'hi'])
            ->assertCreated();

        Sanctum::actingAs($outsider);
        // 非成员按「不存在」处理，不返回 403
        $this->getJson("/api/v1/conversations/{$conversationId}/messages")->assertStatus(404);
        $this->postJson("/api/v1/conversations/{$conversationId}/messages", ['body' => '插话'])
            ->assertStatus(404);

        // 未读状态没有被越权访问改写
        $this->assertSame(1, Message::query()->whereNull('read_at')->count());
    }
}

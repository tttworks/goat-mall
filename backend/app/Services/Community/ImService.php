<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Community;

use App\Events\MessageSent;
use App\Exceptions\SocialException;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\MediaUrl;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 私信（T6）：会话归一化 + 消息落库 + 未读计数。
 *
 * 本轮只做 HTTP 拉取（列表/历史/发送），实时推送（Reverb WS）留到后续；
 * 表结构已按「按会话增量拉取」设计（message_id 单调递增），接 WS 时不用改表。
 *
 * 越权口径：不是会话成员一律 404（不用 403），不暴露「这个会话存在」。
 */
class ImService
{
    /**
     * 开会话（幂等）：同一对用户无论谁发起、点几次，都只有一条。
     *
     * @return array{conversation: Conversation, created: bool}
     */
    public function open(int $userId, int $peerId): array
    {
        if ($userId === $peerId) {
            throw SocialException::invalid('不能给自己发私信');
        }

        if (! User::query()->whereKey($peerId)->exists()) {
            throw SocialException::notFound('对方用户不存在');
        }

        [$a, $b] = Conversation::normalize($userId, $peerId);

        $existing = Conversation::query()
            ->where('user_a_id', $a)
            ->where('user_b_id', $b)
            ->first();

        if ($existing !== null) {
            return ['conversation' => $existing, 'created' => false];
        }

        try {
            $conversation = Conversation::query()->create([
                'user_a_id' => $a,
                'user_b_id' => $b,
            ]);

            return ['conversation' => $conversation, 'created' => true];
        } catch (UniqueConstraintViolationException) {
            // 并发同时开会话：唯一键兜底，读回同一条
            $conversation = Conversation::query()
                ->where('user_a_id', $a)
                ->where('user_b_id', $b)
                ->firstOrFail();

            return ['conversation' => $conversation, 'created' => false];
        }
    }

    public function send(int $userId, int $conversationId, string $body): Message
    {
        $body = trim($body);

        if ($body === '') {
            throw SocialException::invalid('消息内容不能为空');
        }

        $maxLength = max(1, (int) config('goat.im.max_length', 1000));

        if (mb_strlen($body) > $maxLength) {
            throw SocialException::invalid(sprintf('消息长度不能超过 %d 字', $maxLength));
        }

        $conversation = $this->findMemberConversation($userId, $conversationId);
        $body = mb_substr($body, 0, $maxLength);

        $message = DB::transaction(function () use ($conversation, $userId, $body) {
            $message = Message::query()->create([
                'conversation_id' => (int) $conversation->conversation_id,
                'sender_id' => $userId,
                'body' => $body,
            ]);

            // 列表页只读这一行，不扫 messages 表
            $conversation->forceFill([
                'last_message_preview' => mb_substr($body, 0, 120),
                'last_message_at' => $message->created_at ?? Carbon::now(),
            ])->save();

            return $message;
        }, 3);

        $this->broadcast($message, $conversation, $userId);

        return $message;
    }

    /**
     * 会话历史：倒序分页取最近 N 条，再翻回正序给前端直接渲染。
     *
     * @return array{conversation: Conversation, messages: array<int, Message>, total: int, page: int, per_page: int}
     */
    public function messages(int $userId, int $conversationId, int $perPage = 30): array
    {
        $conversation = $this->findMemberConversation($userId, $conversationId);

        // 拉取即已读：对端发来的未读消息在这一刻统一置读
        Message::query()
            ->where('conversation_id', (int) $conversation->conversation_id)
            ->where('sender_id', '!=', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

        $page = Message::query()
            ->where('conversation_id', (int) $conversation->conversation_id)
            ->orderByDesc('message_id')
            ->paginate($perPage);

        return [
            'conversation' => $conversation->refresh(),
            'messages' => array_reverse($page->items()),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'per_page' => $page->perPage(),
        ];
    }

    public function paginate(int $userId, int $perPage = 20): LengthAwarePaginator
    {
        return Conversation::query()
            ->where(function ($query) use ($userId) {
                $query->where('user_a_id', $userId)->orWhere('user_b_id', $userId);
            })
            ->orderByRaw('COALESCE(last_message_at, created_at) DESC')
            ->paginate($perPage);
    }

    /**
     * 会话列表载荷：对端资料 + 未读数（一次 group by 批量取，避免 N+1）。
     *
     * @param  array<int, Conversation>  $conversations
     * @return array<int, array<string, mixed>>
     */
    public function summaries(int $userId, array $conversations): array
    {
        if ($conversations === []) {
            return [];
        }

        $peerIds = array_map(static fn (Conversation $c) => $c->peerOf($userId), $conversations);
        $peers = User::query()->whereKey(array_values(array_unique($peerIds)))->get()->keyBy('user_id');

        $conversationIds = array_map(static fn (Conversation $c) => (int) $c->conversation_id, $conversations);

        $unread = Message::query()
            ->whereIn('conversation_id', $conversationIds)
            ->where('sender_id', '!=', $userId)
            ->whereNull('read_at')
            ->selectRaw('conversation_id, COUNT(*) AS total')
            ->groupBy('conversation_id')
            ->pluck('total', 'conversation_id');

        return array_map(function (Conversation $conversation) use ($userId, $peers, $unread) {
            $peer = $peers->get($conversation->peerOf($userId));

            return [
                'conversation_id' => (int) $conversation->conversation_id,
                'peer' => [
                    'user_id' => $conversation->peerOf($userId),
                    'nickname' => $peer?->nickname,
                    'avatar_key' => $peer?->avatar_key,
                    // C 端只认 URL：裸 key 在小程序里无法直接渲染
                    'avatar_url' => MediaUrl::for($peer?->avatar_key),
                ],
                'last_message_preview' => $conversation->last_message_preview,
                'last_message_at' => $conversation->last_message_at?->toIso8601String(),
                'unread_count' => (int) ($unread[(int) $conversation->conversation_id] ?? 0),
            ];
        }, $conversations);
    }

    /** @return array<string, mixed> */
    public function messagePayload(Message $message, int $viewerId): array
    {
        return [
            'message_id' => (int) $message->message_id,
            'conversation_id' => (int) $message->conversation_id,
            'sender_id' => (int) $message->sender_id,
            'body' => $message->body,
            'is_mine' => (int) $message->sender_id === $viewerId,
            'read_at' => $message->read_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    /**
     * 实时推送（T6）：只推给收信人；推送失败不影响发信结果。
     *
     * 事务外派发：避免收信人在消息提交前被推到一条「回查还查不到」的消息。
     */
    private function broadcast(Message $message, Conversation $conversation, int $senderId): void
    {
        if (! config('goat.ws.enabled')) {
            return;
        }

        try {
            MessageSent::dispatch($message, $conversation->peerOf($senderId));
        } catch (\Throwable $e) {
            Log::channel('security')->warning('im.推送失败', [
                'message_id' => $message->message_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function findMemberConversation(int $userId, int $conversationId): Conversation
    {
        $conversation = Conversation::query()->whereKey($conversationId)->first();

        // 非成员按「不存在」处理：403 会泄露会话 id 有效性
        if ($conversation === null || ! $conversation->hasMember($userId)) {
            throw SocialException::notFound('会话不存在');
        }

        return $conversation;
    }
}

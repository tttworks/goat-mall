<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Community;

use App\Enums\CommentStatus;
use App\Exceptions\SocialException;
use App\Models\Post;
use App\Models\PostComment;
use App\Services\Moderation\Contracts\ModerationChannel;
use App\Support\MediaUrl;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 帖子评论（T6）。
 *
 * 与发帖同一套机审：先落库（pending）再出结论，被拦下的评论留痕而不是静默消失；
 * 列表只出 approved，作者自己能通过接口返回状态看到「审核中 / 未通过 + 原因」。
 */
class CommentService
{
    public function __construct(
        private readonly PostService $posts,
        private readonly ModerationChannel $moderation,
    ) {}

    /**
     * 评论列表：目标帖必须对当前用户可见（未过审的帖子 404）。
     *
     * @throws SocialException
     */
    public function paginate(int $postId, ?int $viewerId, int $perPage = 20): LengthAwarePaginator
    {
        $post = $this->posts->show($postId, $viewerId);

        return PostComment::query()
            ->where('post_id', $post->post_id)
            ->where('status', CommentStatus::Approved->value)
            ->with('author')
            ->orderBy('comment_id')
            ->paginate($perPage);
    }

    /**
     * 发表评论：approved 立即展示；pending 提示审核；rejected 直接 422 并保留记录。
     *
     * @throws SocialException
     */
    public function create(int $userId, int $postId, string $content): PostComment
    {
        $post = $this->posts->show($postId, $userId);

        $content = trim($content);

        if ($content === '') {
            throw SocialException::invalid('评论内容不能为空');
        }

        $content = mb_substr($content, 0, 500);
        $result = $this->moderation->screen($content, []);

        $comment = PostComment::query()->create([
            'post_id' => $post->post_id,
            'user_id' => $userId,
            'content' => $content,
            'status' => CommentStatus::Pending,
            'moderation' => $result->toArray(),
        ]);

        if ($result->rejected()) {
            $comment->update([
                'status' => CommentStatus::Rejected,
                'reject_reason' => $result->reason === '' ? '内容未通过机审' : $result->reason,
            ]);

            throw SocialException::invalid('评论未通过审核：'.$comment->reject_reason);
        }

        if ($result->passed()) {
            $comment->update(['status' => CommentStatus::Approved]);
        }

        return $comment->refresh();
    }

    /**
     * 删除自己的评论（软删）：保留记录，运营复盘时能看到用户自己删了什么。
     *
     * @throws SocialException
     */
    public function delete(int $userId, int $postId, int $commentId): PostComment
    {
        $comment = PostComment::query()
            ->where('post_id', $postId)
            ->whereKey($commentId)
            ->first();

        if ($comment === null) {
            throw SocialException::notFound('评论不存在');
        }

        if ((int) $comment->user_id !== $userId) {
            throw SocialException::forbidden('只能删除自己的评论');
        }

        $comment->delete();

        return $comment;
    }

    /** @return array<string, mixed> */
    public function payload(PostComment $comment): array
    {
        $author = $comment->author;

        return [
            'comment_id' => (int) $comment->comment_id,
            'post_id' => (int) $comment->post_id,
            'content' => $comment->content,
            'status' => $comment->status->value,
            'status_label' => $comment->status->label(),
            'reject_reason' => $comment->status === CommentStatus::Rejected ? $comment->reject_reason : null,
            'created_at' => $comment->created_at?->toIso8601String(),
            'author' => [
                'user_id' => (int) $comment->user_id,
                'nickname' => $author?->nickname,
                'username' => $author?->username,
                'avatar_url' => MediaUrl::for($author?->avatar_key),
            ],
        ];
    }
}

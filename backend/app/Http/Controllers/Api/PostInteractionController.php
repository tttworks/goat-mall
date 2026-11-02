<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api;

use App\Http\Requests\CommentStoreRequest;
use App\Models\Post;
use App\Models\PostComment;
use App\Services\Community\CommentService;
use App\Services\Community\PostService;
use App\Services\Notify\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * 帖子互动：点赞与评论（T6）。
 *
 * 点赞做成「POST 点赞 / DELETE 取消」而不是 toggle：
 * 客户端重试、弱网重复提交时，toggle 会把用户刚点的赞又取消掉，语义不可预期；
 * 幂等的显式动词怎么重试都停在同一个状态。
 */
class PostInteractionController extends Controller
{
    public function __construct(
        private readonly PostService $posts,
        private readonly CommentService $comments,
        private readonly NotificationService $notifications,
    ) {}

    public function like(Request $request, int $post): JsonResponse
    {
        $userId = (int) $request->user()->user_id;
        $result = $this->posts->like($userId, $post);

        // 只在「从未点赞变成已点赞」时提醒：重复点赞不刷屏；
        // 去重键按 (帖子, 点赞人)，取消再点也不会重复通知作者。
        if ($result['liked']) {
            $this->notifyAuthor(
                $post,
                $userId,
                'post.liked',
                '有人赞了你的帖子',
                $this->actorName($request).'赞了你的帖子',
                'post:'.$post.':like:'.$userId
            );
        }

        return response()->json(['data' => $result]);
    }

    public function unlike(Request $request, int $post): JsonResponse
    {
        return response()->json([
            'data' => $this->posts->unlike((int) $request->user()->user_id, $post),
        ]);
    }

    public function comments(Request $request, int $post): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $page = $this->comments->paginate(
            $post,
            $request->user('sanctum')?->user_id,
            (int) ($validated['per_page'] ?? 20)
        );

        return response()->json([
            'data' => array_map(fn (PostComment $comment) => $this->comments->payload($comment), $page->items()),
            'meta' => [
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }

    public function createComment(CommentStoreRequest $request, int $post): JsonResponse
    {
        $userId = (int) $request->user()->user_id;
        $comment = $this->comments->create($userId, $post, (string) $request->validated('content'));

        // 待审评论不通知：还没展示出来就提醒作者「有人评论」，点进去却发现列表是空的
        if ($comment->isVisible()) {
            $this->notifyAuthor(
                $post,
                $userId,
                'post.commented',
                '有人评论了你的帖子',
                $this->actorName($request).'：'.mb_substr((string) $comment->content, 0, 60),
                'comment:'.$comment->comment_id.':created'
            );
        }

        return response()->json([
            'data' => $this->comments->payload($comment),
            'message' => $comment->isVisible() ? '评论已发布' : '评论已提交审核，通过后展示',
        ], 201);
    }

    public function destroyComment(Request $request, int $post, int $comment): JsonResponse
    {
        $this->comments->delete((int) $request->user()->user_id, $post, $comment);

        return response()->json(['message' => '评论已删除']);
    }

    /**
     * 内容被互动时提醒作者，自己赞自己/评自己不提醒。
     *
     * 通知是副作用，失败不能影响互动本身——NotificationService::push 内部已吞异常，
     * 这里再兜一层「帖子查不到就直接跳过」。
     */
    private function notifyAuthor(
        int $postId,
        int $actorId,
        string $type,
        string $title,
        string $body,
        string $dedupeKey
    ): void {
        $authorId = (int) Post::query()->whereKey($postId)->value('user_id');

        if ($authorId <= 0 || $authorId === $actorId) {
            return;
        }

        $this->notifications->push(
            $authorId,
            $type,
            $title,
            $body,
            ['post_id' => $postId],
            $dedupeKey
        );
    }

    private function actorName(Request $request): string
    {
        $user = $request->user();

        return (string) ($user?->nickname ?: $user?->username ?: '有人');
    }
}

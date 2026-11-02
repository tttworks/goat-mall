<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api;

use App\Http\Requests\CommentStoreRequest;
use App\Models\PostComment;
use App\Services\Community\CommentService;
use App\Services\Community\PostService;
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
    ) {}

    public function like(Request $request, int $post): JsonResponse
    {
        return response()->json([
            'data' => $this->posts->like((int) $request->user()->user_id, $post),
        ]);
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
        $comment = $this->comments->create(
            (int) $request->user()->user_id,
            $post,
            (string) $request->validated('content')
        );

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
}

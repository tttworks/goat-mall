<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api\Admin;

use App\Enums\PostStatus;
use App\Http\Requests\PostReviewRequest;
use App\Models\Post;
use App\Services\Community\PostService;
use App\Services\Notify\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * 社群帖子人工审核（T6，仅 admin）。
 *
 * 默认只列 pending：已出结论的帖子不进待审队列，避免「重复审核」产生的口径漂移。
 * 允许按状态回看（approved / rejected），但审核动作本身仍受状态机约束——
 * 已出结论的帖子不会被「再审一次」改判，申诉走人工另开流程。
 */
class PostController extends Controller
{
    public function __construct(
        private readonly PostService $posts,
        private readonly NotificationService $notifications,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(PostStatus::class)],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $page = $this->posts->paginate(
            $validated['status'] ?? null,
            (int) ($validated['per_page'] ?? 20)
        );

        return response()->json([
            'data' => array_map(fn (Post $post) => $this->posts->adminPayload($post), $page->items()),
            'meta' => [
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }

    public function review(PostReviewRequest $request, int $post): JsonResponse
    {
        $updated = $this->posts->review(
            $post,
            (string) $request->validated('action'),
            $request->validated('reason')
        );

        $approved = $updated->status->value === 'approved';

        $this->notifications->push(
            (int) $updated->user_id,
            $approved ? 'post.approved' : 'post.rejected',
            $approved ? '你的帖子已通过审核' : '你的帖子未通过审核',
            $approved
                ? '帖子已发布到社群'
                : (string) $updated->reject_reason,
            ['post_id' => (int) $updated->post_id],
            'post:'.$updated->post_id.':review:'.$updated->status->value
        );

        return response()->json(['data' => $this->posts->adminPayload($updated)]);
    }
}

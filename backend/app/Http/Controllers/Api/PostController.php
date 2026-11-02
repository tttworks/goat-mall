<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api;

use App\Enums\PostStatus;
use App\Http\Requests\PostStoreRequest;
use App\Services\Community\PostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * 社群图片帖（T6）。
 *
 * 公开面只有 feed 与详情（且仅 approved）；作者面有「我的帖子」（含审核状态）与撤回。
 * 审核动作在 Admin\PostController，不在这个控制器里——权限不同的东西不放同一个类。
 */
class PostController extends Controller
{
    public function __construct(private readonly PostService $posts) {}

    /** 公开 feed：只出已过审、已发布的帖子 */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $page = $this->posts->feed(
            $validated['keyword'] ?? null,
            (int) ($validated['per_page'] ?? 20)
        );

        return response()->json([
            'data' => $this->posts->payloads($page->items(), $request->user('sanctum')?->user_id),
            'meta' => [
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }

    /** 公开详情：带上查看者身份，才能返回 liked（未登录就是 false） */
    public function show(Request $request, int $post): JsonResponse
    {
        $viewerId = $request->user('sanctum')?->user_id;

        return response()->json([
            'data' => $this->posts->payload($this->posts->show($post, $viewerId), $viewerId),
        ]);
    }

    /** 发布：机审结论立即体现在返回的 status 上（approved / rejected / pending） */
    public function store(PostStoreRequest $request): JsonResponse
    {
        $post = $this->posts->publish(
            (int) $request->user()->user_id,
            [
                'content' => $request->validated('content'),
                'images' => (array) ($request->validated('images') ?? []),
            ]
        );

        $status = $post->status->value;

        return response()->json([
            'data' => $this->posts->payload($post, (int) $request->user()->user_id),
            'message' => match ($status) {
                'approved' => '发布成功',
                'rejected' => '内容未通过审核：'.$post->reject_reason,
                default => '已提交审核，通过后自动发布',
            },
        ], $status === 'rejected' ? 422 : 201);
    }

    public function mine(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'between:1,50'],
            'status' => ['nullable', Rule::enum(PostStatus::class)],
        ]);

        $page = $this->posts->mine(
            (int) $request->user()->user_id,
            (int) ($validated['per_page'] ?? 20),
            $validated['status'] ?? null
        );

        return response()->json([
            'data' => $this->posts->payloads($page->items(), (int) $request->user()->user_id),
            'meta' => [
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }

    /** 撤回：作者主动下架，软删之外保留状态与时间（申诉留证） */
    public function destroy(Request $request, int $post): JsonResponse
    {
        $userId = (int) $request->user()->user_id;
        $updated = $this->posts->withdraw($userId, $post);

        return response()->json(['data' => $this->posts->payload($updated, $userId)]);
    }
}

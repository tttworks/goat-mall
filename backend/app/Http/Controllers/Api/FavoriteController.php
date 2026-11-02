<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api;

use App\Http\Requests\FavoriteStoreRequest;
use App\Models\Favorite;
use App\Services\Social\FavoriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * 收藏（T6）。收藏列表只返回归属当前用户的数据，user_id 一律取自登录态。
 */
class FavoriteController extends Controller
{
    public function __construct(private readonly FavoriteService $favorites) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'target_type' => ['nullable', Rule::in(Favorite::targetTypes())],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $page = $this->favorites->paginate(
            (int) $request->user()->user_id,
            $validated['target_type'] ?? null,
            (int) ($validated['per_page'] ?? 20)
        );

        return response()->json([
            'data' => $this->favorites->summaries($page->items()),
            'meta' => [
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }

    /** 幂等：首次 201，已收藏返回 200（前端连点不弹错） */
    public function store(FavoriteStoreRequest $request): JsonResponse
    {
        $result = $this->favorites->add(
            (int) $request->user()->user_id,
            (string) $request->validated('target_type'),
            (int) $request->validated('target_id')
        );

        return response()->json([
            'data' => [
                'favorite_id' => $result['favorite']->favorite_id,
                'target_type' => $result['favorite']->target_type,
                'target_id' => (int) $result['favorite']->target_id,
                'favorited' => true,
            ],
        ], $result['created'] ? 201 : 200);
    }

    /** 取消收藏幂等：本来没收藏也返回 200，不报 404 */
    public function destroy(Request $request, string $targetType, int $targetId): JsonResponse
    {
        if (! in_array($targetType, Favorite::targetTypes(), true)) {
            return response()->json(['message' => '收藏类型不合法'], 422);
        }

        $removed = $this->favorites->remove((int) $request->user()->user_id, $targetType, $targetId);

        return response()->json([
            'data' => [
                'target_type' => $targetType,
                'target_id' => $targetId,
                'favorited' => false,
                'removed' => $removed,
            ],
        ]);
    }
}

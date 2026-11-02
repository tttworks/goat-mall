<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api;

use App\Services\Social\FollowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * 关注卖家（T6）。路径里的 {seller} 是卖家 user_id，
 * 与 seller_profiles.seller_id 不是同一口径——对外一律用 user_id，避免前端两套 ID。
 */
class FollowController extends Controller
{
    public function __construct(private readonly FollowService $follows) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $page = $this->follows->paginate(
            (int) $request->user()->user_id,
            (int) ($validated['per_page'] ?? 20)
        );

        return response()->json([
            'data' => $this->follows->summaries($page->items()),
            'meta' => [
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }

    /** 幂等：首次 201，已关注返回 200 */
    public function store(Request $request, int $seller): JsonResponse
    {
        $result = $this->follows->follow((int) $request->user()->user_id, $seller);

        return response()->json([
            'data' => [
                'seller_id' => $seller,
                'following' => true,
                'followers' => $this->follows->countFollowers($seller),
            ],
        ], $result['created'] ? 201 : 200);
    }

    public function destroy(Request $request, int $seller): JsonResponse
    {
        $this->follows->unfollow((int) $request->user()->user_id, $seller);

        return response()->json([
            'data' => [
                'seller_id' => $seller,
                'following' => false,
                'followers' => $this->follows->countFollowers($seller),
            ],
        ]);
    }
}

<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api;

use App\Http\Requests\ConversationStoreRequest;
use App\Services\Community\ImService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * 私信会话（T6）：列表 + 开启。会话归属由服务层归一化，接口不接收会话内成员。
 */
class ConversationController extends Controller
{
    public function __construct(private readonly ImService $im) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $userId = (int) $request->user()->user_id;
        $page = $this->im->paginate($userId, (int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => $this->im->summaries($userId, $page->items()),
            'meta' => [
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }

    /** 幂等：首次创建 201，已存在返回 200（前端可以直接进会话） */
    public function store(ConversationStoreRequest $request): JsonResponse
    {
        $userId = (int) $request->user()->user_id;
        $result = $this->im->open($userId, (int) $request->validated('peer_id'));
        $conversation = $result['conversation'];

        return response()->json([
            'data' => $this->im->summaries($userId, [$conversation])[0],
        ], $result['created'] ? 201 : 200);
    }
}

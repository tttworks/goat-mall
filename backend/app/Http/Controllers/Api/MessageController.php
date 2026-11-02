<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api;

use App\Http\Requests\MessageStoreRequest;
use App\Models\Message;
use App\Services\Community\ImService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * 私信消息（T6）：历史拉取 + 发送。
 *
 * 未读口径：拉取历史即视为已读（服务层统一置 read_at），
 * 所以前端每次进会话只需要一次 GET，不必再调「标已读」接口。
 */
class MessageController extends Controller
{
    public function __construct(private readonly ImService $im) {}

    public function index(Request $request, int $conversation): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $userId = (int) $request->user()->user_id;
        $result = $this->im->messages($userId, $conversation, (int) ($validated['per_page'] ?? 30));

        return response()->json([
            'data' => array_map(
                fn (Message $message) => $this->im->messagePayload($message, $userId),
                $result['messages']
            ),
            'conversation' => $this->im->summaries($userId, [$result['conversation']])[0],
            'meta' => [
                'total' => $result['total'],
                'page' => $result['page'],
                'per_page' => $result['per_page'],
            ],
        ]);
    }

    public function store(MessageStoreRequest $request, int $conversation): JsonResponse
    {
        $userId = (int) $request->user()->user_id;

        $message = $this->im->send($userId, $conversation, (string) $request->validated('body'));

        return response()->json([
            'data' => $this->im->messagePayload($message, $userId),
        ], 201);
    }
}

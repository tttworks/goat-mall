<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api;

use App\Services\Notify\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * 通知中心（T6）。只读自己那一份：user_id 取自登录态，不接受入参。
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'unread' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $userId = (int) $request->user()->user_id;
        $page = $this->notifications->paginate(
            $userId,
            isset($validated['unread']) ? (bool) $validated['unread'] : null,
            (int) ($validated['per_page'] ?? 20)
        );

        return response()->json([
            'data' => array_map(
                fn ($notification) => $this->notifications->payload($notification),
                $page->items()
            ),
            'unread_count' => $this->notifications->unreadCount($userId),
            'meta' => [
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }

    public function read(Request $request, int $notification): JsonResponse
    {
        $updated = $this->notifications->markRead((int) $request->user()->user_id, $notification);

        if ($updated === null) {
            return response()->json(['message' => '通知不存在'], 404);
        }

        return response()->json(['data' => $this->notifications->payload($updated)]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->user_id;

        return response()->json([
            'data' => [
                'updated' => $this->notifications->markAllRead($userId),
                'unread_count' => $this->notifications->unreadCount($userId),
            ],
        ]);
    }
}

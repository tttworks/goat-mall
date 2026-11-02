<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Events;

use App\Models\UserNotification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 站内通知已创建（T6 实时化）。
 *
 * 带 unread_count：客户端角标不需要再发一次 GET /notifications。
 * 注意只在**首次写入**时广播，dedupe_key 命中的重复事件不再推（否则渠道重推会刷屏）。
 */
class NotificationCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly UserNotification $notification,
        public readonly int $unreadCount,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('goat.user.'.(int) $this->notification->user_id)];
    }

    public function broadcastAs(): string
    {
        return 'notify.created';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'notification_id' => (int) $this->notification->notification_id,
            'type' => $this->notification->type,
            'title' => $this->notification->title,
            'body' => $this->notification->body,
            'payload' => $this->notification->payload ?? [],
            'created_at' => $this->notification->created_at?->toIso8601String(),
            'unread_count' => $this->unreadCount,
        ];
    }
}

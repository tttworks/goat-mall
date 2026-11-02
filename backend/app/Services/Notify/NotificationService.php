<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Notify;

use App\Events\NotificationCreated;
use App\Models\UserNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 通知中心写入/读取（T6）。
 *
 * 唯一要守住的规则：通知是**副作用**，任何业务失败都不能因为通知写失败而回滚。
 * 因此 push() 吞掉写库异常（唯一键冲突返回已有记录），调用方无需 try/catch。
 * 去重靠 dedupe_key 唯一约束：支付回调会被渠道重推，同一事件只提醒一次。
 */
class NotificationService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function push(
        int $userId,
        string $type,
        string $title,
        string $body = '',
        array $payload = [],
        ?string $dedupeKey = null
    ): ?UserNotification {
        if ($userId <= 0) {
            return null;
        }

        try {
            $notification = UserNotification::query()->create([
                'user_id' => $userId,
                'type' => $type,
                'title' => mb_substr($title, 0, 120),
                'body' => $body === '' ? null : mb_substr($body, 0, 500),
                'payload' => $payload === [] ? null : $payload,
                'dedupe_key' => $dedupeKey,
            ]);
        } catch (UniqueConstraintViolationException) {
            // 同一事件重复推送：返回首条，不再写第二条（也不再广播，否则渠道重推会刷屏）
            return $dedupeKey === null
                ? null
                : UserNotification::query()->where('dedupe_key', $dedupeKey)->first();
        }

        $this->broadcast($notification);

        return $notification;
    }

    /** 实时角标推送（T6）：失败一律吞掉——通知本身已写库，推送只是锦上添花 */
    private function broadcast(UserNotification $notification): void
    {
        if (! config('goat.ws.enabled')) {
            return;
        }

        try {
            NotificationCreated::dispatch(
                $notification,
                $this->unreadCount((int) $notification->user_id)
            );
        } catch (\Throwable $e) {
            Log::channel('security')->warning('notify.推送失败', [
                'notification_id' => $notification->notification_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function paginate(int $userId, ?bool $unreadOnly = null, int $perPage = 20): LengthAwarePaginator
    {
        return UserNotification::query()
            ->where('user_id', $userId)
            ->when($unreadOnly === true, fn ($query) => $query->whereNull('read_at'))
            ->orderByDesc('notification_id')
            ->paginate($perPage);
    }

    public function unreadCount(int $userId): int
    {
        return UserNotification::query()->where('user_id', $userId)->whereNull('read_at')->count();
    }

    /** 已读幂等：重复调用不刷新时间，避免「已读时间」被反复改写 */
    public function markRead(int $userId, int $notificationId): ?UserNotification
    {
        $notification = UserNotification::query()
            ->where('user_id', $userId)
            ->whereKey($notificationId)
            ->first();

        if ($notification === null) {
            return null;
        }

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => Carbon::now()])->save();
        }

        return $notification;
    }

    /** @return int 本次实际标记的条数 */
    public function markAllRead(int $userId): int
    {
        return UserNotification::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
    }

    /** @return array<string, mixed> */
    public function payload(UserNotification $notification): array
    {
        return [
            'notification_id' => $notification->notification_id,
            'type' => $notification->type,
            'title' => $notification->title,
            'body' => $notification->body,
            'payload' => $notification->payload ?? [],
            'is_read' => $notification->isRead(),
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}

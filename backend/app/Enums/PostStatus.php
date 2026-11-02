<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Enums;

use App\Support\Concerns\StateTransitions;

/**
 * 帖子状态机（T6）。字面量与 posts 表 CHECK 约束一致。
 *
 * pending ─→ approved ─→ withdrawn
 *    │           
 *    ├→ rejected ─→ withdrawn
 *    └→ withdrawn
 *
 * 只有 approved 且有 published_at 的帖子进 feed；
 * withdrawn 是作者主动撤下（可再发新帖，不复活旧帖，避免「先发后改」绕过审核）。
 */
enum PostStatus: string
{
    use StateTransitions;

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    public function transitions(): array
    {
        return [
            self::Pending->value => [self::Approved->value, self::Rejected->value, self::Withdrawn->value],
            self::Approved->value => [self::Withdrawn->value],
            self::Rejected->value => [self::Withdrawn->value],
            self::Withdrawn->value => [],
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => '审核中',
            self::Approved => '已发布',
            self::Rejected => '未通过',
            self::Withdrawn => '已撤回',
        };
    }

    /** 对外可见（feed / 详情给陌生人看） */
    public function isPublic(): bool
    {
        return $this === self::Approved;
    }
}

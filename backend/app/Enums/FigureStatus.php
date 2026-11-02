<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Enums;

/**
 * 图鑑条目状态（T3）。契约「仅管理员发布」：draft → published 只能由 admin 触发。
 *
 * 与采集系统的关系：T9 可自由 upsert draft 态字段，但不得把条目改成 published；
 * 这样「采集入库」与「人工审核发布」两条链路不会互相踩。
 */
enum FigureStatus: string
{
    /** 草稿：采集/后台录入但未对外可见 */
    case Draft = 'draft';

    /** 已发布：对外可见、进 Meili 索引 */
    case Published = 'published';

    /** 已归档：历史条目下架但保留（不再出现在列表，详情页 410 语义） */
    case Archived = 'archived';

    public function isPublic(): bool
    {
        return $this === self::Published;
    }

    /** 后台列表筛选用 */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => '草稿',
            self::Published => '已发布',
            self::Archived => '已归档',
        };
    }
}

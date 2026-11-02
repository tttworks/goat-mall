<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Enums;

/**
 * 评论状态（T6）。
 *
 * 与帖子不同，评论没有「撤回」这个用户动作：作者只能删除（软删），
 * 因此状态只有机审三态，不走状态机——写错方向的风险比帖子小得多。
 */
enum CommentStatus: string
{
    case Approved = 'approved';
    case Pending = 'pending';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Approved => '已发布',
            self::Pending => '审核中',
            self::Rejected => '未通过',
        };
    }
}

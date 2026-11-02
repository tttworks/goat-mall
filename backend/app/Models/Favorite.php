<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 收藏（T6）。target_type 只认 listing / figure（迁移里有 CHECK 兜底）。
 */
class Favorite extends Model
{
    protected $table = 'favorites';

    protected $primaryKey = 'favorite_id';

    protected $guarded = [];

    /** 允许收藏的对象类型；新增类型必须同时改迁移的 CHECK 约束 */
    public const TARGET_LISTING = 'listing';

    public const TARGET_FIGURE = 'figure';

    /** @return array<int, string> */
    public static function targetTypes(): array
    {
        return [self::TARGET_LISTING, self::TARGET_FIGURE];
    }
}

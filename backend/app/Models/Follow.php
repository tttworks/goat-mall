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
 * 关注关系（T6）：follower → seller，单向。
 *
 * 只有卖家可被关注：关注列表的用途是「卖家上新提醒」，
 * 关注普通买家既没有内容产出也没有通知场景。
 */
class Follow extends Model
{
    protected $table = 'follows';

    protected $primaryKey = 'follow_id';

    protected $guarded = [];
}

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
 * 站内通知（T6）。
 *
 * 刻意不用 Laravel 内置通知表：内置表结构固定（notifiable_type/notifiable_id + data），
 * 而通知中心要按业务事件去重（dedupe_key）、要能按 type 过滤给前端分 tab，
 * 自带表两头都别扭，不如自建一张窄表。
 */
class UserNotification extends Model
{
    protected $table = 'user_notifications';

    protected $primaryKey = 'notification_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}

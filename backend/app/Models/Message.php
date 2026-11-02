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
 * 私信消息（T6）。已读只按「单条 read_at」记，不做整会话已读位：
 * 一条 read_at 既能支持未读数，也能支持「已读回执」的时间点展示。
 */
class Message extends Model
{
    protected $table = 'messages';

    protected $primaryKey = 'message_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}

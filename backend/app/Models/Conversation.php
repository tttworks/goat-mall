<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 一对一会话（T6）。
 *
 * 归一化约定：user_a_id < user_b_id。任何按「两人」查询的地方都必须先归一化，
 * 否则同一对人会开出两条会话，消息各看一半。
 */
class Conversation extends Model
{
    protected $table = 'conversations';

    protected $primaryKey = 'conversation_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id', 'conversation_id');
    }

    public function hasMember(int $userId): bool
    {
        return (int) $this->user_a_id === $userId || (int) $this->user_b_id === $userId;
    }

    /** 取会话里的另一个人 */
    public function peerOf(int $userId): int
    {
        return (int) $this->user_a_id === $userId
            ? (int) $this->user_b_id
            : (int) $this->user_a_id;
    }

    /** 归一化两人 id，保证 (a,b) 与 (b,a) 落到同一行 */
    public static function normalize(int $left, int $right): array
    {
        return $left < $right ? [$left, $right] : [$right, $left];
    }
}

<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Models;

use App\Enums\AuthProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 身份绑定（T2）：一人多凭证。
 *
 * 唯一约束 (provider, provider_user_id) 由 DB 兜底，服务层再查一次以避免并发注册重复绑定。
 * meta 只放非敏感快照（如 Apple is_private_email），**不存放 session_key/token**。
 *
 * @property int $identity_id
 * @property int $user_id
 * @property AuthProvider $provider
 * @property string $provider_user_id
 */
class Identity extends Model
{
    protected $table = 'identities';

    protected $primaryKey = 'identity_id';

    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'union_id',
        'nickname',
        'avatar_key',
        'meta',
        'verified_at',
        'last_login_at',
    ];

    protected function casts(): array
    {
        return [
            'provider' => AuthProvider::class,
            'meta' => 'array',
            'verified_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}

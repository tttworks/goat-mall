<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Models;

use App\Enums\AuthProvider;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * 用户主模型（T2）。
 *
 * 主键固定 `user_id`：T4 交易域的 buyer_id/seller_id/bidder_id 全按此列引用，
 * 与 Laravel 默认 `id` 不同，改动会连带交易域外键失效，勿动。
 *
 * 隐私约定：password / remember_token 永不出现在 API 响应；头像只暴露 UFile key。
 *
 * @property int $user_id
 * @property string|null $username
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $nickname
 * @property string|null $avatar_key
 * @property UserRole $role
 * @property UserStatus $status
 * @property string $locale
 */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $table = 'users';

    protected $primaryKey = 'user_id';

    protected $fillable = [
        'username',
        'email',
        'phone',
        'password',
        'nickname',
        'avatar_key',
        'role',
        'status',
        'locale',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'last_login_at' => 'datetime',
            'email_verified_at' => 'datetime',
        ];
    }

    /** @return HasMany<Identity, $this> */
    public function identities(): HasMany
    {
        return $this->hasMany(Identity::class, 'user_id', 'user_id');
    }

    /** @return HasOne<SellerProfile, $this> */
    public function sellerProfile(): HasOne
    {
        return $this->hasOne(SellerProfile::class, 'user_id', 'user_id');
    }

    /** @return HasMany<IdentityVerification, $this> */
    public function verifications(): HasMany
    {
        return $this->hasMany(IdentityVerification::class, 'user_id', 'user_id');
    }

    public function identityOf(AuthProvider $provider): ?Identity
    {
        return $this->identities->firstWhere('provider', $provider);
    }

    public function isSeller(): bool
    {
        return $this->role->canSell();
    }

    public function isAdmin(): bool
    {
        return $this->role->canModerate();
    }

    /** 状态正常才允许下单/出价/上架 */
    public function canTransact(): bool
    {
        return $this->status->canTransact();
    }

    /** 对外展示的最小资料（绝不带 email/phone/password） */
    public function publicProfile(): array
    {
        return [
            'user_id' => $this->user_id,
            'nickname' => $this->nickname,
            'avatar_key' => $this->avatar_key,
            'role' => $this->role->value,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

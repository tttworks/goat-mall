<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Models;

use App\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 实名核身记录（T2）。
 *
 * real_name / id_number 走 `encrypted` cast：读取时自动解密，写库时为密文。
 * 因此**不要**在业务里直接拿这两列做查询条件——按 `id_number_hash`（HMAC）比对。
 *
 * @property int $verification_id
 * @property int $user_id
 * @property VerificationStatus $status
 * @property string|null $id_number_mask
 */
class IdentityVerification extends Model
{
    protected $table = 'identity_verifications';

    protected $primaryKey = 'verification_id';

    protected $fillable = [
        'user_id',
        'provider',
        'channel_ref',
        'real_name',
        'id_number',
        'id_number_hash',
        'id_number_mask',
        'status',
        'reason',
        'submitted_at',
        'verified_at',
        'submitted_ip',
    ];

    protected $hidden = [
        'real_name',
        'id_number',
        'id_number_hash',
    ];

    protected function casts(): array
    {
        return [
            'real_name' => 'encrypted',
            'id_number' => 'encrypted',
            'status' => VerificationStatus::class,
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /** 仅暴露脱敏信息给前端/客服 */
    public function toSafeArray(): array
    {
        return [
            'verification_id' => $this->verification_id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'id_number_mask' => $this->id_number_mask,
            'reason' => $this->reason,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'verified_at' => $this->verified_at?->toIso8601String(),
        ];
    }
}

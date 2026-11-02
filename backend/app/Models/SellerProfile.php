<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Models;

use App\Enums\SellerStatus;
use App\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 店铺档案（T2）。
 *
 * 上架前置条件（T4 调用 canList()）：店铺 status=active 且 identity_status=approved。
 * 两个字段分开存，是因为「审核通过」与「实名通过」可能异步完成，展示与拦截需要分别判断。
 *
 * @property int $seller_id
 * @property int $user_id
 * @property string $shop_name
 * @property SellerStatus $status
 * @property VerificationStatus $identity_status
 */
class SellerProfile extends Model
{
    protected $table = 'seller_profiles';

    protected $primaryKey = 'seller_id';

    protected $fillable = [
        'user_id',
        'shop_name',
        'shop_intro',
        'shop_logo_key',
        'status',
        'identity_status',
        'applied_at',
        'activated_at',
        'suspended_at',
        'reject_reason',
        'sold_count',
        'rating_bp',
    ];

    protected function casts(): array
    {
        return [
            'status' => SellerStatus::class,
            'identity_status' => VerificationStatus::class,
            'applied_at' => 'datetime',
            'activated_at' => 'datetime',
            'suspended_at' => 'datetime',
            'sold_count' => 'integer',
            'rating_bp' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /** 路由绑定用 seller_id（主键非 id） */
    public function getRouteKeyName(): string
    {
        return 'seller_id';
    }

    /** 能否上架：店铺已开且已实名 */
    public function canList(): bool
    {
        return $this->status->canList() && $this->identity_status->isApproved();
    }
}

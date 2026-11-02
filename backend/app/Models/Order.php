<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 订单（契约 §2.2 orders）。owner：T4。
 * 三件套不可缺：order_no（对外）、idempotency_key（防重）、status（状态机）。
 * 注意：金额一律 decimal 字符串，禁止 float 参与结算。
 */
class Order extends Model
{
    use SoftDeletes;

    protected $table = 'orders';

    protected $primaryKey = 'order_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'goods_amount' => 'decimal:2',
            'shipping_amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'payable_amount' => 'decimal:2',
            'fee_snapshot' => 'array',
            'paid_at' => 'datetime',
            'shipped_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'closed_at' => 'datetime',
            'expire_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id', 'order_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'order_id', 'order_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class, 'order_id', 'order_id');
    }

    /** 后台列表要显示买卖双方昵称，避免前端再逐单查用户 */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id', 'user_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id', 'user_id');
    }

    /**
     * 状态跃迁（唯一入口）：先按枚举校验方向，再落库。
     *
     * @param  array<string, mixed>  $attributes  同时写入的时间戳/附加字段
     *
     * @throws \App\Exceptions\IllegalStateTransition
     */
    public function transitTo(OrderStatus $to, array $attributes = []): self
    {
        $this->status = $this->status->assertTransitionTo($to);
        $this->fill($attributes)->save();

        return $this;
    }

    public function escrowAmount(): string
    {
        return $this->status->holdsFunds() ? (string) $this->payable_amount : '0.00';
    }
}

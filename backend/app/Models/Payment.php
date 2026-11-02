<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 支付单（契约 §2.2 payments）。owner：T4 只建结构与幂等键；流转/分账实现归 T5。
 * 幂等两道：payment_no 唯一（我方单号）+ provider_trade_no 唯一（渠道单号，回调重放去重）。
 */
class Payment extends Model
{
    protected $table = 'payments';

    protected $primaryKey = 'payment_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'platform_fee_amount' => 'decimal:2',
            'seller_amount' => 'decimal:2',
            'platform_split_bip' => 'integer',
            'raw_payload' => 'array',
            'paid_at' => 'datetime',
            'frozen_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'order_id');
    }

    /** 状态跃迁唯一入口（流转业务由 T5 调用，禁止直接改 status 字段） */
    public function transitTo(PaymentStatus $to, array $attributes = []): self
    {
        $this->status = $this->status->assertTransitionTo($to);
        $this->fill($attributes)->save();

        return $this;
    }
}

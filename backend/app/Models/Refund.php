<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Models;

use App\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 退款单（契约 §2.2 售后）。状态跃迁只走 transitTo，业务代码不直接写 status。
 *
 * @property int $refund_id
 * @property string $refund_no
 * @property int $order_id
 * @property int|null $payment_id
 * @property int $buyer_id
 * @property string $amount
 * @property string $currency
 * @property string $reason
 * @property RefundStatus $status
 * @property string|null $remark
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $refunded_at
 */
class Refund extends Model
{
    protected $table = 'refunds';

    protected $primaryKey = 'refund_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'amount' => 'decimal:2',
            'reviewed_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'order_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id', 'payment_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id', 'user_id');
    }

    public function transitTo(RefundStatus $to, array $attributes = []): self
    {
        $this->status = $this->status->assertTransitionTo($to);
        $this->fill($attributes)->save();

        return $this;
    }

    /** 单号规则与支付单一致：前缀 + 时分秒 + 随机，便于人工核对时快速定位时间 */
    public static function nextNo(): string
    {
        return 'RF'.Carbon::now()->format('ymdHis').strtoupper(bin2hex(random_bytes(4)));
    }
}

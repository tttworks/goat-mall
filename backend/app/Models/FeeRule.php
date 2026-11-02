<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Models;

use App\Enums\FeeBearer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 费率规则（后台可配置）。owner：T4。
 * 匹配顺序：seller 级 > category 级 > global；同级取 priority 高者，再取新建者。
 * 生效期判定在 FeeCalculator 里做，保证「历史订单用下单时快照」。
 */
class FeeRule extends Model
{
    protected $table = 'fee_rules';

    protected $primaryKey = 'fee_rule_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'percent_bip' => 'integer',
            'cap_amount' => 'decimal:2',
            'bearer' => FeeBearer::class,
            'priority' => 'integer',
            'is_active' => 'boolean',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }

    public function scopeInWindow(Builder $query, ?Carbon $at = null): Builder
    {
        $at = $at ?? Carbon::now();

        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $at))
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $at));
    }
}

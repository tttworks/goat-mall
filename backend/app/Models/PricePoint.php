<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 行情数据点（契约 §2.1 price_points）。
 *
 * 写入方只有两处：本站确认收货时回写 internal 成交价、采集系统 upsert 外部行情。
 * 读取一律走 QuoteService（聚合与平滑逻辑集中一处，避免各接口各算一套）。
 *
 * @property int $price_point_id
 * @property int $figure_id
 * @property string $source
 * @property string $currency
 * @property string $price
 * @property string|null $shipping_amount
 * @property string|null $condition
 * @property string|null $external_id
 * @property string|null $title
 * @property string|null $url
 * @property \Illuminate\Support\Carbon|null $sold_at
 */
class PricePoint extends Model
{
    protected $table = 'price_points';

    protected $primaryKey = 'price_point_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'shipping_amount' => 'decimal:2',
            'raw' => 'array',
            'sold_at' => 'datetime',
        ];
    }

    public function figure(): BelongsTo
    {
        return $this->belongsTo(Figure::class, 'figure_id', 'figure_id');
    }

    /** 成本价口径：多数买家关心「含运费到手价」，但单列保留以便对比 */
    public function landedPrice(): string
    {
        return number_format((float) $this->price + (float) ($this->shipping_amount ?? 0), 2, '.', '');
    }
}

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
 * 交易评价（T6）：一个订单一条，买家评卖家。
 *
 * 评分与内容在订单完成后写入，之后不允许改分（只允许卖家回复 + 后台隐藏），
 * 否则「先好评返现再改差评」会污染卖家信用分。
 */
class Review extends Model
{
    protected $table = 'reviews';

    protected $primaryKey = 'review_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'images' => 'array',
            'replied_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'order_id');
    }

    public function isReplied(): bool
    {
        return $this->replied_at !== null;
    }
}

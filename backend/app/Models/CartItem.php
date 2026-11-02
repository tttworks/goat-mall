<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 购物车条目（T6）。加购不锁库，仅表达购买意向。
 */
class CartItem extends Model
{
    protected $table = 'cart_items';

    protected $primaryKey = 'cart_item_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }
}

<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 购物车（T6）。
 *
 * 为什么没有 quantity 以外的库存字段：C2C 二手多为单件，
 * 加购不锁库（只有下单才锁），所以购物车只表达「意向」，不承担库存承诺。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table) {
            $table->bigIncrements('cart_item_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('listing_id');
            // 二手单件：恒为 1，保留字段是为了将来接品牌多件商品时不改表
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->timestamps();

            // 同一用户同一商品只留一行，重复加购走「已存在即返回」
            $table->unique(['user_id', 'listing_id'], 'uk_cart_items_user_listing');
            $table->index(['listing_id'], 'idx_cart_items_listing');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};

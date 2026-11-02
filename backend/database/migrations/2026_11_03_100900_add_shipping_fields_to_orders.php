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
 * 订单物流字段（T4 · 发货）。
 *
 * 100300 建表时只留了 shipped_at，没有承运商/运单号 —— 发货接口无处落单号，
 * 买家无法查件。此处补齐；T4 未上线，直接在原表加列而不是新建副表。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('shipping_company', 32)->nullable()->after('confirmed_at');
            $table->string('tracking_no', 64)->nullable()->after('shipping_company');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['shipping_company', 'tracking_no']);
        });
    }
};

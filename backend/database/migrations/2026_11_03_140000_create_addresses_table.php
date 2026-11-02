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
 * 收货地址簿（T4）。此前下单只落商品与金额，卖家拿不到收件人信息，
 * 只能私信问地址——交易在「发货」这一步断掉了。
 *
 * 两条刻意的决定：
 * 1. **不加软删除**：地址是个人信息，用户删了就该真删；订单侧另有收货快照，
 *    历史单据不会因为地址消失而丢凭证。
 * 2. **省市区拆列**：不存 "天津市/天津市/和平区" 一个字符串——运费模板、面单打印
 *    都要按行政区划取字段，拼串之后再拆是典型的错误来源。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->bigIncrements('address_id');
            $table->unsignedBigInteger('user_id');
            $table->string('receiver_name', 32);
            $table->string('receiver_phone', 20);
            $table->string('province', 32);
            $table->string('city', 32);
            $table->string('district', 32)->nullable();
            $table->string('detail', 255);
            // 默认地址由服务层保证「每人至多一条」：库层跨 PG/SQLite 的部分唯一索引不值得为它引入方言分支
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'is_default'], 'idx_addresses_user_default');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};

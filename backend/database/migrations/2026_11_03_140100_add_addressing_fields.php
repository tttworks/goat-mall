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
 * 收货快照 + 商品运费（T4）。
 *
 * 订单侧存快照而不是只存 address_id：地址随时可改可删，发货单必须还原
 * 「下单那一刻寄给谁、寄到哪」，否则出纠纷时连寄错地址都无法自证。
 *
 * 商品侧补 shipping_fee：运费本该是卖家的挂牌条件，此前由买家下单时自由传值，
 * 结果是既能白嫖运费、也能被恶意改成 0 —— 定价权必须回到挂牌方。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // 溯源用：地址被改/删后仍能追到原始条目；历史订单为空
            $table->unsignedBigInteger('address_id')->nullable();
            $table->string('receiver_name', 32)->nullable();
            $table->string('receiver_phone', 20)->nullable();
            $table->string('receiver_province', 32)->nullable();
            $table->string('receiver_city', 32)->nullable();
            $table->string('receiver_district', 32)->nullable();
            $table->string('receiver_detail', 255)->nullable();
        });

        Schema::table('listings', function (Blueprint $table) {
            // 0 = 包邮；卖家挂牌时填写，下单以该值为准
            $table->decimal('shipping_fee', 12, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'address_id',
                'receiver_name',
                'receiver_phone',
                'receiver_province',
                'receiver_city',
                'receiver_district',
                'receiver_detail',
            ]);
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('shipping_fee');
        });
    }
};

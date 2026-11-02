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
 * T2 · 卖家（店铺）档案。
 *
 * 开店即触发实名：profile.status = pending 且 identity_status = none/pending 时不允许上架（T4 侧校验）。
 * 一个用户只能有一个店铺（user_id 唯一），不做多店铺，避免资金清分与保证金复杂度。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_profiles', function (Blueprint $table) {
            $table->bigIncrements('seller_id');

            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('user_id')->on('users')->unique()->cascadeOnDelete();

            $table->string('shop_name', 48)->unique();
            $table->text('shop_intro')->nullable();
            $table->string('shop_logo_key', 255)->nullable();

            $table->string('status', 16)->default('draft');       // App\Enums\SellerStatus
            $table->string('identity_status', 16)->default('none'); // 与 identity_verifications 同步

            $table->timestamp('applied_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('reject_reason', 255)->nullable();

            // 交易数据（冗余统计，成交后由 T4 累加；初始 0 便于前台直接展示）
            $table->unsignedInteger('sold_count')->default(0);
            $table->unsignedInteger('rating_bp')->default(5000); // 好评率「万分比」，5000 = 50.00%

            $table->timestamps();

            $table->index(['status', 'identity_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_profiles');
    }
};

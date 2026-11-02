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
 * 支付单（契约 §2.2 payments）：收付通二级商户 + 冻结 + 分账字段。
 * owner：T4 只建表结构（字段 + 幂等键 + 状态字面量）；状态机流转与沙箱网关归 T5。
 * 幂等：provider_trade_no 唯一 + idempotency_key 唯一，回调重放不产生第二条支付。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->bigIncrements('payment_id');
            $table->string('payment_no', 32)->unique();
            $table->unsignedBigInteger('order_id');
            $table->string('idempotency_key', 64)->unique();
            // wechat_ecsw / alipay_zft / sandbox
            $table->string('channel', 24)->default('sandbox');
            // pending / paid / frozen / splitting / settled / refunded / failed
            $table->string('status', 24)->default('pending');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('CNY');
            // 二级商户（卖家）与平台分账比例快照，bip 万分比
            $table->string('sub_merchant_id', 64)->nullable();
            $table->unsignedInteger('platform_split_bip')->default(0);
            $table->decimal('platform_fee_amount', 12, 2)->default(0);
            $table->decimal('seller_amount', 12, 2)->default(0);
            $table->string('provider_trade_no', 64)->nullable()->unique();
            $table->jsonb('raw_payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('frozen_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->foreign('order_id', 'fk_payments_order')
                ->references('order_id')->on('orders')->cascadeOnDelete();
            $table->index(['status', 'created_at'], 'idx_payments_status_created');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(
                "ALTER TABLE payments ADD CONSTRAINT chk_payments_status CHECK (status IN
                 ('pending','paid','frozen','splitting','settled','refunded','failed'))"
            );
            Schema::getConnection()->statement(
                'ALTER TABLE payments ADD CONSTRAINT chk_payments_amount CHECK (amount >= 0)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};

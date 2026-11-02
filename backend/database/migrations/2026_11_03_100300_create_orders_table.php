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
 * 订单 + 订单项（契约 §2.2 orders / order_items），担保交易口径。
 * owner：T4（状态机 + 幂等键）；资金分账由 T5 在 payments 上落。
 * 幂等：idempotency_key 全局唯一，重复提交同一 key 直接返回原订单（不重复扣减/锁库）。
 * buyer_id / seller_id → users：跨 owner，不加 FK（见 listings 迁移说明）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->bigIncrements('order_id');
            $table->string('order_no', 32)->unique();
            // 客户端幂等键（防重复下单/重复回调），唯一约束由 DB 兜底
            $table->string('idempotency_key', 64)->unique();
            $table->unsignedBigInteger('buyer_id');
            $table->unsignedBigInteger('seller_id');
            // pending_payment / paid / seller_shipped / buyer_confirmed / completed
            // cancelled / refunding / refunded / closed
            $table->string('status', 24)->default('pending_payment');
            // 担保交易：款项在买家确认收货前为冻结态
            $table->string('escrow_status', 16)->default('none'); // none / frozen / released / refunded
            $table->decimal('goods_amount', 12, 2)->default(0);
            $table->decimal('shipping_amount', 12, 2)->default(0);
            $table->decimal('fee_amount', 12, 2)->default(0);
            $table->decimal('payable_amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('CNY');
            $table->unsignedBigInteger('fee_rule_id')->nullable();
            $table->string('fee_bearer', 16)->nullable();
            $table->jsonb('fee_snapshot')->nullable();
            $table->string('remark', 255)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('expire_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['buyer_id', 'status'], 'idx_orders_buyer_status');
            $table->index(['seller_id', 'status'], 'idx_orders_seller_status');
            $table->index(['status', 'expire_at'], 'idx_orders_status_expire');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->bigIncrements('order_item_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('listing_id');
            $table->unsignedBigInteger('figure_id')->nullable();
            // 下单时刻快照：后续商品改价/改名不影响历史订单
            $table->string('title_snapshot', 160);
            $table->string('cover_key_snapshot', 255)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('subtotal', 12, 2);
            $table->timestamps();

            $table->foreign('order_id', 'fk_order_items_order')
                ->references('order_id')->on('orders')->cascadeOnDelete();
            $table->index(['listing_id'], 'idx_order_items_listing');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(
                "ALTER TABLE orders ADD CONSTRAINT chk_orders_status CHECK (status IN
                 ('pending_payment','paid','seller_shipped','buyer_confirmed','completed',
                  'cancelled','refunding','refunded','closed'))"
            );
            Schema::getConnection()->statement(
                "ALTER TABLE orders ADD CONSTRAINT chk_orders_escrow CHECK (escrow_status IN
                 ('none','frozen','released','refunded'))"
            );
            Schema::getConnection()->statement(
                'ALTER TABLE orders ADD CONSTRAINT chk_orders_amount CHECK (payable_amount >= 0 AND goods_amount >= 0)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};

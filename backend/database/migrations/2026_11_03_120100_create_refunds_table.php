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
 * 退款单（契约 §2.2 交易域售后）。owner：T4。
 *
 * 为什么单独建表而不复用 payments：一笔支付可能多次申请退款（驳回后再申请、
 * 渠道失败重试），退款是「申请-审核」业务对象，有自己的原因、审核人与状态，
 * 塞进 payments 会让支付单状态机变成一锅粥。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->bigIncrements('refund_id');
            $table->string('refund_no', 32)->unique();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->unsignedBigInteger('buyer_id');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('CNY');
            $table->string('reason', 255);
            // pending / refunded / rejected / failed
            $table->string('status', 16)->default('pending');
            // 管理员审核意见（驳回时必填，由请求层校验）
            $table->string('remark', 255)->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status'], 'idx_refunds_order_status');
            $table->index(['status', 'created_at'], 'idx_refunds_status_created');
            $table->index(['buyer_id', 'created_at'], 'idx_refunds_buyer_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};

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
 * 举报表（T6 社群治理）：把「用户看不下去」变成一条可排队、可追溯的工单。
 *
 * 三处设计取舍：
 * 1. 与收藏一样用「多态两列」：商品/帖子/用户三类目标共用一张表，
 *    后台按状态排队即可，不需要为每类内容各做一个队列页；
 * 2. 同一人对同一目标只留一条记录（唯一约束）：重复提交变成「补充证据」，
 *    而不是把后台刷满；处理完再被举报时复用同一条并重新置为 pending；
 * 3. 举报只记录判定，不直接改内容状态——下架/封禁是运营动作，必须留操作人。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->bigIncrements('report_id');
            $table->unsignedBigInteger('reporter_id');
            // listing / post / user：业务层用枚举白名单校验
            $table->string('target_type', 16);
            $table->unsignedBigInteger('target_id');
            // fake / prohibited / fraud / abuse / spam / other：口径固定，便于统计
            $table->string('reason', 32);
            $table->string('detail', 500)->nullable();
            // pending / handled / dismissed
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('handled_by')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->string('handle_note', 255)->nullable();
            $table->timestamps();

            $table->unique(['reporter_id', 'target_type', 'target_id'], 'uk_reports_reporter_target');
            $table->index(['status', 'created_at'], 'idx_reports_status_created');
            $table->index(['target_type', 'target_id'], 'idx_reports_target');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};

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
 * 行情源登记表（契约 §2.1 的补充表）。
 *
 * 为什么需要一张表：`price_points.source` 只能表达「这条数据从哪来」，
 * 表达不了「这个源现在还用不用、多久采一次、最近一次成功是什么时候」。
 * 运营在后台把 eBay 停了，代码里到处 `if source == 'ebay'` 是不现实的，
 * 所以把「源」提升为一等实体，聚合时按表里的开关决定出哪些源。
 *
 * 不建外键到 price_points：行情点可能先于源登记入库（采集侧早期数据），
 * 有 FK 会导致导入失败；孤儿点由聚合层按「未登记源」忽略即可。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_sources', function (Blueprint $table) {
            $table->bigIncrements('market_source_id');
            // internal / ebay / mercari / manual
            $table->string('source', 16)->unique();
            $table->string('label', 32);
            // internal=站内成交回写（不可停用） / collector=采集系统 / manual=人工补录
            $table->string('channel', 16)->default('collector');
            $table->boolean('enabled')->default(true);
            $table->string('currency', 3)->default('CNY');
            // 采集间隔（分钟）；null = 不定时（人工补录类）
            $table->unsignedSmallInteger('interval_minutes')->nullable();
            // 越小越靠前，决定详情页多源对比的顺序
            $table->unsignedSmallInteger('priority')->default(100);
            // 采集侧回执：后台据此判断「这个源是不是断了」，不靠运维翻日志
            $table->timestamp('last_success_at')->nullable();
            $table->string('last_status', 16)->default('idle'); // idle / ok / error
            $table->string('last_message', 500)->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['enabled', 'priority'], 'idx_market_sources_enabled_priority');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_sources');
    }
};

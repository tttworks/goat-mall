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
 * 行情数据点（契约 §2.1 price_points）。owner：T3 读取 / T9 采集写入 / 本站成交回写。
 *
 * 三源统一建模：source 区分 internal（站内成交）/ ebay / mercari / manual（人工补录），
 * 前端做多源对比时按 source 分组，不需要为每个渠道建表。
 *
 * 关于 TimescaleDB：契约里写的是 hypertable，但 hypertable 要求唯一索引包含分区列
 * （本表主键是 price_point_id，除非改成 (sold_at, price_point_id) 复合主键），
 * 而图鑑库体量在百万级以内时「普通表 + (figure_id, sold_at) 复合索引」的查询代价一样。
 * 因此这里先建普通表，生产上量后再由 DBA 执行 create_hypertable（数据可迁移），
 * 避免引入一个在 SQLite 测试库上必然失败的迁移。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_points', function (Blueprint $table) {
            $table->bigIncrements('price_point_id');
            $table->unsignedBigInteger('figure_id');
            // internal / ebay / mercari / manual
            $table->string('source', 16)->default('internal');
            $table->string('currency', 3)->default('CNY');
            $table->decimal('price', 12, 2);
            // 到手价口径差异大（日本煤炉多为含运费），单独存一列供前端切换展示
            $table->decimal('shipping_amount', 12, 2)->nullable();
            // 成色口径尽量对齐站内：new / used_a / used_b / used_c / unknown
            $table->string('condition', 16)->nullable();
            // 采集侧原始 ID（如 ebay itemId），用于幂等写入；站内成交无此值
            $table->string('external_id', 120)->nullable();
            $table->string('title', 255)->nullable();
            $table->string('url', 500)->nullable();
            // 成交/抓取时间：行情时间轴的唯一基准，不用 created_at（导入历史数据会失真）
            $table->timestamp('sold_at')->nullable();
            $table->jsonb('raw')->nullable();
            $table->timestamps();

            // 采集重跑去重：同一来源同一原始 ID 只落一条（NULL 不参与唯一约束，站内成交不受影响）
            $table->unique(['source', 'external_id'], 'uk_price_points_source_external');

            $table->index(['figure_id', 'sold_at'], 'idx_price_points_figure_time');
            $table->index(['figure_id', 'source', 'sold_at'], 'idx_price_points_figure_source_time');
            $table->index(['source', 'sold_at'], 'idx_price_points_source_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_points');
    }
};

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
 * 补 listings.figure_id → figures.figure_id 外键（契约 §2.2）。owner：T3。
 *
 * 时序：T4 的 listings 建表排在 100200，本迁移排在 100600（其后），故此处补 FK 不会「表不存在」。
 * 行为：SET NULL —— 图鑑条目被物理删除时商品保留（降级为「无图鑑」商品），
 * 不能 CASCADE，否则删一条图鑑会连带删掉别人的在售商品。
 * SQLite 不支持对已有表追加外键，测试环境跳过（真库才需要这层约束）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        if (! Schema::hasTable('listings') || ! Schema::hasTable('figures')) {
            return;
        }

        Schema::table('listings', function (Blueprint $table) {
            $table->foreign('figure_id', 'fk_listings_figure')
                ->references('figure_id')
                ->on('figures')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        if (Schema::hasTable('listings')) {
            Schema::table('listings', function (Blueprint $table) {
                $table->dropForeign('fk_listings_figure');
            });
        }
    }
};

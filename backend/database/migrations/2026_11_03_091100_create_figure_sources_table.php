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
 * 图鑑数据源溯源（契约 §2.1 figure_sources）。owner：T3 建表，T9 为主写入方。
 *
 * 为什么保留复合主键 (source, external_id) 而不加自增 id：
 * 「一个外部源条目只对应一个 figure」是数据一致性约束本身，复合唯一键能直接拒绝重复入库，
 * 比自增 id + 唯一索引少一次查重；写入统一走 DB::table()->updateOrInsert（见 FigureService）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('figure_sources', function (Blueprint $table) {
            $table->string('source', 16);
            $table->string('external_id', 64);
            // 可空：采集到的条目尚未归属到任何图鑑条目时先落库待人工归并
            $table->unsignedBigInteger('figure_id')->nullable();
            $table->jsonb('raw_payload')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->primary(['source', 'external_id']);
            $table->index(['figure_id', 'source'], 'idx_figure_sources_figure');
            $table->index(['last_synced_at'], 'idx_figure_sources_synced');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(
                "ALTER TABLE figure_sources ADD CONSTRAINT chk_figure_sources_source CHECK (source IN ('mfc','78dm','amiAmi'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('figure_sources');
    }
};

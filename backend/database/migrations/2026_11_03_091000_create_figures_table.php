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
 * 图鑑主数据（契约 §2.1 figures）。owner：T3。
 *
 * 读写归属：站点后台（T3）负责录入/编辑/发布；采集系统（T9）只 upsert 采集到的字段
 * 与 figure_sources 溯源，不改 status / published_at（发布权只在管理员）。
 *
 * 字段扩展说明（相对契约 §2.1 的增量，均为可空/有默认值，不破坏 T9 的写入契约）：
 *   slug         SEO 友好路径（对外详情页用 slug，内部仍以 figure_id 关联）
 *   aliases      别名/俗称（中文圈「景品叫法」差异大，搜索必须覆盖）
 *   year         从 release_date 派生的发布年，供多维筛选与 Meili 排序
 *   price_low    行情最低价，由 T9 回写；此处仅存储展示值，权威数据仍在 price_points
 *   status       发布状态；契约「仅管理员发布」由此列落地
 *   views        详情页浏览计数（热度排序用，非核心业务）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('figures', function (Blueprint $table) {
            $table->bigIncrements('figure_id');
            $table->string('slug', 160)->unique();
            $table->string('name', 200);
            $table->string('name_ja', 200)->nullable();
            $table->string('name_en', 200)->nullable();
            $table->jsonb('aliases')->nullable();
            // 多维筛选维度：厂商 / 系列 / 比例 / 品类 / 年份
            $table->string('maker', 120)->nullable();
            $table->string('series', 120)->nullable();
            $table->string('scale', 24)->nullable();
            $table->string('category', 32)->default('other');
            $table->smallInteger('year')->nullable();
            $table->date('release_date')->nullable();
            // 外部数据源 ID：{mfc:<id>, 78dm:<id>, amiAmi:<id>}，GIN 索引（契约 §2.1）
            $table->jsonb('external_ids')->nullable();
            // 规格明细：材质/尺寸/配件/原型师等非结构化字段
            $table->jsonb('specs')->nullable();
            $table->string('cover_key', 255)->nullable();
            $table->jsonb('gallery_keys')->nullable();
            $table->decimal('price_low', 12, 2)->nullable();
            $table->string('status', 16)->default('draft');
            $table->unsignedBigInteger('views')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['maker', 'status'], 'idx_figures_maker_status');
            $table->index(['series', 'status'], 'idx_figures_series_status');
            $table->index(['category', 'status'], 'idx_figures_category_status');
            $table->index(['year'], 'idx_figures_year');
            $table->index(['status', 'published_at'], 'idx_figures_status_published');
            $table->index(['price_low'], 'idx_figures_price_low');
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $connection = Schema::getConnection();

        // external_ids / aliases 是「按 ID 精确命中」的高频过滤位，走 GIN 而非 LIKE 扫全表
        $connection->statement('CREATE INDEX idx_figures_external_ids ON figures USING gin (external_ids)');
        $connection->statement('CREATE INDEX idx_figures_aliases ON figures USING gin (aliases)');

        // 中文/日文名用 trigram 模糊检索兜底（Meili 不可用时仍能搜）
        // pg_trgm 需 DBA 权限，拿不到就静默跳过：业务退化为 SQL 全表 ILIKE，不影响可用性
        try {
            $connection->statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            $connection->statement('CREATE INDEX idx_figures_name_trgm ON figures USING gin (name gin_trgm_ops)');
        } catch (Throwable) {
            // 无扩展权限：跳过，保留 ILIKE 兜底
        }

        // 枚举/数值兜底：DB 层拒绝非法字面量（应用层已校验，这里是最后一道闸）
        $connection->statement(
            "ALTER TABLE figures ADD CONSTRAINT chk_figures_status CHECK (status IN ('draft','published','archived'))"
        );
        $connection->statement(
            "ALTER TABLE figures ADD CONSTRAINT chk_figures_category CHECK (category IN
             ('scale_figure','prize','ichiban_kuji','action_figure','statue','model_kit','plush','acrylic','card','other'))"
        );
        $connection->statement(
            'ALTER TABLE figures ADD CONSTRAINT chk_figures_year CHECK (year IS NULL OR (year BETWEEN 1950 AND 2100))'
        );
        $connection->statement(
            'ALTER TABLE figures ADD CONSTRAINT chk_figures_price_low CHECK (price_low IS NULL OR price_low >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('figures');
    }
};

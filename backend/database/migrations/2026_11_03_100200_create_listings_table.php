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
 * 在售商品（契约 §2.2 listings）。
 * owner：T4。
 * 跨 owner 依赖说明（不加 FK，仅索引，避免与 T2/T3 争同一迁移文件）：
 *   - seller_id → users：T2 建表后由其迁移补 FK；
 *   - figure_id → figures：T3/T1 基线建表后由其迁移补 FK（契约要求 figure_id 可空，无图鑑也可上架）。
 * 媒体只存 UFile 对象 key（契约 §2 + 项目约定 §2），URL 由 API 层用 MEDIA_BASE_URL 拼。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table) {
            $table->bigIncrements('listing_id');
            $table->unsignedBigInteger('seller_id');
            $table->unsignedBigInteger('figure_id')->nullable();
            $table->string('title', 160);
            $table->text('description')->nullable();
            // 品相枚举：new / like_new / used_good / used_fair / defective / for_parts
            $table->string('condition_enum', 24)->default('used_good');
            $table->decimal('price', 12, 2);
            $table->string('currency', 3)->default('CNY');
            // draft / pending_review / active / reserved / sold / delisted / rejected
            $table->string('status', 24)->default('draft');
            $table->unsignedInteger('stock')->default(1);
            $table->string('cover_key', 255)->nullable();
            $table->jsonb('gallery_keys')->nullable();
            $table->jsonb('meta')->nullable();
            // 担保交易：买家下单即锁库，锁定时长到期由调度释放
            $table->timestamp('reserved_until')->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['seller_id', 'status'], 'idx_listings_seller_status');
            $table->index(['figure_id', 'status'], 'idx_listings_figure_status');
            $table->index(['status', 'published_at'], 'idx_listings_status_published');
            $table->index(['price'], 'idx_listings_price');
        });

        // 状态机兜底：DB 层拒绝非法状态字面量（应用层 StateMachine 已在写前校验）
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(
                "ALTER TABLE listings ADD CONSTRAINT chk_listings_status CHECK (status IN
                 ('draft','pending_review','active','reserved','sold','delisted','rejected'))"
            );
            Schema::getConnection()->statement(
                'ALTER TABLE listings ADD CONSTRAINT chk_listings_price CHECK (price >= 0)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('listings');
    }
};

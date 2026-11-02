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
 * 互动层四张表（T6）：收藏 / 关注 / 评价 / 通知。
 *
 * 三处设计取舍：
 * 1. 收藏用「多态两列」（target_type + target_id）而不是两张表：
 *    收藏列表要按时间混排，两张表就得在应用层做归并排序，分页会错；
 * 2. 关注是卖家维度的单向关系，与 orders 一样不跨 owner 加 FK（用户表 owner 是 T2）；
 * 3. 评价以 order_id 唯一约束保证「一单一评」，靠 DB 兜底而非先查后插。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorites', function (Blueprint $table) {
            $table->bigIncrements('favorite_id');
            $table->unsignedBigInteger('user_id');
            // listing / figure：只允许这两类，业务层用枚举白名单校验
            $table->string('target_type', 16);
            $table->unsignedBigInteger('target_id');
            $table->timestamps();

            // 重复收藏由唯一约束吸收，接口保持幂等
            $table->unique(['user_id', 'target_type', 'target_id'], 'uk_favorites_user_target');
            $table->index(['target_type', 'target_id'], 'idx_favorites_target');
        });

        Schema::create('follows', function (Blueprint $table) {
            $table->bigIncrements('follow_id');
            $table->unsignedBigInteger('follower_id');
            $table->unsignedBigInteger('seller_id');
            $table->timestamps();

            $table->unique(['follower_id', 'seller_id'], 'uk_follows_pair');
            $table->index(['seller_id'], 'idx_follows_seller');
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->bigIncrements('review_id');
            // 一单一评：唯一约束是硬口径，客服也不能手动补第二条
            $table->unsignedBigInteger('order_id')->unique();
            $table->unsignedBigInteger('listing_id')->nullable();
            $table->unsignedBigInteger('buyer_id');
            $table->unsignedBigInteger('seller_id');
            $table->unsignedTinyInteger('rating');
            $table->string('content', 500)->nullable();
            // 图片只存对象 key（契约 §2），URL 由 API 层拼
            $table->jsonb('images')->nullable();
            // published / hidden：隐藏只影响展示，不删除凭证
            $table->string('status', 16)->default('published');
            $table->string('seller_reply', 500)->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'status'], 'idx_reviews_seller_status');
            $table->index(['listing_id'], 'idx_reviews_listing');
        });

        // 用 user_notifications 而非 notifications：避开 Laravel 默认通知表结构，
        // 便于后续接队列/WS 时自定义字段不被框架迁移覆盖
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->bigIncrements('notification_id');
            $table->unsignedBigInteger('user_id');
            $table->string('type', 40);
            $table->string('title', 120);
            $table->string('body', 500)->nullable();
            $table->jsonb('payload')->nullable();
            // 幂等键：同一业务事件只提醒一次（渠道重推回调时靠它去重）
            $table->string('dedupe_key', 64)->nullable()->unique();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at'], 'idx_user_notifications_unread');
            $table->index(['user_id', 'created_at'], 'idx_user_notifications_feed');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(
                "ALTER TABLE favorites ADD CONSTRAINT chk_favorites_target_type
                 CHECK (target_type IN ('listing','figure'))"
            );
            Schema::getConnection()->statement(
                'ALTER TABLE reviews ADD CONSTRAINT chk_reviews_rating CHECK (rating BETWEEN 1 AND 5)'
            );
            Schema::getConnection()->statement(
                "ALTER TABLE reviews ADD CONSTRAINT chk_reviews_status
                 CHECK (status IN ('published','hidden'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('follows');
        Schema::dropIfExists('favorites');
    }
};

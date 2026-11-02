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
 * 社群 + 私信三张表（T6）：posts / conversations / messages。
 *
 * 关键约束：
 * 1. 帖子有审核状态机，未过审不进 feed（status + published_at 双条件，前端不可能漏过滤）；
 * 2. 会话用「小 id 在前」的归一化唯一键，同一对用户无论谁先发起都只落一条；
 * 3. 消息外键级联删除，会话被清理时消息不残留孤儿行。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->bigIncrements('post_id');
            $table->unsignedBigInteger('user_id');
            $table->string('content', 1000)->nullable();
            // 图片只存对象 key；上限由配置 goat.community.post_max_images 控制
            $table->jsonb('images')->nullable();
            // pending / approved / rejected / withdrawn
            $table->string('status', 16)->default('pending');
            // 机审原始结论：保留判定依据，申诉时能复盘
            $table->jsonb('moderation')->nullable();
            $table->string('reject_reason', 255)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at'], 'idx_posts_feed');
            $table->index(['user_id', 'status'], 'idx_posts_user_status');
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->bigIncrements('conversation_id');
            // 恒有 user_a_id < user_b_id：唯一键才能吸收「谁先发起」的差异
            $table->unsignedBigInteger('user_a_id');
            $table->unsignedBigInteger('user_b_id');
            // 列表页只展示最后一句，不在列表里扫 messages 表
            $table->string('last_message_preview', 120)->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['user_a_id', 'user_b_id'], 'uk_conversations_pair');
            $table->index(['user_a_id', 'last_message_at'], 'idx_conversations_a');
            $table->index(['user_b_id', 'last_message_at'], 'idx_conversations_b');
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->bigIncrements('message_id');
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('sender_id');
            $table->string('body', 1000);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->foreign('conversation_id', 'fk_messages_conversation')
                ->references('conversation_id')->on('conversations')->cascadeOnDelete();
            $table->index(['conversation_id', 'message_id'], 'idx_messages_conversation');
            $table->index(['conversation_id', 'sender_id', 'read_at'], 'idx_messages_unread');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(
                "ALTER TABLE posts ADD CONSTRAINT chk_posts_status
                 CHECK (status IN ('pending','approved','rejected','withdrawn'))"
            );
            Schema::getConnection()->statement(
                'ALTER TABLE conversations ADD CONSTRAINT chk_conversations_pair
                 CHECK (user_a_id < user_b_id)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('posts');
    }
};

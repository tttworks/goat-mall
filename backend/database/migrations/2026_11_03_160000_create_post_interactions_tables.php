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
 * 社群互动：点赞 + 评论（T6）。
 *
 * 点赞用「关系表 + 唯一约束」而不是帖子上的计数器：
 * 计数器一旦写错（重复点赞、删帖回滚）就永远对不上，关系表随时可以 COUNT 复原。
 * 评论带 status，与帖子一样先落库再机审——被拦下的评论要能复盘，而不是凭空消失。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_likes', function (Blueprint $table) {
            $table->bigIncrements('like_id');
            $table->unsignedBigInteger('post_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('created_at')->nullable();

            // 同一人对同一帖子只有一条：重复点赞由唯一约束兜底（幂等）
            $table->unique(['post_id', 'user_id'], 'uk_post_likes_post_user');
            $table->index('user_id', 'idx_post_likes_user');
        });

        Schema::create('post_comments', function (Blueprint $table) {
            $table->bigIncrements('comment_id');
            $table->unsignedBigInteger('post_id');
            $table->unsignedBigInteger('user_id');
            $table->string('content', 500);
            // approved / pending（机审说不准）/ rejected（机审拦下，作者可见原因）
            $table->string('status', 16)->default('approved');
            $table->string('reject_reason', 255)->nullable();
            // 机审原始结论留痕：与帖子一致，被拦下的评论要能复盘
            $table->jsonb('moderation')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['post_id', 'status', 'comment_id'], 'idx_post_comments_post');
            $table->index(['user_id', 'comment_id'], 'idx_post_comments_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_comments');
        Schema::dropIfExists('post_likes');
    }
};

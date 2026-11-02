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
 * T2 · 第三方/本地身份绑定表。
 *
 * 一个 user 可绑定多个身份（用户名 + 微信 + Apple），因此登录主体与登录方式解耦：
 * users 存「人」，identities 存「凭证」。后续加短信/邮箱验证码登录只需新增 provider 值，不动主表。
 *
 * 安全约定：**绝不落 session_key / refresh_token 等可冒用凭证**；微信 session_key 用完即弃，
 * meta 只放展示性与非敏感信息（如 Apple 的 is_private_email、微信 nickname 快照）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identities', function (Blueprint $table) {
            $table->bigIncrements('identity_id');

            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();

            $table->string('provider', 16);            // username / email / wechat / apple
            $table->string('provider_user_id', 191);   // 用户名、邮箱、openid、Apple sub

            $table->string('union_id', 191)->nullable(); // 微信 unionid（多端打通）
            $table->string('nickname', 48)->nullable();
            $table->string('avatar_key', 255)->nullable();
            $table->json('meta')->nullable();

            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            // 同一 provider 下标识唯一；同一用户同一 provider 只允许一条
            $table->unique(['provider', 'provider_user_id'], 'identities_provider_user_unique');
            $table->unique(['user_id', 'provider'], 'identities_user_provider_unique');
            $table->index('union_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identities');
    }
};

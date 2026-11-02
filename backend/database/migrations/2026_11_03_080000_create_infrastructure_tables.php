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
 * T1 · migrations 基线（基础设施表，排序在 08:00，先于各业务域）。
 *
 * 表名统一 goat_ 前缀：与采集系统共用同一 PG 实例时，goat_* 一眼可辨归属，
 * 也不会和框架默认名（jobs/sessions）或采集侧表混在一起。
 *
 * 归属纪律：本文件是 T1 基线，**其他任务不得修改**；需要新表/新列请追加自己的迁移。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 缓存/锁：仅在 CACHE_STORE=database 时使用（默认走 Redis），保留兜底能力
        Schema::create('goat_cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        Schema::create('goat_cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });

        // 队列：database 驱动兜底（生产走 Redis + Horizon）
        Schema::create('goat_jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('goat_job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        // 失败队列：T10 的告警链路从这里取数（WAF 面板联动）
        Schema::create('goat_failed_jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();

            $table->index(['queue', 'failed_at']);
        });

        // 会话：PC 后台登录使用；C 端 Token 无状态
        Schema::create('goat_sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        // 找回密码令牌（users 表由 T2 建，此处只放令牌，不建跨 owner 外键）
        Schema::create('goat_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goat_password_reset_tokens');
        Schema::dropIfExists('goat_sessions');
        Schema::dropIfExists('goat_failed_jobs');
        Schema::dropIfExists('goat_job_batches');
        Schema::dropIfExists('goat_jobs');
        Schema::dropIfExists('goat_cache_locks');
        Schema::dropIfExists('goat_cache');
    }
};

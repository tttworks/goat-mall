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
 * T2 · 实名核身记录（按次付费 API，仅开店触发）。
 *
 * 合规红线：姓名/证件号属敏感个人信息，**不落明文**。
 *  - real_name / id_number 用 Laravel encrypted cast 落库（APP_KEY 加密）；
 *  - 另存 `id_number_hash`（HMAC-SHA256）用于「同证件多账号」风控去重，不可逆；
 *  - 只保留 `id_number_mask` 供客服/审计展示（如 3***********1234）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_verifications', function (Blueprint $table) {
            $table->bigIncrements('verification_id');

            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();

            $table->string('provider', 32)->default('null'); // 核身服务商标识（null/http…）
            $table->string('channel_ref', 64)->nullable();    // 服务商流水号，便于对账

            $table->text('real_name')->nullable();           // encrypted cast
            $table->text('id_number')->nullable();           // encrypted cast
            $table->string('id_number_hash', 64)->nullable();
            $table->string('id_number_mask', 32)->nullable();

            $table->string('status', 16)->default('pending'); // App\Enums\VerificationStatus
            $table->string('reason', 255)->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->ipAddress('submitted_ip')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('id_number_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_verifications');
    }
};

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
 * T2 · 用户主表。
 *
 * 设计要点：
 *  - 主键 `user_id`（T4 的 seller_id/buyer_id/bidder_id 均按此列引用，勿改回 id）；
 *  - 四个登录通道各自可空且唯一：username / email / phone(预留，短信初期不启用) / OAuth(走 identities 表)；
 *  - `password` 可空：Apple/微信注册用户可能永远不设密码；
 *  - 角色单列不建多对多：本项目只有买家/卖家/管理员三种，卖家身份还需 seller_profiles 佐证。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->bigIncrements('user_id');

            $table->string('username', 32)->nullable()->unique();
            $table->string('email', 120)->nullable()->unique();
            $table->string('phone', 32)->nullable()->unique(); // 预留：短信通道未启用
            $table->string('password')->nullable();

            $table->string('nickname', 48)->nullable();
            $table->string('avatar_key', 255)->nullable(); // UFile key，非 URL

            $table->string('role', 16)->default('buyer');   // App\Enums\UserRole
            $table->string('status', 16)->default('active'); // App\Enums\UserStatus
            $table->string('locale', 8)->default('zh_CN');

            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            // 后台按角色+状态筛选用户（审核/封禁）
            $table->index(['role', 'status']);
            $table->index('created_at');
        });

        // 昵称大小写不敏感唯一性由应用层保证（PG citext 需扩展，避免部署依赖）
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};

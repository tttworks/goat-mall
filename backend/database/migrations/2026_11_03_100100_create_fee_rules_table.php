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
 * 费率规则（后台可配置）：百分比 + 封顶 + 承担方，百分比可设 0（免费期）。
 * 契约：架构契约.md §2.2（交易域）；费率不写死代码，全部走本表。
 * owner：T4。命中规则取「范围最具体 + 优先级最高 + 当前时间在生效区间内」。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_rules', function (Blueprint $table) {
            $table->bigIncrements('fee_rule_id');
            // global / category / seller —— 由粗到细，具体者优先
            $table->string('scope', 16)->default('global');
            $table->string('scope_value', 64)->nullable();
            // 费率百分比，bip(万分比)避免浮点误差：150 = 1.50%；0 = 免费期
            $table->unsignedInteger('percent_bip')->default(0);
            // 封顶金额；NULL = 不封顶
            $table->decimal('cap_amount', 12, 2)->nullable();
            // 承担方：seller / buyer（费率展示与结算口径随之变化）
            $table->string('bearer', 16)->default('seller');
            // 结算币种，费率与封顶按该币种计量
            $table->string('currency', 3)->default('CNY');
            $table->unsignedSmallInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->string('remark', 255)->nullable();
            $table->timestamps();

            $table->index(['scope', 'scope_value', 'is_active'], 'idx_fee_rules_scope');
            $table->index(['effective_from', 'effective_to'], 'idx_fee_rules_window');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_rules');
    }
};

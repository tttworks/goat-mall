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
 * 行情源补一列「采集侧标识」。
 *
 * 站点展示源与采集系统的源不是同一套命名：站内叫 `internal`，采集侧叫 `site`；
 * 煤炉在库里是 `mercari`，采集任务是 `mercari_jp`。后台停用 eBay 之后，
 * 「采集触发」页的勾选项也应该跟着消失 —— 否则配了等于没配。
 * 这一列就是那根连线，运营不用看到它，但它必须存在。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('market_sources', 'collector_key')) {
            return;
        }

        Schema::table('market_sources', function (Blueprint $table) {
            // null = 该源不来自采集系统（人工补录）；不用 after()：列顺序无关功能，跨库更稳
            $table->string('collector_key', 24)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('market_sources', 'collector_key')) {
            return;
        }

        Schema::table('market_sources', function (Blueprint $table) {
            $table->dropColumn('collector_key');
        });
    }
};

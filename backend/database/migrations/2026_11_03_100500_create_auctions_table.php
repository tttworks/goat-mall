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
 * 竞拍 + 出价（契约 §2.2 auctions / bids，含 anti-snipe 字段）。
 * owner：T4。防狙击：结束前 anti_snipe_seconds 内出价 → end_at 顺延 extension_seconds，
 * 顺延次数上限 max_extensions（防无限延长套利）。
 * 竞态：出价一律在事务内 SELECT ... FOR UPDATE 锁 auctions 行（见 BiddingService）。
 * 幂等：bids.idempotency_key 唯一，同一 key 重复提交不重复抬价。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auctions', function (Blueprint $table) {
            $table->bigIncrements('auction_id');
            $table->unsignedBigInteger('listing_id');
            $table->unsignedBigInteger('seller_id');
            $table->unsignedBigInteger('figure_id')->nullable();
            // draft / scheduled / active / ended / settled / failed / cancelled
            $table->string('status', 24)->default('draft');
            $table->decimal('start_price', 12, 2);
            $table->decimal('reserve_price', 12, 2)->nullable();  // 保留价，未达则流拍
            $table->decimal('min_increment', 12, 2)->default(1.00);
            $table->decimal('current_price', 12, 2);
            $table->unsignedInteger('bid_count')->default(0);
            $table->unsignedBigInteger('winner_bidder_id')->nullable();
            $table->unsignedBigInteger('winning_bid_id')->nullable();
            $table->decimal('deposit_amount', 12, 2)->default(0); // 保证金，0 = 免保证金
            $table->string('currency', 3)->default('CNY');
            // 防狙击参数（可后台配置，默认 60s / 顺延 60s / 最多 10 次）
            $table->unsignedSmallInteger('anti_snipe_seconds')->default(60);
            $table->unsignedSmallInteger('extension_seconds')->default(60);
            $table->unsignedSmallInteger('max_extensions')->default(10);
            $table->unsignedSmallInteger('extension_count')->default(0);
            $table->timestamp('start_at');
            $table->timestamp('end_at');
            $table->timestamp('original_end_at');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'end_at'], 'idx_auctions_status_end');
            $table->index(['seller_id', 'status'], 'idx_auctions_seller_status');
            $table->index(['listing_id'], 'idx_auctions_listing');
        });

        Schema::create('bids', function (Blueprint $table) {
            $table->bigIncrements('bid_id');
            $table->unsignedBigInteger('auction_id');
            $table->unsignedBigInteger('bidder_id');
            $table->decimal('amount', 12, 2);
            // leading / outbid / won / lost / cancelled
            $table->string('status', 16)->default('leading');
            $table->string('idempotency_key', 64);
            // 记录本次出价是否触发顺延，便于复盘防狙击效果
            $table->boolean('extended')->default(false);
            $table->timestamp('placed_at');
            $table->timestamps();

            $table->foreign('auction_id', 'fk_bids_auction')
                ->references('auction_id')->on('auctions')->cascadeOnDelete();
            // 幂等键按「竞拍 + 客户端 key」唯一
            $table->unique(['auction_id', 'idempotency_key'], 'uniq_bids_idempotency');
            $table->index(['auction_id', 'amount'], 'idx_bids_auction_amount');
            $table->index(['bidder_id', 'status'], 'idx_bids_bidder_status');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(
                "ALTER TABLE auctions ADD CONSTRAINT chk_auctions_status CHECK (status IN
                 ('draft','scheduled','active','ended','settled','failed','cancelled'))"
            );
            Schema::getConnection()->statement(
                'ALTER TABLE auctions ADD CONSTRAINT chk_auctions_price CHECK (current_price >= 0 AND start_price >= 0)'
            );
            Schema::getConnection()->statement(
                "ALTER TABLE bids ADD CONSTRAINT chk_bids_status CHECK (status IN
                 ('leading','outbid','won','lost','cancelled'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bids');
        Schema::dropIfExists('auctions');
    }
};

<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 行情源（market_sources）。
 *
 * 只有后台能改这张表；采集侧只允许回执 `last_status` / `last_success_at`，
 * 不允许自己把源打开 —— 「谁能打开数据源」必须留在运营手里。
 *
 * @property int $market_source_id
 * @property string $source
 * @property string $label
 * @property string $channel
 * @property bool $enabled
 * @property string $currency
 * @property int|null $interval_minutes
 * @property int $priority
 * @property \Illuminate\Support\Carbon|null $last_success_at
 * @property string $last_status
 * @property string|null $last_message
 * @property string|null $note
 */
class MarketSource extends Model
{
    /** 渠道语义：决定这个源的数据由谁写入、能不能停 */
    public const CHANNELS = [
        'internal' => '站内成交回写',
        'collector' => '采集系统',
        'manual' => '人工补录',
    ];

    protected $table = 'market_sources';

    protected $primaryKey = 'market_source_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'interval_minutes' => 'integer',
            'priority' => 'integer',
            'last_success_at' => 'datetime',
        ];
    }

    /** @param Builder<MarketSource> $query */
    public function scopeEnabled(Builder $query): void
    {
        $query->where('enabled', true);
    }

    public function channelLabel(): string
    {
        return self::CHANNELS[$this->channel] ?? $this->channel;
    }

    /** 站内成交源不可停用：详情页价签只认它，停了等于把主价签弄没 */
    public function isInternal(): bool
    {
        return $this->channel === 'internal' || $this->source === 'internal';
    }
}

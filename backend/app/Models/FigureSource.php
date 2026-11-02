<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 图鑑数据源溯源（契约 §2.1 figure_sources）。owner：T3 建表，T9 主要写入。
 *
 * 复合主键 (source, external_id)：Eloquent 无复合主键支持，故本模型只用于读取，
 * 写入一律走 DB::table('figure_sources')->updateOrInsert()（见 FigureService::syncSources）。
 * 这样「同一外部源同一条目」天然幂等，采集侧重复投递不会产生脏行。
 *
 * @property string $source
 * @property string $external_id
 * @property int|null $figure_id
 * @property array<string, mixed>|null $raw_payload
 * @property \Illuminate\Support\Carbon|null $last_synced_at
 */
class FigureSource extends Model
{
    protected $table = 'figure_sources';

    /** 复合主键，无自增列 */
    public $incrementing = false;

    protected $primaryKey = 'external_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'last_synced_at' => 'datetime',
            'figure_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Figure, $this> */
    public function figure(): BelongsTo
    {
        return $this->belongsTo(Figure::class, 'figure_id', 'figure_id');
    }
}

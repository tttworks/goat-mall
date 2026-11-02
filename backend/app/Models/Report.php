<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Models;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\ReportTargetType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 举报工单（T6 · 社群治理）。
 *
 * 目标用多态两列（target_type + target_id），与 favorites 同一套写法；
 * 不做成三张表是因为后台只有一个队列页，混排才能按提交时间公平处理。
 */
class Report extends Model
{
    protected $table = 'reports';

    protected $primaryKey = 'report_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'target_type' => ReportTargetType::class,
            'reason' => ReportReason::class,
            'status' => ReportStatus::class,
            'handled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id', 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by', 'user_id');
    }

    public function isPending(): bool
    {
        return $this->status === ReportStatus::Pending;
    }

    /** 状态跃迁（唯一入口），先按枚举断言方向再落库 */
    public function transitTo(ReportStatus $to, array $attributes = []): self
    {
        $this->status = $this->status->assertTransitionTo($to);
        $this->fill($attributes)->save();

        return $this;
    }
}

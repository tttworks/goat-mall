<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Enums;

use App\Support\Concerns\StateTransitions;

/**
 * 举报工单状态（T6 · 社群治理）。
 *
 * pending ─→ handled（判定成立，运营已处置）
 *    └────→ dismissed（判定不成立）
 *
 * 终态不再流转：同一个人再举报同一目标时，服务层把原记录重新置为 pending 并覆盖证据
 * （唯一约束只留一条），历史判定结果在 handle_note 里被新证据覆盖是刻意为之——
 * 否则「先举报一次被驳回」就成了对方的内容护身符。
 */
enum ReportStatus: string
{
    use StateTransitions;

    case Pending = 'pending';
    case Handled = 'handled';
    case Dismissed = 'dismissed';

    public function transitions(): array
    {
        return [
            self::Pending->value => [self::Handled->value, self::Dismissed->value],
            self::Handled->value => [],
            self::Dismissed->value => [],
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => '待处理',
            self::Handled => '已处理',
            self::Dismissed => '不成立',
        };
    }
}

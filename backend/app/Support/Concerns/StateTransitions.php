<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Support\Concerns;

use App\Exceptions\IllegalStateTransition;

/**
 * 状态机通用能力（T4 用在 listing / order / payment / auction）。
 *
 * 设计铁律（契约 §"资金相关表必须带状态机 + 幂等键"）：
 * 1. 合法跃迁表写在各状态枚举的 transitions() 里，**单点定义**，别处不得再写 if/switch；
 * 2. DB 层另有 CHECK 约束兜底字面量，应用层负责「方向」合法；
 * 3. 非法跃迁一律抛 IllegalStateTransition（由 T5/T10 接告警，不静默吞掉）。
 */
trait StateTransitions
{
    /**
     * 返回「当前状态值 => 允许到达的状态值数组」。
     *
     * @return array<string, array<int, string>>
     */
    abstract public function transitions(): array;

    public function canTransitionTo(self $to): bool
    {
        return in_array($to->value, $this->transitions()[$this->value] ?? [], true);
    }

    /** @throws IllegalStateTransition */
    public function assertTransitionTo(self $to): self
    {
        if (! $this->canTransitionTo($to)) {
            throw new IllegalStateTransition(
                sprintf('非法状态跃迁：%s → %s', $this->value, $to->value)
            );
        }

        return $to;
    }

    /** @return array<int, string> */
    public function allowedNext(): array
    {
        return $this->transitions()[$this->value] ?? [];
    }

    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }
}

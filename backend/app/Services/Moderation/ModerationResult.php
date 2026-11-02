<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Moderation;

/**
 * 机审结论（T6）。
 *
 * 三态而不是布尔：真实机审会「说不准」（超时/服务不可用/低置信度），
 * 这时正确答案是转人工，而不是默认放行或默认拒绝。
 */
final class ModerationResult
{
    public const PASS = 'pass';

    public const REJECT = 'reject';

    public const REVIEW = 'review';

    /**
     * @param  array<int, string>  $labels  命中的风险标签（关键词/模型分类）
     */
    public function __construct(
        public readonly string $verdict,
        public readonly array $labels = [],
        public readonly string $reason = '',
    ) {}

    /** @param array<int, string> $labels */
    public static function pass(array $labels = []): self
    {
        return new self(self::PASS, $labels);
    }

    /** @param array<int, string> $labels */
    public static function reject(string $reason, array $labels = []): self
    {
        return new self(self::REJECT, $labels, $reason);
    }

    public static function defer(string $reason): self
    {
        return new self(self::REVIEW, [], $reason);
    }

    public function passed(): bool
    {
        return $this->verdict === self::PASS;
    }

    public function rejected(): bool
    {
        return $this->verdict === self::REJECT;
    }

    public function needsReview(): bool
    {
        return $this->verdict === self::REVIEW;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'verdict' => $this->verdict,
            'labels' => $this->labels,
            'reason' => $this->reason,
        ];
    }
}

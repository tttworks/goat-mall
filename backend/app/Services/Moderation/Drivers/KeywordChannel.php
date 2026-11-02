<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Moderation\Drivers;

use App\Services\Moderation\Contracts\ModerationChannel;
use App\Services\Moderation\ModerationResult;

/**
 * 关键词机审（T6 · 零采购默认驱动）。
 *
 * 定位：本地/联调与正式期兜底。词表由配置注入（GOAT_MODERATION_KEYWORDS，逗号分隔），
 * 不进代码——词表属于运营策略，随时会变，写死就得发版。
 * 命中即拒绝，不做「疑似转人工」：词表本身是可解释的硬规则。
 */
class KeywordChannel implements ModerationChannel
{
    /**
     * @param  array<int, string>  $keywords
     */
    public function __construct(private readonly array $keywords = []) {}

    public function name(): string
    {
        return 'keyword';
    }

    public function screen(string $content, array $imageKeys = []): ModerationResult
    {
        if ($content === '') {
            return ModerationResult::pass();
        }

        $normalized = mb_strtolower($content);

        foreach ($this->keywords as $keyword) {
            $needle = mb_strtolower(trim((string) $keyword));

            if ($needle !== '' && str_contains($normalized, $needle)) {
                return ModerationResult::reject(
                    sprintf('内容包含违规词「%s」，请修改后重新发布', $keyword),
                    ['keyword:'.$needle]
                );
            }
        }

        return ModerationResult::pass();
    }
}

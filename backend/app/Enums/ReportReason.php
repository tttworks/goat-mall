<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Enums;

/**
 * 举报理由（T6 · 社群治理）。
 *
 * 用固定枚举而不是自由文本：理由要能统计（哪类问题最多）、能配置处置策略，
 * 自由文本只能靠人工读。具体经过放 detail，长度上限 500。
 */
enum ReportReason: string
{
    case Fake = 'fake';
    case Prohibited = 'prohibited';
    case Fraud = 'fraud';
    case Abuse = 'abuse';
    case Spam = 'spam';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Fake => '假货 / 仿品',
            self::Prohibited => '违禁品',
            self::Fraud => '欺诈 / 骗取钱财',
            self::Abuse => '辱骂 / 人身攻击',
            self::Spam => '广告 / 刷屏',
            self::Other => '其他',
        };
    }
}

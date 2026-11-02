<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Support;

use InvalidArgumentException;

/**
 * 金额运算（契约：资金相关禁止 float 直接运算）。
 * 内部一律折成「分」整数算，输出定点字符串；不依赖 bcmath 扩展（服务器省一个依赖）。
 * 费率用 bip（万分比）整数表示：150 = 1.50%，避免 0.015 的二进制误差。
 */
final class Money
{
    /** 字符串/数值 → 分（四舍五入到分） */
    public static function toCents(string|int|float $amount): int
    {
        if (is_string($amount)) {
            $amount = trim($amount);
            if ($amount === '' || preg_match('/^-?\d+(\.\d+)?$/', $amount) !== 1) {
                throw new InvalidArgumentException("非法金额：{$amount}");
            }
        }

        return (int) round(((float) $amount) * 100);
    }

    /** 分 → 「0.00」定点字符串（DB decimal 与 JSON 输出统一格式） */
    public static function fromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /** 分 × bip 万分比 → 分（向下取整到分，费率差一分归平台不吃亏） */
    public static function applyBip(int $cents, int $bip): int
    {
        if ($bip <= 0) {
            return 0;
        }

        return intdiv($cents * $bip, 10000);
    }

    /** 分求和 */
    public static function sum(int ...$cents): int
    {
        return array_sum($cents);
    }
}

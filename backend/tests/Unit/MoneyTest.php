<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 金额工具单测（纯函数，不需要框架启动）。
 * 重点覆盖：分位换算、万分比向下取整、非法输入。
 */
class MoneyTest extends TestCase
{
    public function test_to_cents_handles_strings_and_rounding(): void
    {
        $this->assertSame(1999, Money::toCents('19.99'));
        $this->assertSame(2000, Money::toCents('20'));
        $this->assertSame(1, Money::toCents('0.01'));
        $this->assertSame(0, Money::toCents('0.001')); // 四舍五入到分
    }

    public function test_to_cents_rejects_garbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::toCents('19.99元');
    }

    public function test_from_cents_always_two_decimals(): void
    {
        $this->assertSame('19.99', Money::fromCents(1999));
        $this->assertSame('20.00', Money::fromCents(2000));
        $this->assertSame('0.00', Money::fromCents(0));
    }

    /** @return array<string, array{int, int, int}> */
    public static function bipProvider(): array
    {
        return [
            '0 = 免费期' => [10000, 0, 0],
            '150bip 即 1.5%' => [10000, 150, 150],
            '向下取整不吃亏' => [1999, 150, 29],
            '100%=' => [10000, 10000, 10000],
        ];
    }

    #[DataProvider('bipProvider')]
    public function test_apply_bip(int $cents, int $bip, int $expected): void
    {
        $this->assertSame($expected, Money::applyBip($cents, $bip));
    }

    public function test_negative_bip_yields_zero(): void
    {
        $this->assertSame(0, Money::applyBip(10000, -5));
    }
}

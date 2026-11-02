<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace Tests\Unit;

use App\Enums\FeeBearer;
use App\Models\FeeRule;
use App\Services\Trade\FeeCalculator;
use PHPUnit\Framework\TestCase;

/**
 * 费率语义单测（T4，GLM 语义复核点）。
 * 用内存构造的 FeeRule（不落库）验证四种组合：卖家承担 / 买家承担 / 封顶 / 免费期。
 */
class FeeCalculatorTest extends TestCase
{
    private FeeCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new FeeCalculator;
    }

    private function rule(int $bip, ?string $cap, FeeBearer $bearer): FeeRule
    {
        $rule = new FeeRule;
        $rule->percent_bip = $bip;
        $rule->cap_amount = $cap;
        $rule->bearer = $bearer;

        return $rule;
    }

    public function test_seller_bearer_deducts_fee_from_income(): void
    {
        $quote = $this->calc->quote('1000.00', $this->rule(150, null, FeeBearer::Seller));

        $this->assertSame('15.00', $quote->feeAmount);      // 1.5%
        $this->assertSame('1000.00', $quote->payableAmount);
        $this->assertSame('985.00', $quote->sellerIncome);
    }

    public function test_buyer_bearer_adds_fee_to_payable(): void
    {
        $quote = $this->calc->quote('1000.00', $this->rule(150, null, FeeBearer::Buyer));

        $this->assertSame('15.00', $quote->feeAmount);
        $this->assertSame('1015.00', $quote->payableAmount);
        $this->assertSame('1000.00', $quote->sellerIncome);
    }

    public function test_cap_limits_fee(): void
    {
        $quote = $this->calc->quote('10000.00', $this->rule(300, '50.00', FeeBearer::Seller));

        $this->assertSame('50.00', $quote->feeAmount); // 3% = 300，被 50 封顶
        $this->assertSame('9950.00', $quote->sellerIncome);
    }

    public function test_zero_percent_is_free_even_with_cap(): void
    {
        $quote = $this->calc->quote('888.88', $this->rule(0, '10.00', FeeBearer::Buyer));

        $this->assertSame('0.00', $quote->feeAmount);
        $this->assertSame('888.88', $quote->payableAmount);
        $this->assertSame('888.88', $quote->sellerIncome);
    }

    public function test_snapshot_contains_rule_id_for_audit(): void
    {
        $rule = $this->rule(200, null, FeeBearer::Seller);
        $rule->fee_rule_id = 7;

        $snapshot = $this->calc->quote('500.00', $rule)->snapshot();

        $this->assertSame(7, $snapshot['fee_rule_id']);
        $this->assertSame('seller', $snapshot['bearer']);
        $this->assertSame(200, $snapshot['percent_bip']);
    }
}

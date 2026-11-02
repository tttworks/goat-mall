<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Trade;

use App\Enums\FeeBearer;
use App\Support\Money;

/**
 * 一次费率测算的结果（下单时整体快照进 orders.fee_snapshot，事后改费率不影响历史单）。
 */
final readonly class FeeQuote
{
    public function __construct(
        public string $goodsAmount,
        public string $feeAmount,
        public string $payableAmount,
        public string $sellerIncome,
        public FeeBearer $bearer,
        public ?int $feeRuleId,
        public int $percentBip,
        public ?string $capAmount,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'goods_amount' => $this->goodsAmount,
            'fee_amount' => $this->feeAmount,
            'payable_amount' => $this->payableAmount,
            'seller_income' => $this->sellerIncome,
            'bearer' => $this->bearer->value,
            'fee_rule_id' => $this->feeRuleId,
            'percent_bip' => $this->percentBip,
            'cap_amount' => $this->capAmount,
        ];
    }

    public function toArray(): array
    {
        return $this->snapshot();
    }

    /** 便于断言：手续费分 */
    public function feeCents(): int
    {
        return Money::toCents($this->feeAmount);
    }
}

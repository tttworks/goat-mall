<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Trade;

use App\Enums\FeeBearer;
use App\Models\FeeRule;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 费率计算（T4）。规则全部来自 fee_rules 表，代码里只有一个兜底默认值（config goat.trade.default_fee）。
 *
 * 语义（待 GLM 5.3 复核）：
 *   fee = floor(货款 × 百分比)，若设了封顶则 min(fee, 封顶)
 *   承担方 = 卖家：应付 = 货款，卖家到手 = 货款 - fee
 *   承担方 = 买家：应付 = 货款 + fee，卖家到手 = 货款
 *   百分比 = 0（免费期）：fee = 0，不因封顶/承担方产生任何费用
 */
class FeeCalculator
{
    /** 范围权重：越具体越优先 */
    private const SCOPE_WEIGHT = ['seller' => 3, 'category' => 2, 'global' => 1];

    /**
     * 选出当前生效的规则（无命中返回 null）。
     * 顺序：范围权重 desc → priority desc → fee_rule_id desc（后建优先，便于紧急覆盖）。
     */
    public function resolve(?int $sellerId = null, ?string $category = null, ?Carbon $at = null): ?FeeRule
    {
        /** @var Collection<int, FeeRule> $rules */
        $rules = FeeRule::query()->inWindow($at)->get();

        return $rules
            ->filter(fn (FeeRule $rule) => match ($rule->scope) {
                'seller' => $sellerId !== null && (string) $rule->scope_value === (string) $sellerId,
                'category' => $category !== null && (string) $rule->scope_value === (string) $category,
                'global' => true,
                default => false,
            })
            ->sortByDesc(fn (FeeRule $rule) => [
                self::SCOPE_WEIGHT[$rule->scope] ?? 0,
                $rule->priority,
                $rule->fee_rule_id,
            ])
            ->first();
    }

    /** 按货款金额出测算结果；$rule 为 null 时用兜底默认费率 */
    public function quote(string $goodsAmount, ?FeeRule $rule = null): FeeQuote
    {
        $goodsCents = Money::toCents($goodsAmount);

        $percentBip = $rule?->percent_bip ?? (int) config('goat.trade.default_fee.percent_bip', 0);
        $capAmount = $rule ? $rule->cap_amount : config('goat.trade.default_fee.cap_amount');
        $bearer = $rule?->bearer ?? FeeBearer::from((string) config('goat.trade.default_fee.bearer', 'seller'));

        $feeCents = Money::applyBip($goodsCents, $percentBip);

        if ($feeCents > 0 && $capAmount !== null && $capAmount !== '') {
            $feeCents = min($feeCents, Money::toCents((string) $capAmount));
        }

        // 免费期硬语义：百分比 0 一律不收，避免封顶配置误伤
        if ($percentBip <= 0) {
            $feeCents = 0;
        }

        $feeCents = max($feeCents, 0);

        [$payableCents, $sellerIncomeCents] = match ($bearer) {
            FeeBearer::Seller => [$goodsCents, max($goodsCents - $feeCents, 0)],
            FeeBearer::Buyer => [$goodsCents + $feeCents, $goodsCents],
        };

        return new FeeQuote(
            goodsAmount: Money::fromCents($goodsCents),
            feeAmount: Money::fromCents($feeCents),
            payableAmount: Money::fromCents($payableCents),
            sellerIncome: Money::fromCents($sellerIncomeCents),
            bearer: $bearer,
            feeRuleId: $rule?->fee_rule_id,
            percentBip: $percentBip,
            capAmount: $capAmount === null || $capAmount === '' ? null : Money::fromCents(Money::toCents((string) $capAmount)),
        );
    }
}

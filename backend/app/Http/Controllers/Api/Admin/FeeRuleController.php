<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api\Admin;

use App\Enums\FeeBearer;
use App\Models\FeeRule;
use App\Models\Order;
use App\Services\Trade\FeeCalculator;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * 费率后台（T4 出口：百分比 + 封顶 + 承担方，可设 0 = 免费期）。
 *
 * 设计取舍：**费率改动只影响新订单**。订单下单时把 FeeQuote 快照进
 * `orders.fee_snapshot` + `orders.fee_rule_id`，所以这里可以放心改、放心停用，
 * 不需要「改价等于改历史账」的补偿逻辑。
 *
 * 因此删除语义是「归档」：被历史订单引用过的规则不允许物理删除（会断掉财务对账线索），
 * 只能停用；从未被引用过的规则才真正删掉，便于清理录错的脏数据。
 */
class FeeRuleController extends Controller
{
    public function __construct(private readonly FeeCalculator $calculator) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scope' => ['nullable', 'string', 'in:global,category,seller'],
            'active' => ['nullable', 'string', 'in:0,1'],
            'keyword' => ['nullable', 'string', 'max:64'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $page = FeeRule::query()
            ->when($validated['scope'] ?? null, fn ($query, $scope) => $query->where('scope', $scope))
            ->when(isset($validated['active']), fn ($query) => $query->where('is_active', $validated['active'] === '1'))
            ->when($validated['keyword'] ?? null, fn ($query, $keyword) => $query->where('scope_value', 'like', '%'.$keyword.'%'))
            ->orderByRaw("case scope when 'seller' then 1 when 'category' then 2 else 3 end")
            ->orderByDesc('priority')
            ->orderByDesc('fee_rule_id')
            ->paginate((int) ($validated['per_page'] ?? 30));

        return response()->json([
            'data' => collect($page->items())->map(fn (FeeRule $rule) => self::payload($rule))->all(),
            'meta' => [
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'bearers' => collect(FeeBearer::cases())->map(fn (FeeBearer $bearer) => [
                    'value' => $bearer->value,
                    'label' => $bearer->label(),
                ])->all(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $rule = FeeRule::query()->create($this->validated($request));

        return response()->json(['data' => self::payload($rule)], 201);
    }

    public function update(Request $request, int $feeRuleId): JsonResponse
    {
        $rule = FeeRule::query()->whereKey($feeRuleId)->firstOrFail();
        $rule->fill($this->validated($request, $rule))->save();

        return response()->json(['data' => self::payload($rule->refresh())]);
    }

    public function destroy(int $feeRuleId): JsonResponse
    {
        $rule = FeeRule::query()->whereKey($feeRuleId)->firstOrFail();

        $referenced = Order::query()->where('fee_rule_id', $feeRuleId)->exists();
        if ($referenced) {
            // 历史订单挂着这条规则：只归档，不删
            $rule->fill(['is_active' => false])->save();

            return response()->json([
                'data' => self::payload($rule->refresh()),
                'message' => '该规则已被历史订单引用，已停用而非删除（保留财务对账线索）',
            ]);
        }

        $rule->delete();

        return response()->json(['data' => null, 'message' => '已删除']);
    }

    /**
     * 费率高亮试算：后台改完规则立刻能看到「1 万块收多少」，
     * 不用真的下一单。C 端下单前展示费用也用同一口径（FeeCalculator）。
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'goods_amount' => ['required', 'numeric', 'min:0.01', 'max:9999999'],
            'seller_id' => ['nullable', 'integer', 'exists:users,user_id'],
            'category' => ['nullable', 'string', 'max:64'],
        ]);

        $rule = $this->calculator->resolve(
            isset($validated['seller_id']) ? (int) $validated['seller_id'] : null,
            $validated['category'] ?? null
        );

        $quote = $this->calculator->quote(Money::fromCents(Money::toCents((string) $validated['goods_amount'])), $rule);

        return response()->json([
            'data' => $quote->snapshot() + [
                'rule' => $rule === null ? null : self::payload($rule),
                'matched' => $rule !== null,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    public static function payload(FeeRule $rule): array
    {
        return [
            'fee_rule_id' => $rule->fee_rule_id,
            'scope' => $rule->scope,
            'scope_value' => $rule->scope_value,
            'percent_bip' => $rule->percent_bip,
            // 前端展示百分比，不在前端做 /10000（口径只留在后端一处）
            'percent_label' => rtrim(rtrim(number_format($rule->percent_bip / 100, 2, '.', ''), '0'), '.').'%',
            'cap_amount' => $rule->cap_amount,
            'bearer' => $rule->bearer->value,
            'bearer_label' => $rule->bearer->label(),
            'currency' => $rule->currency,
            'priority' => $rule->priority,
            'is_active' => $rule->is_active,
            'effective_from' => $rule->effective_from?->toIso8601String(),
            'effective_to' => $rule->effective_to?->toIso8601String(),
            'remark' => $rule->remark,
            'created_at' => $rule->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?FeeRule $rule = null): array
    {
        $required = $rule === null ? 'required' : 'sometimes';

        $validated = $request->validate([
            'scope' => [$required, 'string', Rule::in(['global', 'category', 'seller'])],
            'scope_value' => ['nullable', 'string', 'max:64'],
            // bip：150 = 1.50%，上限 10000 = 100%；0 合法（免费期）
            'percent_bip' => [$required, 'integer', 'between:0,10000'],
            'cap_amount' => ['nullable', 'numeric', 'min:0'],
            'bearer' => [$required, 'string', Rule::in(array_column(FeeBearer::cases(), 'value'))],
            'currency' => ['nullable', 'string', 'size:3'],
            'priority' => ['nullable', 'integer', 'between:0,255'],
            'is_active' => ['nullable', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'remark' => ['nullable', 'string', 'max:255'],
        ]);

        $scope = $validated['scope'] ?? $rule?->scope ?? 'global';
        if ($scope !== 'global' && blank($validated['scope_value'] ?? $rule?->scope_value)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'scope_value' => 'category / seller 级规则必须填具体的类目或卖家 ID',
            ]);
        }

        if ($scope === 'global') {
            $validated['scope_value'] = null;
        }

        return $validated;
    }
}

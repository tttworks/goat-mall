<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api\Admin;

use App\Services\Collector\InternalCollectorClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * 采集触发（T7 后台侧，仅 admin）。
 *
 * 站点只做「按需触发 + 查进度」，抓什么、怎么抓全在采集系统里（契约 §1 隔离原则）。
 * 因此这里没有任何解析/清洗逻辑：多写一行，就等于把采集规则搬进站点，权属边界会糊。
 */
class CollectorController extends Controller
{
    public function __construct(private readonly InternalCollectorClient $collector) {}

    public function crawlFigure(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'figure_id' => ['nullable', 'integer', 'min:1', 'required_without:external_ids'],
            'external_ids' => ['nullable', 'array', 'required_without:figure_id'],
            'external_ids.*' => ['string', 'max:64'],
        ]);

        return response()->json(
            ['data' => $this->collector->crawlFigure($validated)],
            202
        );
    }

    public function crawlPrice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'figure_id' => ['required', 'integer', 'min:1'],
            'sources' => ['nullable', 'array', 'max:3'],
            // 三源口径与 price_points.source 枚举一致（契约 §2.3）
            'sources.*' => [Rule::in(['site', 'mercari_jp', 'ebay'])],
        ]);

        return response()->json(
            ['data' => $this->collector->crawlPrice($validated)],
            202
        );
    }

    public function job(string $job): JsonResponse
    {
        return response()->json(['data' => $this->collector->job($job)]);
    }
}

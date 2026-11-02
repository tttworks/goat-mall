<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api;

use App\Models\Figure;
use App\Services\Market\MarketSourceService;
use App\Services\Market\QuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * 行情（T9 上游写入 / 详情页读取）。
 *
 * 两个端点够用：概览给价签与多源对比，K 线给趋势图。
 * 不提供「写行情」的公开接口——行情只由本站成交与采集系统写入，
 * 对外开放写入等于把价格话语权交出去。
 */
class QuoteController extends Controller
{
    public function __construct(
        private readonly QuoteService $quotes,
        private readonly MarketSourceService $sources,
    ) {}

    public function summary(Request $request, int $figureId): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'between:1,365'],
        ]);

        Figure::query()->published()->whereKey($figureId)->firstOrFail();

        return response()->json([
            'data' => $this->quotes->summary($figureId, (int) ($validated['days'] ?? 30)),
        ]);
    }

    public function kline(Request $request, int $figureId): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'between:1,365'],
            // 白名单含「已停用但登记过」的源：停用只是不再展示，回看历史不该 422
            'source' => ['nullable', 'string', 'in:'.implode(',', array_keys($this->sources->labels(false))).',all'],
        ]);

        Figure::query()->published()->whereKey($figureId)->firstOrFail();

        $source = $validated['source'] ?? 'internal';

        return response()->json([
            'data' => $this->quotes->series(
                $figureId,
                (int) ($validated['days'] ?? 90),
                $source === 'all' ? null : $source
            ),
        ]);
    }
}

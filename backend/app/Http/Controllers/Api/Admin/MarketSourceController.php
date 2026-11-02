<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api\Admin;

use App\Models\MarketSource;
use App\Models\PricePoint;
use App\Services\Market\MarketSourceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * 行情源配置（T9 出口：源名称 / 启停 / 采集间隔 / 入库量）。
 *
 * 边界说明：
 * - 这里只说「源」，不碰价格。价格点的增删改仍归采集系统与站内成交回写；
 * - 采集侧回执（last_status / last_success_at）由采集系统按契约直接 UPDATE 共享表，
 *   站点侧不开放写入端点 —— 避免多一条能伪造「源健康」的通道；
 * - 停用一个源只影响展示与新采集，历史 price_points 不删：删了就没法回看行情。
 */
class MarketSourceController extends Controller
{
    public function __construct(private readonly MarketSourceService $sources) {}

    public function index(): JsonResponse
    {
        // 后台点开才建默认数据：迁移只做 schema，读接口也不产生写操作
        $this->sources->ensureDefaults();

        $stats = $this->sources->stats();
        $rows = $this->sources->all()
            ->map(fn (MarketSource $source) => MarketSourceService::payload($source, $stats[$source->source] ?? []))
            ->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'total' => $rows->count(),
                'enabled' => $rows->where('enabled', true)->count(),
                'points_total' => array_sum(array_map(
                    static fn (array $stat) => (int) $stat['total'],
                    $stats
                )),
                // 覆盖图鑑按图鑑去重：各源 stats.figures 相加会重复计数，只能当单源指标
                'figures_total' => (int) PricePoint::query()->distinct()->count('figure_id'),
                'channels' => collect(MarketSource::CHANNELS)
                    ->map(static fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values()
                    ->all(),
            ],
        ]);
    }

    public function update(Request $request, int $marketSourceId): JsonResponse
    {
        $source = MarketSource::query()->whereKey($marketSourceId)->firstOrFail();

        $validated = $request->validate([
            'label' => ['sometimes', 'string', 'max:32'],
            'enabled' => ['sometimes', 'boolean'],
            'currency' => ['sometimes', 'string', 'size:3'],
            // 5 分钟 ~ 7 天；null = 不定时（人工补录）
            'interval_minutes' => ['nullable', 'integer', 'between:5,10080'],
            'priority' => ['sometimes', 'integer', 'between:0,9999'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $this->sources->update($source, $validated);

        $stat = $this->sources->stats()[$source->source] ?? [];

        return response()->json([
            'data' => MarketSourceService::payload($source->refresh(), $stat),
        ]);
    }
}

<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Market;

use App\Models\MarketSource;
use App\Models\PricePoint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * 行情源登记与统计。
 *
 * 分工：这张表只管「源」这件事 —— 叫什么、开不开、多久采一次、最近成没成；
 * 具体价格仍全部落在 price_points，聚合口径留在 QuoteService。
 *
 * 两个刻意的取舍：
 * 1. 表为空时**不写库**，直接回落到 QuoteService::SOURCES 常量。
 *    读接口（C 端行情）不应产生写操作，否则一次冷启动就把只读事务变成写事务。
 * 2. 补齐默认源只在 admin 侧调用（ensureDefaults）。后台点开配置页才建数据，
 *    这样迁移只做 schema，不带数据迁移逻辑。
 */
class MarketSourceService
{
    /**
     * 出厂默认源。新增源需要采集侧先能产出该 source 的数据，
     * 所以这里只登记已实现的三类 + 站内回写。
     *
     * @var array<string, array<string, mixed>>
     */
    public const DEFAULTS = [
        'internal' => [
            'label' => '站内成交',
            'channel' => 'internal',
            // 站内也由采集系统回写，所以采集侧标识同样存在
            'collector_key' => 'site',
            'priority' => 10,
            'interval_minutes' => null,
            'note' => '买家确认收货时自动回写；详情页主价签只认它，不可停用',
        ],
        'ebay' => [
            'label' => 'eBay',
            'channel' => 'collector',
            'collector_key' => 'ebay',
            'priority' => 20,
            'interval_minutes' => 360,
            'note' => 'Browse API 过渡源，海外成交参考价',
        ],
        'mercari' => [
            'label' => '煤炉',
            'channel' => 'collector',
            'collector_key' => 'mercari_jp',
            'priority' => 30,
            'interval_minutes' => 720,
            'note' => '日本出口采集（goat-collector），到手价口径含运费',
        ],
        'manual' => [
            'label' => '人工补录',
            'channel' => 'manual',
            'collector_key' => null,
            'priority' => 40,
            'interval_minutes' => null,
            'note' => '运营按成交截图手动补点，用于冷门图鑑',
        ],
    ];

    /** 表结构是否存在：老库未迁移时不该让 C 端行情整页 500 */
    private ?bool $tableReady = null;

    /** @var array<string, string>|null */
    private ?array $labelsCache = null;

    /**
     * 补齐缺失的默认源（幂等）。只在 admin 打开配置页时调用。
     *
     * 已存在的源一律不动 —— 运营改过的名称、开关不能被一次部署冲掉。
     */
    public function ensureDefaults(): void
    {
        if (! $this->hasTable()) {
            return;
        }

        $existing = MarketSource::query()->get()->keyBy('source');

        foreach (self::DEFAULTS as $source => $attributes) {
            /** @var MarketSource|null $row */
            $row = $existing->get($source);

            if ($row !== null) {
                // 补列迁移之前建的行没有采集侧标识：只补这个契约字段，运营改过的名称/开关一律不动
                if ($row->collector_key === null && ($attributes['collector_key'] ?? null) !== null) {
                    $row->forceFill(['collector_key' => $attributes['collector_key']])->save();
                }

                continue;
            }

            MarketSource::query()->create([
                'source' => $source,
                'label' => $attributes['label'],
                'channel' => $attributes['channel'],
                'collector_key' => $attributes['collector_key'],
                'enabled' => true,
                'currency' => 'CNY',
                'interval_minutes' => $attributes['interval_minutes'],
                'priority' => $attributes['priority'],
                'last_status' => 'idle',
                'note' => $attributes['note'],
            ]);
        }

        $this->labelsCache = null;
    }

    /** @return Collection<int, MarketSource> */
    public function all(): Collection
    {
        if (! $this->hasTable()) {
            return collect();
        }

        return MarketSource::query()->orderBy('priority')->orderBy('source')->get();
    }

    /**
     * source => label，供聚合层遍历。
     *
     * @param  bool  $onlyEnabled  只返回已启用的源（C 端展示口径）；false 用于校验历史查询参数
     * @return array<string, string>
     */
    public function labels(bool $onlyEnabled = true): array
    {
        if ($onlyEnabled && $this->labelsCache !== null) {
            return $this->labelsCache;
        }

        $labels = [];

        if ($this->hasTable()) {
            $rows = MarketSource::query()
                ->when($onlyEnabled, fn ($query) => $query->where('enabled', true))
                ->orderBy('priority')
                ->orderBy('source')
                ->get();

            foreach ($rows as $row) {
                $labels[$row->source] = $row->label;
            }
        }

        // 表空（或尚未迁移）：回落到常量，保证行情页永远有源可选，不会白屏
        if ($labels === []) {
            $labels = QuoteService::SOURCES;
        }

        if ($onlyEnabled) {
            $this->labelsCache = $labels;
        }

        return $labels;
    }

    /**
     * 各源入库量统计：一次条件聚合出总点数、覆盖图鑑数、近 N 天点数、最后入库时间。
     *
     * 用条件聚合而不是逐源 count，是因为源数量会增长，
     * N 个源 N 次查询在后台列表页会明显变慢。
     *
     * @return array<string, array<string, mixed>>
     */
    public function stats(int $days = 30): array
    {
        if (! $this->hasTable()) {
            return [];
        }

        $since = Carbon::now()->subDays(max($days, 1));

        $rows = PricePoint::query()
            ->selectRaw('source, count(*) as total, count(distinct figure_id) as figures')
            ->selectRaw('sum(case when sold_at >= ? then 1 else 0 end) as recent', [$since])
            ->selectRaw('max(sold_at) as last_sold_at')
            ->selectRaw('max(created_at) as last_ingested_at')
            ->groupBy('source')
            ->get();

        $stats = [];

        foreach ($rows as $row) {
            $stats[(string) $row->source] = [
                'total' => (int) $row->total,
                'figures' => (int) $row->figures,
                'recent' => (int) $row->recent,
                'last_sold_at' => $row->last_sold_at === null ? null : Carbon::parse($row->last_sold_at)->toIso8601String(),
                'last_ingested_at' => $row->last_ingested_at === null ? null : Carbon::parse($row->last_ingested_at)->toIso8601String(),
            ];
        }

        return $stats;
    }

    /**
     * 更新一个源。source / channel 不可改：改名等于换数据来源，应该新增一条而不是改旧的。
     *
     * @param  array<string, mixed>  $data
     */
    public function update(MarketSource $marketSource, array $data): MarketSource
    {
        if (array_key_exists('enabled', $data) && $data['enabled'] === false && $marketSource->isInternal()) {
            // 站内成交是详情页主价签的唯一可信来源，停了会让所有图鑑显示「暂无成交」
            throw ValidationException::withMessages([
                'enabled' => '站内成交源不可停用：它是详情页主价签与成交样本的唯一来源',
            ]);
        }

        $marketSource->fill($data)->save();

        $this->labelsCache = null;

        return $marketSource;
    }

    /**
     * 后台出参。stats 由调用方批量传入，避免每条源各查一次库。
     *
     * @param  array<string, mixed>  $stat
     * @return array<string, mixed>
     */
    public static function payload(MarketSource $marketSource, array $stat = []): array
    {
        // 超过 2 个采集周期没成功就标黄；「从未同步过」不算中断，用 last_status=idle 表达
        $overdue = $marketSource->last_success_at !== null
            && $marketSource->last_success_at->lt(Carbon::now()->subHours((int) max(1, ($marketSource->interval_minutes ?? 1440) * 2)));

        return [
            'market_source_id' => $marketSource->market_source_id,
            'source' => $marketSource->source,
            'label' => $marketSource->label,
            'channel' => $marketSource->channel,
            'channel_label' => $marketSource->channelLabel(),
            // 只读：采集系统的源标识，改了会打错源，不由运营维护
            'collector_key' => $marketSource->collector_key,
            'enabled' => $marketSource->enabled,
            'locked' => $marketSource->isInternal(),
            'currency' => $marketSource->currency,
            'interval_minutes' => $marketSource->interval_minutes,
            'priority' => $marketSource->priority,
            'last_status' => $marketSource->last_status,
            'last_success_at' => $marketSource->last_success_at?->toIso8601String(),
            // 中断要比「报错」更早被发现：采集一直不成功但也没抛错时，只能靠时间戳判断
            'stale' => $marketSource->channel === 'collector' && $overdue,
            'last_message' => $marketSource->last_message,
            'note' => $marketSource->note,
            'stats' => [
                'total' => $stat['total'] ?? 0,
                'figures' => $stat['figures'] ?? 0,
                'recent' => $stat['recent'] ?? 0,
                'last_sold_at' => $stat['last_sold_at'] ?? null,
                'last_ingested_at' => $stat['last_ingested_at'] ?? null,
            ],
        ];
    }

    private function hasTable(): bool
    {
        return $this->tableReady ??= Schema::hasTable('market_sources');
    }
}

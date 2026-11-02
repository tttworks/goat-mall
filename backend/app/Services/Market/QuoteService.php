<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Market;

use App\Models\Listing;
use App\Models\Order;
use App\Models\PricePoint;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * 行情聚合（T9 上游写入 / 站点读取）。三源统一：internal（站内成交）+ ebay + mercari。
 *
 * 三条口径：
 * 1. 均价用「成交中位数」而非算术平均——煤炉/雅虎常有 1 円 钓鱼价和天价挂单，均值会被拉爆；
 * 2. 汇率不做实时换算：入库即按采集侧当日汇率折算成 CNY（报价币种落 currency 列留痕），
 *    行情页只做同币种比较，避免前端每次刷新都要一个汇率接口；
 * 3. K 线按天聚合（open=当日首笔、close=当日末笔、high/low=极值、volume=笔数），
 *    够用且不需要 Timescale 的 time_bucket。
 */
class QuoteService
{
    /**
     * 出厂行情源（与 C 端 chips 一一对应）。
     *
     * 运行时以 `market_sources` 表为准（后台可改名、可停用）；常量只在
     * 「表尚未迁移 / 表为空」时兜底，避免行情页因为没配置就没有源可选。
     */
    public const SOURCES = [
        'internal' => '站内成交',
        'ebay' => 'eBay',
        'mercari' => '煤炉',
        'manual' => '人工补录',
    ];

    /** 单次聚合最多取多少点：行情页做趋势不需要全量，防大图鑑把内存打满 */
    private const MAX_POINTS = 2000;

    public function __construct(private readonly MarketSourceService $sources) {}

    /**
     * 多源概览：各源最新价 + 站内成交统计（详情页顶部价签与「近 30 天成交 N 笔」）。
     *
     * @return array<string, mixed>
     */
    public function summary(int $figureId, int $days = 30): array
    {
        $since = Carbon::now()->subDays(max($days, 1));
        $points = $this->recent($figureId, $since);

        $bySources = [];
        // 遍历配置里已启用的源：停用后该源不再出现在多源对比里，但历史点仍在库中
        foreach ($this->sources->labels() as $source => $label) {
            $rows = $points->where('source', $source)->values();
            $latest = $rows->first();

            $bySources[] = [
                'source' => $source,
                'label' => $label,
                'count' => $rows->count(),
                'latest_price' => $latest === null ? null : (string) $latest->price,
                'latest_at' => $latest?->sold_at?->toIso8601String(),
                'median_price' => $rows->isEmpty() ? null : $this->median($rows->pluck('price')->all()),
            ];
        }

        $internal = $points->where('source', 'internal')->pluck('price')->all();

        return [
            'window' => [
                'days' => $days,
                'from' => $since->toDateString(),
                'to' => Carbon::now()->toDateString(),
            ],
            // 详情页主价签：优先站内成交中位数，其次任一源最新价（前端据 available 决定是否灰显）
            'headline' => [
                'available' => $points->isNotEmpty(),
                'median' => $internal === [] ? null : $this->median($internal),
                'low' => $internal === [] ? null : $this->minOf($internal),
                'high' => $internal === [] ? null : $this->maxOf($internal),
                'sample' => count($internal),
                'currency' => (string) ($points->first()->currency ?? 'CNY'),
            ],
            'sources' => $bySources,
            'updated_at' => $points->first()?->created_at?->toIso8601String(),
        ];
    }

    /**
     * K 线序列（按天聚合）。
     *
     * @return array<string, mixed>
     */
    public function series(int $figureId, int $days = 90, ?string $source = 'internal'): array
    {
        $since = Carbon::now()->subDays(max($days, 1));
        $points = $this->recent($figureId, $since)
            ->when($source !== null && $source !== '', fn (Collection $rows) => $rows->where('source', $source))
            ->sortBy(fn (PricePoint $point) => $point->sold_at?->getTimestamp() ?? 0)
            ->values();

        $buckets = [];

        foreach ($points as $point) {
            $day = ($point->sold_at ?? $point->created_at)?->toDateString();
            if ($day === null) {
                continue;
            }

            $cents = Money::toCents((string) $point->price);

            if (! isset($buckets[$day])) {
                $buckets[$day] = ['open' => $cents, 'close' => $cents, 'high' => $cents, 'low' => $cents, 'volume' => 0];
            }

            $buckets[$day]['close'] = $cents;
            $buckets[$day]['high'] = max($buckets[$day]['high'], $cents);
            $buckets[$day]['low'] = min($buckets[$day]['low'], $cents);
            $buckets[$day]['volume']++;
        }

        $candles = [];
        foreach ($buckets as $day => $bucket) {
            $candles[] = [
                'date' => $day,
                'open' => Money::fromCents($bucket['open']),
                'close' => Money::fromCents($bucket['close']),
                'high' => Money::fromCents($bucket['high']),
                'low' => Money::fromCents($bucket['low']),
                'volume' => $bucket['volume'],
            ];
        }

        $closes = array_map(static fn (array $candle) => (float) $candle['close'], $candles);

        return [
            'source' => $source,
            'days' => $days,
            'candles' => $candles,
            // 前端 mini 图与涨跌标签直接用这两个值，避免把统计塞进渲染层
            'change' => count($closes) < 2 ? null : $this->changeRate($closes[0], $closes[count($closes) - 1]),
            'available_sources' => $this->availableSources($figureId, $since),
        ];
    }

    /**
     * 站内成交回写：买家确认收货即视为一次真实成交。
     *
     * 该方法吞掉所有异常——行情是「附加信息」，绝不能因为写行情失败让确认收货回滚。
     */
    public function recordFromOrder(Order $order): int
    {
        $recorded = 0;

        try {
            foreach ($order->items as $item) {
                // order_items 已冗余 figure_id（下单时快照），不必回查 listings
                $figureId = $item->figure_id
                    ?? Listing::query()->whereKey($item->listing_id)->value('figure_id');

                if ($figureId === null) {
                    continue;
                }

                PricePoint::query()->create([
                    'figure_id' => (int) $figureId,
                    'source' => 'internal',
                    'currency' => (string) ($order->currency ?: 'CNY'),
                    'price' => (string) $item->unit_price,
                    'shipping_amount' => (string) ($order->shipping_amount ?? '0.00'),
                    'condition' => null,
                    // 站内成交也用唯一外部 ID：重复确认收货（异常重放）不会重复计入行情
                    'external_id' => 'order:'.$order->order_id.':'.$item->order_item_id,
                    'title' => (string) $item->title_snapshot,
                    'sold_at' => $order->confirmed_at ?? Carbon::now(),
                ]);

                $recorded++;
            }
        } catch (\Throwable $e) {
            report($e);
            Log::channel('goat.trade')->warning('quote.record_failed', [
                'order_id' => $order->order_id,
                'message' => $e->getMessage(),
            ]);
        }

        return $recorded;
    }

    /** @return Collection<int, PricePoint> */
    private function recent(int $figureId, Carbon $since): Collection
    {
        return PricePoint::query()
            ->where('figure_id', $figureId)
            ->where(fn ($query) => $query->where('sold_at', '>=', $since)->orWhereNull('sold_at'))
            ->orderByDesc('sold_at')
            ->orderByDesc('price_point_id')
            ->limit(self::MAX_POINTS)
            ->get();
    }

    /**
     * C 端 chips 的可选源：既要库里有数据，也要后台仍启用。
     *
     * 只按库过滤会出现「后台已停用 eBay，前端还在给 eBay 标签点了没数据」的死入口。
     *
     * @return array<int, string>
     */
    private function availableSources(int $figureId, Carbon $since): array
    {
        $enabled = array_keys($this->sources->labels());

        return PricePoint::query()
            ->where('figure_id', $figureId)
            ->where('sold_at', '>=', $since)
            ->distinct()
            ->pluck('source')
            ->map(static fn ($source) => (string) $source)
            ->filter(static fn (string $source) => in_array($source, $enabled, true))
            ->values()
            ->all();
    }

    /** @param array<int, string|float> $amounts */
    private function median(array $amounts): string
    {
        $cents = array_map(static fn ($amount) => Money::toCents((string) $amount), $amounts);
        sort($cents);

        $count = count($cents);
        if ($count === 0) {
            return '0.00';
        }

        $middle = intdiv($count, 2);
        $value = $count % 2 === 1
            ? $cents[$middle]
            : (int) round(($cents[$middle - 1] + $cents[$middle]) / 2);

        return Money::fromCents($value);
    }

    /** @param array<int, string|float> $amounts */
    private function minOf(array $amounts): string
    {
        $cents = array_map(static fn ($amount) => Money::toCents((string) $amount), $amounts);

        return Money::fromCents(min($cents));
    }

    /** @param array<int, string|float> $amounts */
    private function maxOf(array $amounts): string
    {
        $cents = array_map(static fn ($amount) => Money::toCents((string) $amount), $amounts);

        return Money::fromCents(max($cents));
    }

    private function changeRate(float $from, float $to): ?float
    {
        if ($from <= 0.0) {
            return null;
        }

        return round(($to - $from) / $from * 100, 2);
    }
}

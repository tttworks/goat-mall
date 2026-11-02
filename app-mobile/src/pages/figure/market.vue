<!--
  GOAT 潮玩交易平台 / GOAT 潮玩数据采集
  Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
  Author: Aloysius Luo
  本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
-->

<template>
  <view class="market">
    <view class="market__head">
      <text class="market__title">{{ figure?.name || '图鑑行情' }}</text>
      <text class="g-dim">{{ figure?.maker || '厂牌未收录' }}</text>
    </view>

    <view class="quote">
      <view class="quote__main">
        <text class="g-dim">站内成交中位数 · 近 {{ summary?.window?.days || 30 }} 天</text>
        <text v-if="hasMedian" class="g-price quote__price">
          <text class="g-price__symbol">¥</text>{{ money(headline.median) }}
        </text>
        <text v-else class="quote__empty-price">暂无站内成交</text>

        <view v-if="hasMedian" class="quote__range">
          <text class="g-dim">低 ¥{{ money(headline.low) }}</text>
          <text class="g-dim">高 ¥{{ money(headline.high) }}</text>
          <text class="g-dim">{{ headline.sample }} 笔样本</text>
        </view>
      </view>

      <view v-if="change !== null" class="quote__change" :class="{ 'quote__change--down': change < 0 }">
        <text>{{ change > 0 ? '+' : '' }}{{ change }}%</text>
        <text class="g-dim">区间涨跌</text>
      </view>
    </view>

    <view class="market__sources">
      <view
        v-for="source in sourceOptions"
        :key="source.value"
        class="g-chip"
        :class="{ 'g-chip--active': source.value === pickedSource }"
        @tap="pickSource(source.value)"
      >
        <text>{{ source.label }}</text>
      </view>
    </view>

    <!-- 蜡烛图：纯 view 定位，不引图表库；一行一天，涨绿跌红 -->
    <view v-if="candles.length" class="chart">
      <view class="chart__head">
        <text class="g-muted">{{ pickedLabel }} · 按天聚合</text>
        <text class="g-dim">{{ candles.length }} 个交易日</text>
      </view>

      <view class="chart__body">
        <view class="chart__grid">
          <text class="chart__axis">{{ money(chartMax) }}</text>
          <text class="chart__axis chart__axis--mid">{{ money((chartMax + chartMin) / 2) }}</text>
          <text class="chart__axis chart__axis--bottom">{{ money(chartMin) }}</text>
        </view>

        <view class="chart__plot">
          <view v-for="(candle, index) in bars" :key="candle.date" class="chart__col">
            <view class="chart__wick" :style="{ bottom: candle.low + '%', height: candle.span + '%' }"></view>
            <view
              class="chart__candle"
              :class="{ 'chart__candle--down': candle.down }"
              :style="{ bottom: candle.bodyBottom + '%', height: candle.bodyHeight + '%' }"
            ></view>
            <text v-if="index % labelStep === 0" class="chart__tick">{{ candle.short }}</text>
          </view>
        </view>
      </view>

      <view class="chart__foot">
        <text class="g-dim">开 {{ money(candles[0].open) }} · 收 {{ money(candles[candles.length - 1].close) }}</text>
        <text class="g-dim">共 {{ totalVolume }} 笔成交</text>
      </view>
    </view>

    <view v-else-if="!loading" class="market__blank">
      <view class="market__blank-mark"><text>GOAT</text></view>
      <text class="g-title">该图鑑还没有行情</text>
      <text class="g-muted market__blank-tip">
        行情由「站内成交」与采集源（eBay / 煤炉）汇聚而成。等第一笔确认收货后，这里会自动出现价格曲线。
      </text>
      <view class="g-btn g-btn--ghost market__blank-btn" @tap="openFigure">
        <text>去看图鑑详情</text>
      </view>
    </view>

    <view class="market__table">
      <text class="g-title market__table-head">多源对比</text>
      <view class="src" v-for="row in summary?.sources || []" :key="row.source">
        <view class="src__name">
          <text class="src__label">{{ row.label }}</text>
          <text class="g-dim">{{ row.count }} 笔 · 最新 {{ row.latest_at ? dateTime(row.latest_at) : '—' }}</text>
        </view>
        <view class="src__values">
          <text class="src__price">{{ row.median_price ? `¥${money(row.median_price)}` : '—' }}</text>
          <text class="g-dim">中位价</text>
        </view>
      </view>
    </view>

    <view class="market__note">
      <text class="g-dim">
        说明：均价取成交中位数，避免 1 元钓鱼价与天价挂单拉偏；外币报价已按采集当日汇率折算为人民币。
      </text>
    </view>

    <view v-if="loading" class="g-loading"><text>加载中…</text></view>
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { getFigure, getFigureKline, getFigureQuotes } from '../../api/catalog'
import { dateTime, money } from '../../utils/format'

const SOURCE_LABELS = { internal: '站内成交', ebay: 'eBay', mercari: '煤炉', manual: '人工补录' }

const figureId = ref(0)
const figure = ref(null)
const summary = ref(null)
const series = ref(null)
const loading = ref(false)
const pickedSource = ref('internal')

const headline = computed(() => summary.value?.headline || { available: false })
// headline.available 只要任一源有点就是 true，但 median/low/high 只统计站内成交，
// 所以「能不能显示价格」要看 median 有没有值，而不是 available。
const hasMedian = computed(() => headline.value.median !== null && headline.value.median !== undefined)
const candles = computed(() => series.value?.candles || [])
const change = computed(() => (series.value?.change === null ? null : series.value?.change))

const sourceOptions = computed(() => {
  const available = series.value?.available_sources || []
  // 即使当前窗口没有该源数据也保留 chip，用户点进去能看到「这个源还没铺数据」
  return Object.keys(SOURCE_LABELS).map((value) => ({
    value,
    label: available.includes(value) ? SOURCE_LABELS[value] : `${SOURCE_LABELS[value]}·空`
  }))
})

const pickedLabel = computed(() => SOURCE_LABELS[pickedSource.value] || pickedSource.value)

/** 纵轴上下界按可见区间留 8% 边距，蜡烛不会顶到框线上 */
const bounds = computed(() => {
  if (!candles.value.length) return { min: 0, max: 1 }

  const lows = candles.value.map((item) => Number(item.low))
  const highs = candles.value.map((item) => Number(item.high))
  const min = Math.min(...lows)
  const max = Math.max(...highs)
  const pad = Math.max((max - min) * 0.08, max * 0.01, 0.5)

  return { min: Math.max(min - pad, 0), max: max + pad }
})

const chartMin = computed(() => bounds.value.min)
const chartMax = computed(() => bounds.value.max)

function toPercent(value) {
  const { min, max } = bounds.value
  if (max <= min) return 50

  return ((Number(value) - min) / (max - min)) * 100
}

const bars = computed(() =>
  candles.value.map((candle) => {
    const low = toPercent(candle.low)
    const high = toPercent(candle.high)
    const open = toPercent(candle.open)
    const close = toPercent(candle.close)
    const bodyBottom = Math.min(open, close)
    const bodyHeight = Math.max(Math.abs(close - open), 1.2)

    return {
      date: candle.date,
      short: String(candle.date).slice(5),
      low,
      span: Math.max(high - low, 0.6),
      bodyBottom,
      bodyHeight,
      down: Number(candle.close) < Number(candle.open)
    }
  })
)

const totalVolume = computed(() => candles.value.reduce((sum, item) => sum + (item.volume || 0), 0))
// 蜡烛多于 12 根时标签间隔放大，避免日期糊成一片
const labelStep = computed(() => (candles.value.length > 18 ? Math.ceil(candles.value.length / 8) : 3))

async function loadKline(source = pickedSource.value) {
  try {
    const payload = await getFigureKline(figureId.value, { days: 90, source })
    series.value = payload?.data || null
  } catch (e) {
    series.value = null
  }
}

async function load() {
  loading.value = true

  try {
    const [figurePayload, summaryPayload, kline] = await Promise.all([
      getFigure(figureId.value).catch(() => null),
      getFigureQuotes(figureId.value, { days: 30 }).catch(() => null),
      getFigureKline(figureId.value, { days: 90, source: pickedSource.value }).catch(() => null)
    ])

    figure.value = figurePayload?.data || null
    summary.value = summaryPayload?.data || null
    series.value = kline?.data || null

    // 站内没数据但采集源有：自动切到有数据的那一源，别让用户看见空图
    if (!series.value?.candles?.length) {
      const fallback = (series.value?.available_sources || []).find((item) => item !== 'internal')
      if (fallback) {
        pickedSource.value = fallback
        await loadKline(fallback)
      }
    }
  } finally {
    loading.value = false
  }
}

function pickSource(source) {
  if (pickedSource.value === source) return
  pickedSource.value = source
  loadKline(source)
}

function openFigure() {
  uni.navigateTo({ url: `/pages/figure/detail?id=${figureId.value}` })
}

onLoad((options) => {
  figureId.value = Number(options?.id || 0)
  if (!figureId.value) return
  load()
})
</script>

<style lang="scss" scoped>
.market {
  min-height: 100vh;
  padding: 24rpx 24rpx 64rpx;
  display: flex;
  flex-direction: column;
  gap: 24rpx;
}

.market__head {
  display: flex;
  flex-direction: column;
  gap: 6rpx;
}

.market__title {
  color: $goat-text;
  font-size: 36rpx;
  font-weight: 700;
}

.quote {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 20rpx;
  padding: 28rpx 24rpx;
  border-radius: $goat-radius;
  background-color: $goat-surface;
  border: 1rpx solid $goat-border;
}

.quote__main {
  display: flex;
  flex-direction: column;
  gap: 10rpx;
}

.quote__price {
  font-size: 60rpx;
}

.quote__empty-price {
  color: $goat-text-dim;
  font-size: 40rpx;
  font-weight: 700;
}

.quote__range {
  display: flex;
  gap: 20rpx;
}

.quote__change {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  color: $goat-success;
  font-size: 30rpx;
  font-weight: 700;
}

.quote__change--down {
  color: $goat-danger;
}

.market__sources {
  display: flex;
  flex-wrap: wrap;
  gap: 16rpx;
}

.chart {
  padding: 24rpx;
  border-radius: $goat-radius;
  background-color: $goat-surface;
  border: 1rpx solid $goat-border;
}

.chart__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 20rpx;
}

.chart__body {
  display: flex;
  gap: 12rpx;
  height: 380rpx;
}

.chart__grid {
  position: relative;
  width: 110rpx;
  height: 100%;
  flex-shrink: 0;
}

.chart__axis {
  position: absolute;
  top: 0;
  right: 0;
  color: $goat-text-dim;
  font-size: 18rpx;
}

.chart__axis--mid {
  top: 50%;
  transform: translateY(-50%);
}

.chart__axis--bottom {
  top: auto;
  bottom: 0;
}

.chart__plot {
  flex: 1;
  display: flex;
  align-items: flex-end;
  gap: 4rpx;
  border-left: 1rpx solid $goat-border;
  border-bottom: 1rpx solid $goat-border;
  padding-bottom: 26rpx;
  position: relative;
}

.chart__col {
  position: relative;
  flex: 1;
  height: 100%;
}

.chart__wick {
  position: absolute;
  left: 50%;
  width: 2rpx;
  margin-left: -1rpx;
  background-color: rgba(255, 255, 255, 0.28);
}

.chart__candle {
  position: absolute;
  left: 50%;
  width: 60%;
  min-width: 6rpx;
  margin-left: -30%;
  border-radius: 3rpx;
  background-color: $goat-success;
}

.chart__candle--down {
  background-color: $goat-danger;
}

.chart__tick {
  position: absolute;
  bottom: -26rpx;
  left: 50%;
  transform: translateX(-50%);
  color: $goat-text-dim;
  font-size: 16rpx;
  white-space: nowrap;
}

.chart__foot {
  display: flex;
  justify-content: space-between;
  margin-top: 20rpx;
}

.market__blank {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 14rpx;
  padding: 72rpx 32rpx;
  border-radius: $goat-radius;
  background-color: $goat-surface;
  border: 1rpx dashed $goat-border;
}

.market__blank-mark {
  width: 140rpx;
  height: 140rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  border: 1rpx solid $goat-border;
  color: rgba(255, 255, 255, 0.1);
  font-size: 24rpx;
  font-weight: 800;
  letter-spacing: 3rpx;
}

.market__blank-tip {
  text-align: center;
  line-height: 1.6;
}

.market__blank-btn {
  margin-top: 12rpx;
  width: 320rpx;
}

.market__table {
  padding: 24rpx;
  border-radius: $goat-radius;
  background-color: $goat-surface;
  border: 1rpx solid $goat-border;
}

.market__table-head {
  display: block;
  margin-bottom: 12rpx;
}

.src {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20rpx;
  padding: 18rpx 0;
  border-bottom: 1rpx solid $goat-border;
}

.src:last-child {
  border-bottom: none;
}

.src__name {
  display: flex;
  flex-direction: column;
  gap: 4rpx;
}

.src__label {
  color: $goat-text;
  font-size: 26rpx;
}

.src__values {
  display: flex;
  align-items: baseline;
  gap: 10rpx;
}

.src__price {
  color: $goat-accent;
  font-size: 30rpx;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}

.market__note {
  line-height: 1.6;
}
</style>

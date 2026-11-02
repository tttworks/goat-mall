<!--
  GOAT 潮玩交易平台 / GOAT 潮玩数据采集
  Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
  Author: Aloysius Luo
  本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
-->

<template>
  <view class="lot" v-if="auction">
    <view class="lot__head">
      <image v-if="auction.cover_url" class="lot__cover" :src="auction.cover_url" mode="aspectFill" />
      <view v-else class="lot__cover lot__cover--empty"><text>GOAT</text></view>

      <view class="lot__meta">
        <text class="lot__title">{{ auction.title || '拍品' }}</text>
        <view class="lot__tags">
          <text class="g-tag"><text>{{ auction.status_label }}</text></text>
          <text v-if="auction.figure_name" class="g-tag"><text>{{ auction.figure_name }}</text></text>
          <text v-if="auction.condition" class="g-tag"><text>{{ conditionLabel(auction.condition) }}</text></text>
        </view>
        <text class="g-dim" @tap="openListing">
          <text>商品详情 ›</text>
        </text>
      </view>
    </view>

    <view class="lot__price-card">
      <text class="g-dim">当前价</text>
      <text class="g-price lot__price">
        <text class="g-price__symbol">¥</text>{{ money(auction.current_price) }}
      </text>
      <view class="lot__price-foot">
        <text class="g-dim">起拍 ¥{{ money(auction.start_price) }} · 加价幅度 ≥ ¥{{ money(auction.min_increment) }}</text>
        <text class="g-dim">{{ auction.bid_count }} 次出价</text>
      </view>
    </view>

    <view class="lot__clock">
      <text class="g-muted">{{ auction.status === 'active' ? '剩余时间' : '本场时间' }}</text>
      <text class="lot__clock-value">{{ clock }}</text>
      <text class="g-dim">结束于 {{ dateTime(auction.end_at) }}</text>
    </view>

    <view v-if="auction.status === 'active'" class="lot__bid">
      <view class="lot__bid-row">
        <text class="g-muted">我的出价</text>
        <input
          v-model="amount"
          class="g-input lot__bid-input"
          type="digit"
          :placeholder="`不低于 ${money(auction.next_min_bid)}`"
          placeholder-class="g-placeholder"
        />
      </view>

      <view class="lot__quick">
        <view v-for="step in quickSteps" :key="step" class="g-chip" @tap="fill(step)">
          <text>¥{{ money(step) }}</text>
        </view>
      </view>

      <view class="g-btn g-btn--primary" :class="{ 'g-btn--disabled': bidding }" @tap="submitBid">
        <text>{{ bidding ? '出价中…' : '出价' }}</text>
      </view>
      <text class="lot__tip">
        结束前 {{ auction.anti_snipe_seconds }} 秒内出价将自动延时，最多顺延 {{ auction.max_extensions }} 次
      </text>
    </view>

    <view v-else class="lot__closed">
      <text class="g-muted">{{ auction.status_label }}，无法继续出价</text>
    </view>

    <view class="lot__bids">
      <view class="g-row--between lot__bids-head">
        <text class="g-title">出价记录</text>
        <text class="g-dim">最近 20 口</text>
      </view>

      <view v-if="auction.bids && auction.bids.length" class="lot__bids-list">
        <view v-for="(bid, index) in auction.bids" :key="bid.bid_id" class="bid">
          <text class="bid__rank">{{ index + 1 }}</text>
          <view class="bid__body">
            <text class="bid__amount">¥{{ money(bid.amount) }}</text>
            <text class="g-dim">{{ dateTime(bid.placed_at) }}</text>
          </view>
          <text class="g-tag" :class="{ 'g-tag--accent': bid.status === 'leading' }">
            <text>{{ BID_STATUS[bid.status] || bid.status }}</text>
          </text>
        </view>
      </view>
      <view v-else class="g-empty"><text>还没有人出价，起拍价等你揭幕</text></view>
    </view>
  </view>

  <view v-else-if="loading" class="g-loading"><text>加载中…</text></view>

  <view v-else class="g-empty">
    <text>拍品不存在或已下架</text>
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onLoad, onUnload } from '@dcloudio/uni-app'
import { getAuction, placeBid } from '../../api/catalog'
import { conditionLabel, countdown, dateTime, money } from '../../utils/format'
import { toast } from '../../utils/ui'

const BID_STATUS = { leading: '领先', outbid: '被超', won: '成交', lost: '未成交', cancelled: '已撤销' }

const auction = ref(null)
const auctionId = ref(0)
const amount = ref('')
const loading = ref(false)
const bidding = ref(false)
const clock = ref('—')
let timer = null

/** 快捷加价：最小幅度、2 倍、5 倍，够覆盖大多数「我就想压一手」的场景 */
const quickSteps = computed(() => {
  const base = Number(auction.value?.min_increment || 1)
  const current = Number(auction.value?.current_price || 0)

  return [current + base, current + base * 2, current + base * 5].map((value) => value.toFixed(2))
})

async function load() {
  loading.value = true

  try {
    const payload = await getAuction(auctionId.value)
    auction.value = payload?.data || null
    tick()
  } catch (e) {
    auction.value = null
  } finally {
    loading.value = false
  }
}

function tick() {
  if (!auction.value) return
  clock.value = countdown(auction.value.end_at)

  // 倒计时归零后拉一次详情：服务端可能刚顺延或已结束，前端不自己猜
  if (clock.value === '已结束' && auction.value.status === 'active') {
    setTimeout(load, 1200)
  }
}

function fill(value) {
  amount.value = String(value)
}

async function submitBid() {
  if (bidding.value) return

  const value = String(amount.value || '').trim()
  if (!/^\d+(\.\d{1,2})?$/.test(value)) {
    toast('请输入合法金额（最多两位小数）')
    return
  }

  bidding.value = true

  try {
    const payload = await placeBid(auction.value.auction_id, value)
    // 出价接口回了最新整场快照，直接覆盖，省一次往返
    if (payload?.auction) {
      auction.value = { ...auction.value, ...payload.auction, bids: auction.value.bids }
    }
    await load()
    toast(payload?.extended ? '出价成功，本场已自动延时' : '出价成功')
    amount.value = ''
  } catch (e) {
    /* request 层已提示（价格不足/已结束/卖家自拍等） */
  } finally {
    bidding.value = false
  }
}

function openListing() {
  if (!auction.value?.listing_id) return
  uni.navigateTo({ url: `/pages/listing/detail?id=${auction.value.listing_id}` })
}

onLoad((options) => {
  auctionId.value = Number(options?.id || 0)
  if (!auctionId.value) {
    toast('缺少拍品编号')
    return
  }
  load()
  timer = setInterval(tick, 1000)
})

onUnload(() => {
  if (timer) clearInterval(timer)
  timer = null
})
</script>

<style lang="scss" scoped>
.lot {
  min-height: 100vh;
  padding: 24rpx 24rpx 64rpx;
  display: flex;
  flex-direction: column;
  gap: 24rpx;
}

.lot__head {
  display: flex;
  gap: 20rpx;
}

.lot__cover {
  width: 220rpx;
  height: 220rpx;
  border-radius: $goat-radius;
  background-color: $goat-surface-2;
  flex-shrink: 0;
}

.lot__cover--empty {
  display: flex;
  align-items: center;
  justify-content: center;
  color: rgba(255, 255, 255, 0.12);
  font-size: 26rpx;
  letter-spacing: 4rpx;
}

.lot__meta {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 14rpx;
}

.lot__title {
  color: $goat-text;
  font-size: 30rpx;
  font-weight: 600;
  line-height: 1.4;
}

.lot__tags {
  display: flex;
  flex-wrap: wrap;
  gap: 12rpx;
}

.lot__price-card {
  padding: 24rpx;
  border-radius: $goat-radius;
  background-color: $goat-surface;
  border: 1rpx solid $goat-border;
}

.lot__price {
  display: block;
  margin: 8rpx 0 12rpx;
  font-size: 64rpx;
}

.lot__price-foot {
  display: flex;
  justify-content: space-between;
  gap: 16rpx;
}

.lot__clock {
  display: flex;
  align-items: baseline;
  gap: 16rpx;
  padding: 0 4rpx;
}

.lot__clock-value {
  color: $goat-accent;
  font-size: 34rpx;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}

.lot__bid {
  padding: 24rpx;
  border-radius: $goat-radius;
  background-color: $goat-surface;
  border: 1rpx solid $goat-border;
  display: flex;
  flex-direction: column;
  gap: 20rpx;
}

.lot__bid-row {
  display: flex;
  align-items: center;
  gap: 20rpx;
}

.lot__bid-input {
  flex: 1;
  text-align: right;
}

.lot__quick {
  display: flex;
  gap: 16rpx;
  flex-wrap: wrap;
}

.lot__tip {
  color: $goat-text-dim;
  font-size: 22rpx;
  line-height: 1.5;
}

.lot__closed {
  padding: 28rpx;
  border-radius: $goat-radius;
  background-color: $goat-surface;
  border: 1rpx solid $goat-border;
  text-align: center;
}

.lot__bids {
  padding: 24rpx;
  border-radius: $goat-radius;
  background-color: $goat-surface;
  border: 1rpx solid $goat-border;
}

.lot__bids-head {
  margin-bottom: 16rpx;
}

.lot__bids-list {
  display: flex;
  flex-direction: column;
}

.bid {
  display: flex;
  align-items: center;
  gap: 20rpx;
  padding: 18rpx 0;
  border-bottom: 1rpx solid $goat-border;
}

.bid:last-child {
  border-bottom: none;
}

.bid__rank {
  width: 40rpx;
  color: $goat-text-dim;
  font-size: 22rpx;
}

.bid__body {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 4rpx;
}

.bid__amount {
  color: $goat-text;
  font-size: 28rpx;
  font-weight: 600;
}
</style>

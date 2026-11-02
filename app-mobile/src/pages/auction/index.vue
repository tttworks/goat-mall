<!--
  GOAT 潮玩交易平台 / GOAT 潮玩数据采集
  Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
  Author: Aloysius Luo
  本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
-->

<template>
  <view class="auction">
    <view class="auction__head">
      <text class="g-title">竞拍专场</text>
      <text class="g-dim">结束前 5 分钟出价会自动延时</text>
    </view>

    <view class="auction__tabs">
      <view
        v-for="item in SORTS"
        :key="item.value"
        class="g-chip"
        :class="{ 'g-chip--active': sort === item.value }"
        @tap="changeSort(item.value)"
      >
        <text>{{ item.label }}</text>
      </view>
    </view>

    <view v-if="items.length" class="auction__list">
      <view v-for="item in items" :key="item.auction_id" class="lot" @tap="open(item.auction_id)">
        <image v-if="item.cover_url" class="lot__cover" :src="item.cover_url" mode="aspectFill" />
        <view v-else class="lot__cover lot__cover--empty">
          <text>GOAT</text>
        </view>

        <view class="lot__body">
          <text class="lot__title g-clamp-2">{{ item.title || '拍品信息加载中' }}</text>
          <view class="lot__tags">
            <text v-if="item.figure_name" class="g-tag"><text>{{ item.figure_name }}</text></text>
            <text class="g-tag g-tag--accent"><text>{{ item.bid_count }} 次出价</text></text>
          </view>
          <view class="g-row--between lot__foot">
            <view class="lot__prices">
              <text class="g-dim">当前价</text>
              <text class="g-price lot__price">
                <text class="g-price__symbol">¥</text>{{ money(item.current_price) }}
              </text>
            </view>
            <view class="lot__time">
              <text class="lot__countdown">{{ countdown(item.end_at) }}</text>
              <text class="g-dim">起拍 ¥{{ money(item.start_price) }}</text>
            </view>
          </view>
        </view>
      </view>
    </view>

    <view v-else-if="!loading" class="g-empty">
      <text>当前没有进行中的拍品</text>
      <text class="auction__empty-tip">专场由运营在上架审核后编排，稍后再来看看</text>
    </view>

    <view v-if="loading" class="g-loading"><text>加载中…</text></view>
  </view>
</template>

<script setup>
import { ref } from 'vue'
import { onPullDownRefresh, onShow } from '@dcloudio/uni-app'
import { listAuctions } from '../../api/catalog'
import { countdown, money } from '../../utils/format'

const SORTS = [
  { value: 'ending_soon', label: '即将结束' },
  { value: 'latest', label: '最新上拍' },
  { value: 'price_desc', label: '价格从高到低' }
]

const items = ref([])
const sort = ref('ending_soon')
const loading = ref(false)

async function load() {
  loading.value = true

  try {
    const payload = await listAuctions({ sort: sort.value, per_page: 20 })
    items.value = payload?.data || []
  } catch (e) {
    items.value = []
  } finally {
    loading.value = false
    uni.stopPullDownRefresh()
  }
}

function changeSort(value) {
  if (sort.value === value) return
  sort.value = value
  load()
}

function open(id) {
  uni.navigateTo({ url: `/pages/auction/detail?id=${id}` })
}

onShow(() => {
  load()
})

onPullDownRefresh(() => {
  load()
})
</script>

<style lang="scss" scoped>
.auction {
  min-height: 100vh;
  padding: 24rpx 24rpx 48rpx;
}

.auction__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  margin-bottom: 20rpx;
}

.auction__tabs {
  display: flex;
  gap: 16rpx;
  margin-bottom: 24rpx;
}

.auction__list {
  display: flex;
  flex-direction: column;
  gap: 20rpx;
}

.lot {
  display: flex;
  gap: 20rpx;
  padding: 20rpx;
  border-radius: $goat-radius;
  background-color: $goat-surface;
  border: 1rpx solid $goat-border;
}

.lot__cover {
  width: 200rpx;
  height: 200rpx;
  border-radius: $goat-radius-sm;
  background-color: $goat-surface-2;
  flex-shrink: 0;
}

.lot__cover--empty {
  display: flex;
  align-items: center;
  justify-content: center;
  color: rgba(255, 255, 255, 0.12);
  font-size: 24rpx;
  letter-spacing: 3rpx;
}

.lot__body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 12rpx;
}

.lot__title {
  color: $goat-text;
  font-size: 27rpx;
  line-height: 1.45;
}

.lot__tags {
  display: flex;
  flex-wrap: wrap;
  gap: 12rpx;
}

.lot__foot {
  margin-top: auto;
}

.lot__prices {
  display: flex;
  align-items: baseline;
  gap: 10rpx;
}

.lot__price {
  font-size: 34rpx;
}

.lot__time {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 4rpx;
}

.lot__countdown {
  color: $goat-accent;
  font-size: 24rpx;
  font-variant-numeric: tabular-nums;
}

.auction__empty-tip {
  display: block;
  margin-top: 12rpx;
  font-size: 22rpx;
  color: $goat-text-dim;
}
</style>

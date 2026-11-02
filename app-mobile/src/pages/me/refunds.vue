<!--
  GOAT 潮玩交易平台 / GOAT 潮玩数据采集
  Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
  Author: Aloysius Luo
  本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
-->

<template>
  <view class="refunds">
    <view class="refunds__tabs">
      <view
        v-for="item in TABS"
        :key="item.value"
        class="g-chip"
        :class="{ 'g-chip--active': status === item.value }"
        @tap="changeStatus(item.value)"
      >
        <text>{{ item.label }}</text>
      </view>
    </view>

    <view v-if="items.length" class="refunds__list">
      <view v-for="item in items" :key="item.refund_id" class="refund" @tap="openOrder(item.order_id)">
        <view class="refund__head">
          <text class="refund__no">退款单 {{ item.refund_no }}</text>
          <text class="g-tag" :class="tagClass(item.status)"><text>{{ item.status_label }}</text></text>
        </view>

        <view class="refund__row">
          <text class="g-muted">订单号</text>
          <text class="refund__value">{{ item.order_no || `#${item.order_id}` }}</text>
        </view>
        <view class="refund__row">
          <text class="g-muted">退款金额</text>
          <text class="g-price refund__amount"><text class="g-price__symbol">¥</text>{{ money(item.amount) }}</text>
        </view>
        <view class="refund__row">
          <text class="g-muted">申请原因</text>
          <text class="refund__value refund__value--reason">{{ item.reason }}</text>
        </view>
        <view v-if="item.remark" class="refund__row">
          <text class="g-muted">处理说明</text>
          <text class="refund__value">{{ item.remark }}</text>
        </view>

        <view class="refund__foot">
          <text class="g-dim">申请于 {{ dateTime(item.created_at) }}</text>
          <text v-if="item.refunded_at" class="g-dim">退款于 {{ dateTime(item.refunded_at) }}</text>
          <text v-else-if="item.reviewed_at" class="g-dim">处理于 {{ dateTime(item.reviewed_at) }}</text>
        </view>
      </view>
    </view>

    <view v-else-if="!loading" class="refunds__empty">
      <view class="refunds__empty-mark"><text>¥</text></view>
      <text class="g-title">没有退款记录</text>
      <text class="g-muted refunds__empty-tip">
        订单在「待发货 / 待收货」阶段可申请退款；确认收货后请通过客服走售后。
      </text>
      <view class="g-btn g-btn--ghost refunds__empty-btn" @tap="goOrders">
        <text>查看我的订单</text>
      </view>
    </view>

    <view v-if="loading" class="g-loading"><text>加载中…</text></view>
  </view>
</template>

<script setup>
import { ref } from 'vue'
import { onPullDownRefresh, onShow } from '@dcloudio/uni-app'
import { listRefunds } from '../../api/trade'
import { dateTime, money } from '../../utils/format'

const TABS = [
  { value: '', label: '全部' },
  { value: 'pending', label: '待审核' },
  { value: 'refunded', label: '已退款' },
  { value: 'rejected', label: '已驳回' }
]

const items = ref([])
const status = ref('')
const loading = ref(false)

function tagClass(value) {
  if (value === 'refunded') return 'g-tag--accent'
  if (value === 'rejected' || value === 'failed') return 'g-tag--danger'
  return ''
}

async function load() {
  loading.value = true

  try {
    const payload = await listRefunds({ status: status.value || undefined, per_page: 20 })
    items.value = payload?.data || []
  } catch (e) {
    items.value = []
  } finally {
    loading.value = false
    uni.stopPullDownRefresh()
  }
}

function changeStatus(value) {
  if (status.value === value) return
  status.value = value
  load()
}

function openOrder(orderId) {
  if (!orderId) return
  uni.navigateTo({ url: '/pages/me/orders' })
}

function goOrders() {
  uni.navigateTo({ url: '/pages/me/orders' })
}

onShow(() => {
  load()
})

onPullDownRefresh(() => {
  load()
})
</script>

<style lang="scss" scoped>
.refunds {
  min-height: 100vh;
  padding: 24rpx 24rpx 64rpx;
}

.refunds__tabs {
  display: flex;
  gap: 16rpx;
  margin-bottom: 24rpx;
}

.refunds__list {
  display: flex;
  flex-direction: column;
  gap: 20rpx;
}

.refund {
  padding: 24rpx;
  border-radius: $goat-radius;
  background-color: $goat-surface;
  border: 1rpx solid $goat-border;
  display: flex;
  flex-direction: column;
  gap: 12rpx;
}

.refund__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16rpx;
  padding-bottom: 8rpx;
  border-bottom: 1rpx solid $goat-border;
}

.refund__no {
  color: $goat-text;
  font-size: 26rpx;
  font-weight: 600;
}

.refund__row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 24rpx;
}

.refund__value {
  flex: 1;
  text-align: right;
  color: $goat-text;
  font-size: 24rpx;
  line-height: 1.5;
}

.refund__value--reason {
  color: $goat-text-muted;
}

.refund__amount {
  font-size: 30rpx;
}

.refund__foot {
  display: flex;
  justify-content: space-between;
  gap: 16rpx;
  margin-top: 4rpx;
}

.refunds__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 14rpx;
  padding: 140rpx 0 0;
}

.refunds__empty-mark {
  width: 160rpx;
  height: 160rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  border: 1rpx solid $goat-border;
  color: rgba(255, 255, 255, 0.12);
  font-size: 56rpx;
  font-weight: 700;
}

.refunds__empty-tip {
  max-width: 520rpx;
  text-align: center;
  line-height: 1.6;
}

.refunds__empty-btn {
  margin-top: 16rpx;
  width: 320rpx;
}
</style>

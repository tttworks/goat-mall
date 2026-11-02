<!--
  GOAT 潮玩交易平台 / GOAT 潮玩数据采集
  Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
  Author: Aloysius Luo
  本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
-->

<template>
  <view class="reviews">
    <view class="score">
      <view class="score__main">
        <text class="score__value">{{ summary.count ? summary.average.toFixed(1) : '—' }}</text>
        <text class="g-dim">{{ summary.count }} 条评价</text>
      </view>

      <view class="score__bars">
        <view v-for="star in [5, 4, 3, 2, 1]" :key="star" class="bar">
          <text class="bar__label">{{ star }} 星</text>
          <view class="bar__track">
            <view class="bar__fill" :style="{ width: barPercent(star) }"></view>
          </view>
          <text class="bar__count">{{ summary.distribution?.[star] || 0 }}</text>
        </view>
      </view>
    </view>

    <view v-if="sellerName" class="reviews__shop">
      <text class="reviews__shop-name">{{ sellerName }}</text>
      <text class="g-dim">全部评价按时间倒序</text>
    </view>

    <view v-if="items.length" class="reviews__list">
      <view v-for="item in items" :key="item.review_id" class="review">
        <view class="review__head">
          <text class="review__buyer">匿名买家</text>
          <text class="review__stars">{{ stars(item.rating) }}</text>
          <text class="g-dim review__time">{{ dateTime(item.created_at) }}</text>
        </view>

        <text v-if="item.content" class="review__content">{{ item.content }}</text>

        <view v-if="item.images && item.images.length" class="review__images">
          <image
            v-for="(url, index) in item.images"
            :key="url + index"
            class="review__image"
            :src="url"
            mode="aspectFill"
            @tap="previewImages(item.images, index)"
          />
        </view>

        <view v-if="item.seller_reply" class="review__reply">
          <text class="review__reply-label">卖家回复</text>
          <text class="review__reply-text">{{ item.seller_reply }}</text>
        </view>

        <view v-else-if="isOwner" class="review__reply-btn" @tap="openReply(item)">
          <text>回复买家</text>
        </view>
      </view>
    </view>

    <view v-else-if="!loading" class="reviews__empty">
      <view class="reviews__empty-mark"><text>★</text></view>
      <text class="g-title">还没有评价</text>
      <text class="g-muted">成交并完成收货后，买家的评价会出现在这里</text>
    </view>

    <view v-if="loading" class="g-loading"><text>加载中…</text></view>
    <view v-else-if="items.length < total" class="reviews__more" @tap="loadMore">
      <text>加载更多（{{ items.length }}/{{ total }}）</text>
    </view>
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { listSellerReviews } from '../../api/catalog'
import { replyReview } from '../../api/social'
import { session } from '../../store/session'
import { dateTime } from '../../utils/format'
import { toast } from '../../utils/ui'

const sellerId = ref(0)
const items = ref([])
const summary = ref({ average: 0, count: 0, distribution: {} })
const total = ref(0)
const page = ref(1)
const loading = ref(false)

const isOwner = computed(() => Number(session.user?.user_id) === sellerId.value)
const sellerName = computed(() => (isOwner.value ? '我的店铺评价' : '店铺评价'))

function stars(rating) {
  const filled = Math.max(0, Math.min(5, Number(rating) || 0))
  return '★★★★★'.slice(0, filled) + '☆☆☆☆☆'.slice(0, 5 - filled)
}

function barPercent(star) {
  const count = Number(summary.value.distribution?.[star] || 0)
  if (!summary.value.count) return '0%'

  return `${Math.round((count / summary.value.count) * 100)}%`
}

async function load({ reset = false } = {}) {
  loading.value = true

  try {
    const payload = await listSellerReviews(sellerId.value, { per_page: 20, page: reset ? 1 : page.value })
    const rows = payload?.data || []

    items.value = reset ? rows : [...items.value, ...rows]
    summary.value = payload?.summary || summary.value
    total.value = payload?.meta?.total ?? items.value.length
  } catch (e) {
    if (reset) items.value = []
  } finally {
    loading.value = false
  }
}

function loadMore() {
  page.value += 1
  load()
}

function previewImages(urls, index) {
  uni.previewImage({ urls, current: urls[index] })
}

function openReply(item) {
  uni.showModal({
    title: '回复买家',
    editable: true,
    placeholderText: '回复后不可修改，请说明售后处理方式',
    success: async (res) => {
      if (!res.confirm) return

      const content = String(res.content || '').trim()
      if (content.length < 2) {
        toast('回复至少 2 个字')
        return
      }

      try {
        const payload = await replyReview(item.review_id, content)
        const updated = payload?.data
        items.value = items.value.map((row) =>
          row.review_id === item.review_id ? { ...row, ...(updated || { seller_reply: content }) } : row
        )
        toast('回复成功')
      } catch (e) {
        /* request 层已提示（重复回复等） */
      }
    }
  })
}

onLoad((options) => {
  sellerId.value = Number(options?.id || 0)
  if (!sellerId.value) return
  load({ reset: true })
})
</script>

<style lang="scss" scoped>
.reviews {
  min-height: 100vh;
  padding: 24rpx 24rpx 64rpx;
  display: flex;
  flex-direction: column;
  gap: 24rpx;
}

.score {
  display: flex;
  gap: 32rpx;
  padding: 28rpx 24rpx;
  border-radius: $goat-radius;
  background-color: $goat-surface;
  border: 1rpx solid $goat-border;
}

.score__main {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6rpx;
  width: 180rpx;
  flex-shrink: 0;
}

.score__value {
  color: $goat-accent;
  font-size: 64rpx;
  font-weight: 800;
}

.score__bars {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 8rpx;
}

.bar {
  display: flex;
  align-items: center;
  gap: 12rpx;
}

.bar__label {
  width: 64rpx;
  color: $goat-text-dim;
  font-size: 20rpx;
}

.bar__track {
  flex: 1;
  height: 10rpx;
  border-radius: 999rpx;
  background-color: rgba(255, 255, 255, 0.08);
  overflow: hidden;
}

.bar__fill {
  height: 100%;
  border-radius: 999rpx;
  background-color: $goat-accent;
}

.bar__count {
  width: 48rpx;
  text-align: right;
  color: $goat-text-dim;
  font-size: 20rpx;
}

.reviews__shop {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.reviews__shop-name {
  color: $goat-text;
  font-size: 28rpx;
  font-weight: 600;
}

.reviews__list {
  display: flex;
  flex-direction: column;
  gap: 20rpx;
}

.review {
  padding: 24rpx;
  border-radius: $goat-radius;
  background-color: $goat-surface;
  border: 1rpx solid $goat-border;
  display: flex;
  flex-direction: column;
  gap: 14rpx;
}

.review__head {
  display: flex;
  align-items: center;
  gap: 16rpx;
}

.review__buyer {
  color: $goat-text;
  font-size: 26rpx;
}

.review__stars {
  color: $goat-accent;
  font-size: 22rpx;
  letter-spacing: 2rpx;
}

.review__time {
  margin-left: auto;
}

.review__content {
  color: $goat-text-muted;
  font-size: 26rpx;
  line-height: 1.6;
}

.review__images {
  display: flex;
  flex-wrap: wrap;
  gap: 12rpx;
}

.review__image {
  width: 160rpx;
  height: 160rpx;
  border-radius: $goat-radius-sm;
  background-color: $goat-surface-2;
}

.review__reply {
  padding: 16rpx 20rpx;
  border-radius: $goat-radius-sm;
  background-color: $goat-surface-2;
  display: flex;
  flex-direction: column;
  gap: 6rpx;
}

.review__reply-label {
  color: $goat-accent;
  font-size: 20rpx;
}

.review__reply-text {
  color: $goat-text-muted;
  font-size: 24rpx;
  line-height: 1.55;
}

.review__reply-btn {
  align-self: flex-start;
  height: 56rpx;
  padding: 0 24rpx;
  display: flex;
  align-items: center;
  border-radius: 999rpx;
  border: 1rpx solid $goat-border;
  color: $goat-text-muted;
  font-size: 22rpx;
}

.reviews__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 14rpx;
  padding: 120rpx 0 0;
}

.reviews__empty-mark {
  width: 140rpx;
  height: 140rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  border: 1rpx solid $goat-border;
  color: rgba(255, 255, 255, 0.12);
  font-size: 44rpx;
}

.reviews__more {
  padding: 28rpx 0;
  text-align: center;
  color: $goat-text-dim;
  font-size: 24rpx;
}
</style>

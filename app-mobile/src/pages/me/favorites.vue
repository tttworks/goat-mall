<!--
  GOAT 潮玩交易平台 / GOAT 潮玩数据采集
  Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
  Author: Aloysius Luo
  本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
-->

<template>
  <view class="favs">
    <scroll-view class="favs__chips" scroll-x :show-scrollbar="false">
      <view class="favs__chips-inner">
        <view
          v-for="item in TABS"
          :key="item.value"
          class="g-chip"
          :class="{ 'g-chip--active': tab === item.value }"
          @tap="pickTab(item.value)"
        >
          <text>{{ item.label }}</text>
        </view>
      </view>
    </scroll-view>

    <view v-if="rows.length" class="favs__list">
      <view v-for="item in rows" :key="item.favorite_id" class="g-card fav">
        <view class="fav__main" @tap="openTarget(item)">
          <view class="fav__cover">
            <image
              v-if="item.target && item.target.cover_url"
              class="fav__image"
              :src="item.target.cover_url"
              mode="aspectFill"
            />
            <view v-else class="fav__placeholder"><text>GOAT</text></view>
          </view>

          <view class="g-flex-1">
            <text class="fav__title g-clamp-2">{{ titleOf(item) }}</text>
            <view class="fav__meta">
              <text class="g-tag" :class="{ 'g-tag--accent': toneOf(item) === 'accent' }">
                {{ statusOf(item) }}
              </text>
              <text class="g-dim">{{ relativeTime(item.created_at) }}</text>
            </view>
            <text v-if="item.target_type === 'listing' && item.target" class="g-price fav__price">
              <text class="g-price__symbol">¥</text>{{ money(item.target.price) }}
            </text>
            <text v-else-if="item.target" class="g-dim">图鑑条目</text>
          </view>
        </view>

        <view class="fav__actions">
          <view class="g-btn g-btn--sm g-btn--ghost" @tap="remove(item)"><text>取消收藏</text></view>
        </view>
      </view>
    </view>

    <view v-else-if="!loading" class="g-empty"><text>还没有收藏，去图鑑逛逛</text></view>
    <view v-if="loading" class="g-loading"><text>加载中…</text></view>
  </view>
</template>

<script setup>
import { ref } from 'vue'
import { onPullDownRefresh, onReachBottom } from '@dcloudio/uni-app'
import { listFavorites, removeFavorite } from '../../api/social'
import { isLoggedIn } from '../../store/session'
import { money, relativeTime } from '../../utils/format'
import { toast } from '../../utils/ui'

const TABS = [
  { value: '', label: '全部' },
  { value: 'listing', label: '在售商品' },
  { value: 'figure', label: '图鑑条目' }
]

/** 状态文案：收藏卡片要能一眼看出「还能买吗」 */
const LISTING_STATUS = {
  active: { label: '在售', tone: 'accent' },
  reserved: { label: '已锁定', tone: 'normal' },
  sold: { label: '已售出', tone: 'dim' },
  delisted: { label: '已下架', tone: 'dim' },
  draft: { label: '草稿', tone: 'dim' },
  pending_review: { label: '待审', tone: 'dim' },
  rejected: { label: '已驳回', tone: 'dim' }
}

const FIGURE_STATUS = {
  published: { label: '已发布', tone: 'accent' },
  draft: { label: '草稿', tone: 'dim' },
  archived: { label: '已归档', tone: 'dim' }
}

const rows = ref([])
const tab = ref('')
const page = ref(1)
const hasMore = ref(true)
const loading = ref(false)

function metaOf(item) {
  const target = item.target
  if (!target) return { label: '已失效', tone: 'dim' }

  const map = item.target_type === 'listing' ? LISTING_STATUS : FIGURE_STATUS
  return map[target.status] || { label: target.status || '未知', tone: 'dim' }
}

function statusOf(item) {
  return metaOf(item).label
}

function toneOf(item) {
  return metaOf(item).tone
}

function titleOf(item) {
  if (!item.target) return '内容已失效'
  return item.target_type === 'listing' ? item.target.title : item.target.name
}

async function load(reset = false) {
  if (!isLoggedIn()) {
    uni.navigateTo({ url: '/pages/login/index' })
    return
  }

  if (loading.value) return
  if (!reset && !hasMore.value) return

  loading.value = true
  try {
    const params = { page: reset ? 1 : page.value, per_page: 20 }
    if (tab.value) params.target_type = tab.value

    const payload = await listFavorites(params)
    const list = payload.data || []

    rows.value = reset ? list : rows.value.concat(list)

    const total = payload.meta?.total ?? 0
    hasMore.value = rows.value.length < total
    page.value = reset ? 2 : page.value + 1
  } catch (e) {
    toast('收藏加载失败')
  } finally {
    loading.value = false
  }
}

function reload() {
  hasMore.value = true
  page.value = 1
  load(true)
}

function pickTab(value) {
  if (tab.value === value) return
  tab.value = value
  reload()
}

function openTarget(item) {
  if (!item.target) {
    toast('该内容已失效')
    return
  }

  const url =
    item.target_type === 'listing'
      ? `/pages/listing/detail?id=${item.target_id}`
      : `/pages/figure/detail?id=${item.target_id}`

  uni.navigateTo({ url })
}

async function remove(item) {
  try {
    await removeFavorite(item.target_type, item.target_id)
    rows.value = rows.value.filter((row) => row.favorite_id !== item.favorite_id)
    toast('已取消收藏')
  } catch (e) {
    // 请求层已提示
  }
}

onPullDownRefresh(async () => {
  await load(true)
  uni.stopPullDownRefresh()
})

onReachBottom(() => load(false))

reload()
</script>

<style lang="scss" scoped>
.favs {
  min-height: 100vh;
  padding: 24rpx;
}

.favs__chips {
  white-space: nowrap;
  margin-bottom: 20rpx;
}

.favs__chips-inner {
  display: flex;
  gap: 16rpx;
  padding-right: 24rpx;
}

.favs__list {
  display: flex;
  flex-direction: column;
  gap: 16rpx;
}

.fav {
  display: flex;
  align-items: center;
  gap: 20rpx;
}

.fav__main {
  display: flex;
  align-items: center;
  gap: 20rpx;
  flex: 1;
  min-width: 0;
}

.fav__cover {
  width: 160rpx;
  height: 160rpx;
  border-radius: $goat-radius-sm;
  background-color: $goat-surface-2;
  overflow: hidden;
  flex-shrink: 0;
}

.fav__image {
  width: 100%;
  height: 100%;
}

.fav__placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  color: rgba(255, 255, 255, 0.12);
  font-size: 24rpx;
  font-weight: 800;
  letter-spacing: 2rpx;
}

.fav__title {
  color: $goat-text;
  font-size: 28rpx;
  font-weight: 600;
  line-height: 1.4;
}

.fav__meta {
  display: flex;
  align-items: center;
  gap: 12rpx;
  margin-top: 10rpx;
}

.fav__price {
  display: block;
  margin-top: 10rpx;
  font-size: 30rpx;
}

.fav__actions {
  flex-shrink: 0;
}
</style>

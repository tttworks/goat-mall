<!--
  GOAT 潮玩交易平台 / GOAT 潮玩数据采集
  Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
  Author: Aloysius Luo
  本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
-->

<template>
  <view class="feed">
    <view class="feed__head">
      <!-- 搜索统一走独立搜索页：首页内联搜索只能搜图鑑，搜不到在售商品 -->
      <view class="feed__search" @tap="goSearch">
        <text class="feed__search-icon">⌕</text>
        <text class="feed__search-placeholder">搜图鑑 / 商品 / 系列</text>
        <text class="feed__search-go">搜索</text>
      </view>

      <view class="shortcuts">
        <view class="shortcut" @tap="goAuctions">
          <text class="shortcut__icon">⏱</text>
          <text class="shortcut__label">竞拍</text>
        </view>
        <view class="shortcut" @tap="goCart">
          <text class="shortcut__icon">▤</text>
          <text class="shortcut__label">购物车</text>
        </view>
        <view class="shortcut" @tap="goSeller">
          <text class="shortcut__icon">⚑</text>
          <text class="shortcut__label">卖家中心</text>
        </view>
        <view class="shortcut" @tap="goMine">
          <text class="shortcut__icon">◉</text>
          <text class="shortcut__label">我的</text>
        </view>
      </view>
    </view>

    <scroll-view class="feed__chips" scroll-x :show-scrollbar="false">
      <view class="feed__chips-inner">
        <view
          class="g-chip"
          :class="{ 'g-chip--active': !category }"
          @tap="pickCategory('')"
        >
          <text>全部</text>
        </view>
        <view
          v-for="item in categories"
          :key="item.value"
          class="g-chip"
          :class="{ 'g-chip--active': category === item.value }"
          @tap="pickCategory(item.value)"
        >
          <text>{{ item.label }}</text>
        </view>
      </view>
    </scroll-view>

    <view v-if="figures.length" class="feed__grid">
      <view
        v-for="figure in figures"
        :key="figure.figure_id"
        class="card"
        @tap="openFigure(figure)"
      >
        <view class="card__cover">
          <image
            v-if="figure.cover_url"
            class="card__image"
            :src="figure.cover_url"
            mode="aspectFill"
          />
          <view v-else class="card__placeholder">
            <text>GOAT</text>
          </view>
          <view v-if="figure.listings_count" class="card__badge">
            <text>在售 {{ figure.listings_count }}</text>
          </view>
        </view>
        <view class="card__body">
          <text class="card__name g-clamp-2">{{ figure.name }}</text>
          <text class="card__maker g-ellipsis">{{ figure.maker || '厂牌未收录' }}</text>
          <view class="card__footer">
            <text v-if="figure.price_low" class="g-price card__price">
              <text class="g-price__symbol">¥</text>{{ money(figure.price_low) }}
            </text>
            <text v-else class="g-dim">暂无报价</text>
            <text class="g-dim">{{ figure.year || '' }}</text>
          </view>
        </view>
      </view>
    </view>

    <view v-else-if="!loading" class="g-empty">
      <text>{{ category ? '该品类下暂时没有图鑑，换个品类看看' : '图鑑库正在入库，稍后再来看看' }}</text>
    </view>

    <view v-if="loading" class="g-loading">
      <text>加载中…</text>
    </view>
    <view v-else-if="figures.length && !hasMore" class="g-loading">
      <text>已经到底了</text>
    </view>
  </view>
</template>

<script setup>
import { ref } from 'vue'
import { onLoad, onPullDownRefresh, onReachBottom, onUnload } from '@dcloudio/uni-app'
import { listFigures } from '../../api/catalog'
import { isLoggedIn } from '../../store/session'
import { money } from '../../utils/format'

const figures = ref([])
const categories = ref([])
const category = ref('')
const page = ref(1)
const hasMore = ref(true)
const loading = ref(false)

/** 品类选项由后端随列表返回，前端不维护一份中文映射 */
function normalizeCategories(options) {
  return (options || []).map((item) =>
    typeof item === 'string' ? { value: item, label: item } : { value: item.value, label: item.label }
  )
}

async function load(reset = false) {
  if (loading.value) return
  if (!reset && !hasMore.value) return

  loading.value = true
  try {
    const params = { page: reset ? 1 : page.value, per_page: 20 }
    if (category.value) params.category = category.value

    const payload = await listFigures(params)
    const rows = payload.data || []

    figures.value = reset ? rows : figures.value.concat(rows)
    categories.value = normalizeCategories(payload.meta?.categories)

    const total = payload.meta?.total ?? 0
    hasMore.value = figures.value.length < total
    page.value = reset ? 2 : page.value + 1
  } catch (e) {
    // 列表接口是 silent 的，失败时保留旧数据，仅提示
    uni.showToast({ title: '图鑑加载失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

function reload() {
  hasMore.value = true
  page.value = 1
  load(true)
}

function goSearch() {
  uni.navigateTo({ url: '/pages/search/index' })
}

function goAuctions() {
  uni.navigateTo({ url: '/pages/auction/index' })
}

/** 购物车与卖家中心都需要登录态，未登录直接送去登录页，避免进去一片空 */
function goWithAuth(url) {
  if (!isLoggedIn()) {
    uni.navigateTo({ url: '/pages/login/index' })
    return
  }

  uni.navigateTo({ url })
}

function goCart() {
  goWithAuth('/pages/cart/index')
}

function goSeller() {
  goWithAuth('/pages/seller/index')
}

function goMine() {
  uni.switchTab({ url: '/pages/me/index' })
}

/** 搜索页选品类后回传：等价于首页点品类 chip，不用重开一页 */
function onPickCategory(value) {
  if (typeof value !== 'string' || category.value === value) return
  category.value = value
  reload()
}

function pickCategory(value) {
  if (category.value === value) return
  category.value = value
  reload()
}

function openFigure(figure) {
  uni.navigateTo({ url: `/pages/figure/detail?id=${figure.figure_id}` })
}

onLoad(() => {
  uni.$on('goat:pick-category', onPickCategory)
  load(true)
})

onPullDownRefresh(async () => {
  await load(true)
  uni.stopPullDownRefresh()
})

onReachBottom(() => load(false))

onUnload(() => {
  uni.$off('goat:pick-category', onPickCategory)
})
</script>

<style lang="scss" scoped>
.feed {
  min-height: 100vh;
  padding: 24rpx 24rpx 48rpx;
}

.feed__head {
  margin-bottom: 20rpx;
}

.feed__search {
  display: flex;
  align-items: center;
  height: 88rpx;
  padding: 0 24rpx;
  border-radius: 999rpx;
  background-color: $goat-surface;
  border: 1rpx solid $goat-border;
}

.feed__search-icon {
  margin-right: 12rpx;
  color: $goat-text-dim;
  font-size: 32rpx;
}

.feed__search-placeholder {
  flex: 1;
  color: $goat-text-dim;
  font-size: 28rpx;
}

.feed__search-go {
  color: $goat-accent;
  font-size: 26rpx;
  font-weight: 600;
}

.shortcuts {
  display: flex;
  gap: 16rpx;
  margin-top: 20rpx;
}

.shortcut {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8rpx;
  padding: 18rpx 0;
  border-radius: $goat-radius-sm;
  background-color: $goat-surface;
  border: 1rpx solid $goat-border;
}

.shortcut__icon {
  color: $goat-accent;
  font-size: 30rpx;
}

.shortcut__label {
  color: $goat-text-muted;
  font-size: 22rpx;
}

.feed__chips {
  white-space: nowrap;
  margin-bottom: 24rpx;
}

.feed__chips-inner {
  display: flex;
  gap: 16rpx;
  padding-right: 24rpx;
}

.feed__grid {
  display: flex;
  flex-wrap: wrap;
  gap: 20rpx;
}

.card {
  width: calc((100% - 20rpx) / 2);
  border-radius: $goat-radius;
  background-color: $goat-surface;
  border: 1rpx solid $goat-border;
  overflow: hidden;
}

.card__cover {
  position: relative;
  width: 100%;
  height: 320rpx;
  background-color: $goat-surface-2;
}

.card__image {
  width: 100%;
  height: 100%;
}

.card__placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  color: rgba(255, 255, 255, 0.12);
  font-size: 40rpx;
  font-weight: 800;
  letter-spacing: 4rpx;
}

.card__badge {
  position: absolute;
  left: 12rpx;
  top: 12rpx;
  padding: 4rpx 12rpx;
  border-radius: 999rpx;
  background-color: rgba(11, 11, 12, 0.72);
  color: $goat-accent;
  font-size: 20rpx;
}

.card__body {
  padding: 16rpx 20rpx 20rpx;
}

.card__name {
  color: $goat-text;
  font-size: 26rpx;
  font-weight: 600;
  line-height: 1.4;
  min-height: 72rpx;
}

.card__maker {
  display: block;
  margin-top: 6rpx;
  color: $goat-text-muted;
  font-size: 22rpx;
}

.card__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 14rpx;
}

.card__price {
  font-size: 30rpx;
}
</style>

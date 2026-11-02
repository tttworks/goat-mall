<!--
  GOAT 潮玩交易平台 / GOAT 潮玩数据采集
  Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
  Author: Aloysius Luo
  本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
-->

<template>
  <view v-if="figure" class="detail">
    <swiper
      v-if="gallery.length > 1"
      class="detail__gallery"
      :indicator-dots="true"
      indicator-active-color="#D8FF3E"
      indicator-color="rgba(255,255,255,0.24)"
      circular
    >
      <swiper-item v-for="(url, index) in gallery" :key="index">
        <image class="detail__gallery-image" :src="url" mode="aspectFill" />
      </swiper-item>
    </swiper>
    <view v-else class="detail__gallery">
      <image v-if="gallery.length" class="detail__gallery-image" :src="gallery[0]" mode="aspectFill" />
      <view v-else class="detail__placeholder"><text>GOAT</text></view>
    </view>

    <view class="detail__head">
      <view class="g-row--between">
        <text class="detail__name g-flex-1">{{ figure.name }}</text>
        <view class="detail__fav" @tap="toggleFavorite">
          <text :class="{ 'detail__fav--on': favorited }">{{ favorited ? '★' : '☆' }}</text>
        </view>
      </view>
      <text v-if="figure.name_ja || figure.name_en" class="detail__name-sub">
        {{ figure.name_ja || figure.name_en }}
      </text>

      <view class="detail__tags">
        <view v-if="figure.maker" class="g-tag"><text>{{ figure.maker }}</text></view>
        <view v-if="figure.series" class="g-tag"><text>{{ figure.series }}</text></view>
        <view v-if="figure.scale" class="g-tag"><text>{{ figure.scale }}</text></view>
        <view v-if="figure.year" class="g-tag"><text>{{ figure.year }}</text></view>
        <view class="g-tag g-tag--accent"><text>{{ figure.category_label }}</text></view>
      </view>

      <view class="detail__stats">
        <view class="detail__stat">
          <text class="detail__stat-value g-price">
            <text class="g-price__symbol">¥</text>{{ figure.price_low ? money(figure.price_low) : '—' }}
          </text>
          <text class="g-dim">当前最低在售</text>
        </view>
        <view class="detail__stat">
          <text class="detail__stat-value">{{ figure.listings_count }}</text>
          <text class="g-dim">在售件数</text>
        </view>
        <view class="detail__stat">
          <text class="detail__stat-value">{{ figure.views }}</text>
          <text class="g-dim">浏览</text>
        </view>
      </view>
    </view>

    <view v-if="specRows.length" class="g-card detail__section">
      <text class="g-title detail__section-title">规格</text>
      <view v-for="row in specRows" :key="row.key" class="detail__spec">
        <text class="detail__spec-key">{{ row.key }}</text>
        <text class="detail__spec-value">{{ row.value }}</text>
      </view>
    </view>

    <view class="detail__section">
      <view class="g-row--between detail__section-head">
        <text class="g-title">现在能买到</text>
        <text class="g-dim">{{ listings.length }} 件</text>
      </view>

      <view v-if="listings.length" class="detail__list">
        <view
          v-for="item in listings"
          :key="item.listing_id"
          class="goods"
          @tap="openListing(item)"
        >
          <image
            v-if="item.cover_url"
            class="goods__cover"
            :src="item.cover_url"
            mode="aspectFill"
          />
          <view v-else class="goods__cover goods__cover--empty"><text>GOAT</text></view>
          <view class="goods__body">
            <text class="goods__title g-clamp-2">{{ item.title }}</text>
            <view class="g-row--between goods__footer">
              <text class="g-tag"><text>{{ conditionLabel(item.condition) }}</text></text>
              <text class="g-price goods__price">
                <text class="g-price__symbol">¥</text>{{ money(item.price) }}
              </text>
            </view>
          </view>
        </view>
      </view>

      <view v-else class="g-empty">
        <text>暂无人出货，先収藏等通知</text>
      </view>
    </view>
  </view>

  <view v-else-if="loading" class="g-loading">
    <text>加载中…</text>
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { getFigure } from '../../api/catalog'
import { addFavorite, listFavorites, removeFavorite } from '../../api/social'
import { requireLogin } from '../../store/session'
import { conditionLabel, money } from '../../utils/format'
import { toast } from '../../utils/ui'

const figure = ref(null)
const loading = ref(false)
const favorited = ref(false)

const gallery = computed(() => {
  if (!figure.value) return []

  const urls = (figure.value.gallery_urls || []).filter(Boolean)
  if (urls.length) return urls

  return figure.value.cover_url ? [figure.value.cover_url] : []
})

const listings = computed(() => figure.value?.listings || [])

/** specs 是后端预留的自由结构（键值）。这里只做展示，不假定固定字段 */
const specRows = computed(() => {
  const specs = figure.value?.specs

  if (!specs || typeof specs !== 'object') return []

  return Object.keys(specs)
    .filter((key) => specs[key] !== null && specs[key] !== '')
    .map((key) => ({ key, value: String(specs[key]) }))
})

async function loadFigure(id) {
  loading.value = true
  try {
    const payload = await getFigure(id)
    figure.value = payload.data
    uni.setNavigationBarTitle({ title: payload.data?.name || '图鑑详情' })
    syncFavoriteState()
  } catch (e) {
    toast('图鑑不存在或已下架')
  } finally {
    loading.value = false
  }
}

/** 收藏态以服务端为准：本地不做乐观写入，避免多端切换后状态漂移 */
async function syncFavoriteState() {
  if (!requireLoginSilent()) return

  try {
    const payload = await listFavorites({ target_type: 'figure', per_page: 50 })
    const rows = payload.data || []

    favorited.value = rows.some(
      (row) => row.target_type === 'figure' && Number(row.target_id) === Number(figure.value.figure_id)
    )
  } catch (e) {
    favorited.value = false
  }
}

function requireLoginSilent() {
  // 未登录也能看图鑑，只是不给收藏
  return Boolean(uni.getStorageSync('goat:token'))
}

async function toggleFavorite() {
  if (!requireLogin()) return

  const id = figure.value.figure_id
  try {
    if (favorited.value) {
      await removeFavorite('figure', id)
      favorited.value = false
      toast('已取消收藏')
    } else {
      await addFavorite('figure', id)
      favorited.value = true
      toast('已收藏，可在「我的」里查看')
    }
  } catch (e) {
    // 错误提示由请求层统一处理
  }
}

function openListing(item) {
  uni.navigateTo({ url: `/pages/listing/detail?id=${item.listing_id}` })
}

onLoad((options) => {
  if (options?.id) {
    loadFigure(options.id)
  } else {
    toast('缺少图鑑参数')
  }
})
</script>

<style lang="scss" scoped>
.detail {
  min-height: 100vh;
  padding-bottom: 64rpx;
}

.detail__gallery {
  width: 100%;
  height: 640rpx;
  background-color: $goat-surface-2;
}

.detail__gallery-image {
  width: 100%;
  height: 100%;
}

.detail__placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100%;
  color: rgba(255, 255, 255, 0.12);
  font-size: 56rpx;
  font-weight: 800;
  letter-spacing: 8rpx;
}

.detail__head {
  padding: 28rpx 24rpx 8rpx;
}

.detail__name {
  color: $goat-text;
  font-size: 40rpx;
  font-weight: 700;
  line-height: 1.35;
}

.detail__fav {
  padding: 0 8rpx 0 24rpx;
  color: $goat-accent;
  font-size: 44rpx;
}

.detail__fav--on {
  color: $goat-accent;
}

.detail__name-sub {
  display: block;
  margin-top: 10rpx;
  color: $goat-text-muted;
  font-size: 24rpx;
}

.detail__tags {
  display: flex;
  flex-wrap: wrap;
  gap: 12rpx;
  margin-top: 20rpx;
}

.detail__stats {
  display: flex;
  margin-top: 28rpx;
  padding: 24rpx;
  border-radius: $goat-radius;
  background-color: $goat-surface;
  border: 1rpx solid $goat-border;
}

.detail__stat {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 8rpx;
}

.detail__stat-value {
  color: $goat-text;
  font-size: 34rpx;
  font-weight: 700;
}

.detail__section {
  margin: 24rpx 24rpx 0;
}

.detail__section-head {
  margin-bottom: 20rpx;
}

.detail__section-title {
  display: block;
  margin-bottom: 16rpx;
}

.detail__spec {
  display: flex;
  justify-content: space-between;
  padding: 12rpx 0;
  border-bottom: 1rpx solid $goat-border;
}

.detail__spec:last-child {
  border-bottom: none;
}

.detail__spec-key {
  color: $goat-text-muted;
  font-size: 24rpx;
}

.detail__spec-value {
  color: $goat-text;
  font-size: 24rpx;
}

.detail__list {
  display: flex;
  flex-direction: column;
  gap: 20rpx;
}

.goods {
  display: flex;
  gap: 20rpx;
  padding: 20rpx;
  border-radius: $goat-radius;
  background-color: $goat-surface;
  border: 1rpx solid $goat-border;
}

.goods__cover {
  width: 168rpx;
  height: 168rpx;
  border-radius: $goat-radius-sm;
  background-color: $goat-surface-2;
  flex-shrink: 0;
}

.goods__cover--empty {
  display: flex;
  align-items: center;
  justify-content: center;
  color: rgba(255, 255, 255, 0.12);
  font-size: 22rpx;
}

.goods__body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}

.goods__title {
  color: $goat-text;
  font-size: 26rpx;
  line-height: 1.45;
}

.goods__footer {
  margin-top: 12rpx;
}

.goods__price {
  font-size: 32rpx;
}
</style>

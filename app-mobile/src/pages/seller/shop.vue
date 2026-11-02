<!--
  GOAT 潮玩交易平台 / GOAT 潮玩数据采集
  Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
  Author: Aloysius Luo
  本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
-->

<template>
  <view class="shop">
    <view v-if="loading && !seller" class="g-loading"><text>加载中…</text></view>

    <block v-else-if="seller">
      <!-- 店铺名片：买家从商品页点卖家进来，第一屏要知道「这是谁、敢不敢买」 -->
      <view class="card">
        <view class="card__head">
          <image v-if="seller.logo_url" class="card__logo" :src="seller.logo_url" mode="aspectFill" />
          <view v-else class="card__logo card__logo--empty"><text>GOAT</text></view>

          <view class="card__body">
            <view class="card__title-row">
              <text class="card__name">{{ seller.shop_name }}</text>
              <text v-if="seller.status !== 'active'" class="g-tag g-tag--danger">
                <text>{{ seller.status_label }}</text>
              </text>
            </view>
            <text class="card__intro g-clamp-2">
              {{ seller.shop_intro || '这家店还没写简介，可以先看看在售商品与评价' }}
            </text>
          </view>
        </view>

        <view class="card__stats">
          <view class="stat" @tap="openReviews">
            <text class="stat__value">{{ seller.rating ? seller.rating.toFixed(1) : '—' }}</text>
            <text class="g-dim">店铺评分</text>
          </view>
          <view class="stat" @tap="openReviews">
            <text class="stat__value">{{ seller.review_count }}</text>
            <text class="g-dim">评价</text>
          </view>
          <view class="stat">
            <text class="stat__value">{{ seller.sold_count }}</text>
            <text class="g-dim">累计成交</text>
          </view>
          <view class="stat">
            <text class="stat__value">{{ seller.follower_count }}</text>
            <text class="g-dim">关注</text>
          </view>
        </view>

        <view class="card__actions">
          <block v-if="isSelf">
            <view class="g-btn g-btn--sm" @tap="goSellerCenter"><text>进入卖家中心</text></view>
          </block>
          <block v-else>
            <view
              class="g-btn g-btn--sm"
              :class="{ 'g-btn--primary': !following }"
              @tap="toggleFollow"
            >
              <text>{{ following ? '已关注' : '关注店铺' }}</text>
            </view>
            <view class="g-btn g-btn--sm" @tap="chatWithSeller"><text>私信卖家</text></view>
          </block>
        </view>
      </view>

      <!-- 在售商品 -->
      <view class="section">
        <view class="g-row--between section__head">
          <text class="g-title">在售商品</text>
          <text class="g-dim">{{ seller.listing_count }} 件在架</text>
        </view>

        <view v-if="listings.length" class="grid">
          <view
            v-for="item in listings"
            :key="item.listing_id"
            class="goods"
            @tap="openListing(item.listing_id)"
          >
            <view class="goods__cover">
              <image v-if="item.cover_url" class="goods__image" :src="item.cover_url" mode="aspectFill" />
              <view v-else class="goods__placeholder"><text>无图</text></view>
            </view>
            <view class="goods__body">
              <text class="goods__title g-clamp-2">{{ item.title }}</text>
              <view class="g-row--between">
                <text class="g-tag"><text>{{ conditionLabel(item.condition) }}</text></text>
                <text class="g-price goods__price">
                  <text class="g-price__symbol">¥</text>{{ money(item.price) }}
                </text>
              </view>
            </view>
          </view>
        </view>

        <view v-else-if="!listingsLoading" class="g-empty">
          <text>{{ seller.listing_count ? '在售商品加载失败，下拉重试' : '这家店暂时没有在架商品，关注后可收到上新提醒' }}</text>
        </view>

        <view v-if="listingsLoading" class="g-loading"><text>加载商品…</text></view>
        <view v-else-if="listings.length < listingTotal" class="section__more" @tap="loadMoreListings">
          <text>加载更多（{{ listings.length }}/{{ listingTotal }}）</text>
        </view>
      </view>

      <view class="section">
        <view class="menu-row" @tap="openReviews">
          <text class="menu-row__label">全部评价</text>
          <text class="g-dim">{{ seller.review_count }} 条 ›</text>
        </view>
        <view class="menu-row" @tap="openFigures">
          <text class="menu-row__label">他的图鑑</text>
          <text class="g-dim">按作品看行情 ›</text>
        </view>
      </view>
    </block>

    <view v-else class="g-empty">
      <text>店铺不存在或已关闭</text>
      <view class="g-btn g-btn--sm empty__btn" @tap="goHome"><text>回首页逛逛</text></view>
    </view>
  </view>
</template>

<script setup>
import { ref } from 'vue'
import { onLoad, onShow } from '@dcloudio/uni-app'
import { getSeller, listListings } from '../../api/catalog'
import { followSeller, unfollowSeller, openConversation } from '../../api/social'
import { requireLogin } from '../../store/session'
import { conditionLabel, money } from '../../utils/format'
import { toast } from '../../utils/ui'

const sellerId = ref(0)
const seller = ref(null)
const following = ref(false)
const isSelf = ref(false)
const loading = ref(true)

const listings = ref([])
const listingTotal = ref(0)
const listingsPage = ref(1)
const listingsLoading = ref(false)

async function loadShop() {
  loading.value = true

  try {
    const payload = await getSeller(sellerId.value)
    seller.value = payload?.data || null
    following.value = Boolean(payload?.following)
    isSelf.value = Boolean(payload?.is_self)
  } catch (e) {
    seller.value = null
  } finally {
    loading.value = false
  }
}

async function loadListings({ reset = false } = {}) {
  if (!sellerId.value) return

  listingsLoading.value = true

  try {
    // 公开索引本来就只出 active 且 stock>0 的商品，不必再传 status
    const payload = await listListings({
      seller_id: sellerId.value,
      per_page: 20,
      page: reset ? 1 : listingsPage.value
    })
    const rows = payload?.data || []

    listings.value = reset ? rows : [...listings.value, ...rows]
    listingTotal.value = payload?.meta?.total ?? listings.value.length
  } catch (e) {
    if (reset) listings.value = []
  } finally {
    listingsLoading.value = false
  }
}

function loadMoreListings() {
  listingsPage.value += 1
  loadListings()
}

async function toggleFollow() {
  if (!requireLogin()) return
  if (isSelf.value) return

  const next = !following.value
  following.value = next

  try {
    if (next) {
      await followSeller(sellerId.value)
      toast('关注成功，上新会通知你')
    } else {
      await unfollowSeller(sellerId.value)
    }

    if (seller.value) {
      seller.value.follower_count = Math.max(0, Number(seller.value.follower_count || 0) + (next ? 1 : -1))
    }
  } catch (e) {
    following.value = !next
  }
}

async function chatWithSeller() {
  if (!requireLogin()) return

  try {
    const payload = await openConversation(sellerId.value)
    const conversationId = payload?.data?.conversation_id
    if (!conversationId) return

    uni.navigateTo({ url: `/pages/im/chat?id=${conversationId}&peer=${sellerId.value}` })
  } catch (e) {
    /* request 层已提示 */
  }
}

function openListing(id) {
  uni.navigateTo({ url: `/pages/listing/detail?id=${id}` })
}

function openReviews() {
  uni.navigateTo({ url: `/pages/seller/reviews?id=${sellerId.value}` })
}

function openFigures() {
  uni.switchTab({ url: '/pages/index/index' })
}

function goSellerCenter() {
  uni.navigateTo({ url: '/pages/seller/index' })
}

function goHome() {
  uni.switchTab({ url: '/pages/index/index' })
}

onLoad((options) => {
  sellerId.value = Number(options?.id || 0)

  if (!sellerId.value) {
    loading.value = false
    return
  }

  loadShop()
  loadListings({ reset: true })
})

// 从评价墙返回时评分可能已变，重新拉一次名片（商品列表不必重拉）
onShow(() => {
  if (sellerId.value && seller.value) loadShop()
})
</script>

<style lang="scss" scoped>
.shop {
  min-height: 100vh;
  padding: 24rpx 24rpx 64rpx;
  display: flex;
  flex-direction: column;
  gap: 24rpx;
}

.card {
  padding: 28rpx 24rpx;
  border-radius: $goat-radius;
  background-color: $goat-surface;
  border: 1rpx solid $goat-border;
  display: flex;
  flex-direction: column;
  gap: 24rpx;
}

.card__head {
  display: flex;
  gap: 20rpx;
}

.card__logo {
  width: 128rpx;
  height: 128rpx;
  border-radius: $goat-radius-sm;
  background-color: $goat-surface-2;
  flex-shrink: 0;
}

.card__logo--empty {
  display: flex;
  align-items: center;
  justify-content: center;
  color: rgba(255, 255, 255, 0.18);
  font-size: 22rpx;
  letter-spacing: 2rpx;
}

.card__body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 10rpx;
}

.card__title-row {
  display: flex;
  align-items: center;
  gap: 12rpx;
}

.card__name {
  color: $goat-text;
  font-size: 32rpx;
  font-weight: 700;
}

.card__intro {
  color: $goat-text-muted;
  font-size: 24rpx;
  line-height: 1.6;
}

.card__stats {
  display: flex;
  padding: 20rpx 0;
  border-top: 1rpx solid $goat-border;
  border-bottom: 1rpx solid $goat-border;
}

.stat {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6rpx;
}

.stat__value {
  color: $goat-accent;
  font-size: 32rpx;
  font-weight: 700;
}

.card__actions {
  display: flex;
  gap: 16rpx;
}

.section {
  padding: 24rpx;
  border-radius: $goat-radius;
  background-color: $goat-surface;
  border: 1rpx solid $goat-border;
  display: flex;
  flex-direction: column;
  gap: 20rpx;
}

.section__head {
  align-items: baseline;
}

.grid {
  display: flex;
  flex-wrap: wrap;
  gap: 16rpx;
}

.goods {
  width: calc(50% - 8rpx);
  border-radius: $goat-radius-sm;
  background-color: $goat-surface-2;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

.goods__cover {
  width: 100%;
  height: 300rpx;
  background-color: rgba(255, 255, 255, 0.04);
}

.goods__image {
  width: 100%;
  height: 100%;
}

.goods__placeholder {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: rgba(255, 255, 255, 0.16);
  font-size: 22rpx;
}

.goods__body {
  padding: 16rpx;
  display: flex;
  flex-direction: column;
  gap: 12rpx;
}

.goods__title {
  color: $goat-text;
  font-size: 24rpx;
  line-height: 1.45;
}

.goods__price {
  font-size: 26rpx;
}

.section__more {
  padding: 16rpx 0 4rpx;
  text-align: center;
  color: $goat-text-dim;
  font-size: 24rpx;
}

.menu-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 20rpx 0;
  border-bottom: 1rpx solid $goat-border;
}

.menu-row:last-child {
  border-bottom: 0;
}

.menu-row__label {
  color: $goat-text;
  font-size: 26rpx;
}

.empty__btn {
  margin-top: 24rpx;
}
</style>

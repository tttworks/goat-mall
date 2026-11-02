<!--
  GOAT 潮玩交易平台 / GOAT 潮玩数据采集
  Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
  Author: Aloysius Luo
  本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
-->

<template>
  <view v-if="listing" class="detail">
    <swiper v-if="gallery.length > 1" class="detail__gallery" :indicator-dots="true" circular>
      <swiper-item v-for="(url, index) in gallery" :key="index">
        <image class="detail__image" :src="url" mode="aspectFill" />
      </swiper-item>
    </swiper>
    <view v-else class="detail__gallery">
      <image v-if="gallery.length" class="detail__image" :src="gallery[0]" mode="aspectFill" />
      <view v-else class="detail__placeholder"><text>GOAT</text></view>
    </view>

    <view class="detail__head">
      <view class="detail__price-row">
        <text class="g-price g-price--lg">
          <text class="g-price__symbol">¥</text>{{ money(listing.price) }}
        </text>
        <text class="g-tag"><text>{{ conditionLabel(listing.condition) }}</text></text>
      </view>
      <text class="detail__title">{{ listing.title }}</text>

      <view v-if="listing.figure" class="detail__figure" @tap="openFigure">
        <text class="detail__figure-name">{{ listing.figure.name }}</text>
        <text class="g-dim">{{ [listing.figure.maker, listing.figure.scale].filter(Boolean).join(' · ') }}</text>
      </view>

      <view v-if="listing.description" class="g-card detail__desc">
        <text class="detail__desc-text">{{ listing.description }}</text>
      </view>

      <view class="g-card detail__seller">
        <view class="g-flex-1">
          <text class="detail__seller-title">卖家 #{{ listing.seller_id }}</text>
          <text class="g-dim">平台担保交易 · 收货确认后结算</text>
        </view>
        <view class="g-btn g-btn--sm" @tap="followSellerAction">
          <text>{{ following ? '已关注' : '关注' }}</text>
        </view>
      </view>

      <view class="detail__notice">
        <text class="g-dim">担保说明：付款后资金由平台冻结，收到货确认无误才结算给卖家；卖家 3 日内未发货可取消。</text>
      </view>
    </view>

    <view class="detail__bar">
      <view class="detail__bar-icon" @tap="toggleFavorite">
        <text :class="{ 'detail__bar-icon--on': favorited }">{{ favorited ? '★' : '☆' }}</text>
        <text class="g-dim">收藏</text>
      </view>
      <view class="detail__bar-icon" @tap="chatWithSeller">
        <text>✉</text>
        <text class="g-dim">私信</text>
      </view>
      <view
        class="g-btn g-btn--primary detail__buy"
        :class="{ 'g-btn--disabled': submitting }"
        @tap="buy"
      >
        <text>{{ purchasable ? '立即购买' : listing.status_label }}</text>
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
import { getListing } from '../../api/catalog'
import { addFavorite, followSeller, listFavorites, removeFavorite } from '../../api/social'
import { openConversation } from '../../api/social'
import { createOrder, payOrder, simulatePayment } from '../../api/trade'
import { isLoggedIn, requireLogin } from '../../store/session'
import { conditionLabel, money } from '../../utils/format'
import { confirmModal, toast } from '../../utils/ui'

const listing = ref(null)
const loading = ref(false)
const submitting = ref(false)
const favorited = ref(false)
const following = ref(false)

const gallery = computed(() => {
  if (!listing.value) return []

  const urls = (listing.value.gallery_urls || []).filter(Boolean)
  if (urls.length) return urls

  return listing.value.cover_url ? [listing.value.cover_url] : []
})

const purchasable = computed(() => listing.value?.status === 'active' && listing.value?.stock > 0)

async function load(id) {
  loading.value = true
  try {
    const payload = await getListing(id)
    listing.value = payload.data
    uni.setNavigationBarTitle({ title: '商品详情' })
    if (isLoggedIn()) syncFavoriteState()
  } catch (e) {
    toast('商品不存在或已下架')
  } finally {
    loading.value = false
  }
}

async function syncFavoriteState() {
  try {
    const payload = await listFavorites({ target_type: 'listing', per_page: 50 })
    favorited.value = (payload.data || []).some(
      (row) => Number(row.target_id) === Number(listing.value.listing_id)
    )
  } catch (e) {
    favorited.value = false
  }
}

async function toggleFavorite() {
  if (!requireLogin()) return

  try {
    if (favorited.value) {
      await removeFavorite('listing', listing.value.listing_id)
      favorited.value = false
      toast('已取消收藏')
    } else {
      await addFavorite('listing', listing.value.listing_id)
      favorited.value = true
      toast('已收藏')
    }
  } catch (e) {
    // 统一错误提示
  }
}

async function followSellerAction() {
  if (!requireLogin()) return
  if (following.value) {
    toast('已关注该卖家')
    return
  }

  try {
    await followSeller(listing.value.seller_id)
    following.value = true
    toast('关注成功')
  } catch (e) {
    // 统一错误提示
  }
}

async function chatWithSeller() {
  if (!requireLogin()) return

  try {
    const payload = await openConversation(listing.value.seller_id)
    const conversationId = payload.data?.conversation_id
    if (!conversationId) return

    uni.navigateTo({
      url: `/pages/im/chat?id=${conversationId}&title=${encodeURIComponent(listing.value.title)}`
    })
  } catch (e) {
    // 统一错误提示
  }
}

/**
 * 下单 → 支付 →（沙箱）模拟成功。
 * 三步各自独立失败：下单成功但支付失败时，订单仍在「待付款」，用户可回订单页继续付，
 * 所以这里不能把三段包进一个 try 里再整体回滚。
 */
async function buy() {
  if (!requireLogin()) return
  if (!purchasable.value) {
    toast('该商品当前不可购买')
    return
  }
  if (submitting.value) return

  const ok = await confirmModal({
    title: '确认下单',
    content: `${listing.value.title}\n应付 ¥${money(listing.value.price)}`,
    confirmText: '去支付'
  })
  if (!ok) return

  submitting.value = true
  try {
    const created = await createOrder({ listing_id: listing.value.listing_id })
    const orderId = created.data?.order_id
    if (!orderId) throw new Error('下单失败')

    const payment = await payOrder(orderId)
    const paymentId = payment.data?.payment_id

    if (payment.data?.channel === 'sandbox' && paymentId) {
      await simulatePayment(paymentId)
      toast('支付成功，资金已由平台冻结')
    } else {
      toast('支付单已创建')
    }

    setTimeout(() => uni.navigateTo({ url: '/pages/me/orders' }), 600)
  } catch (e) {
    // 已下单未支付：引导去订单页补付，而不是让用户重复下单
    const goOrders = await confirmModal({
      title: '支付未完成',
      content: '订单已创建，是否前往「我的订单」继续支付？',
      confirmText: '去支付'
    })
    if (goOrders) uni.navigateTo({ url: '/pages/me/orders' })
  } finally {
    submitting.value = false
  }
}

function openFigure() {
  if (!listing.value?.figure?.figure_id) return

  uni.navigateTo({ url: `/pages/figure/detail?id=${listing.value.figure.figure_id}` })
}

onLoad((options) => {
  if (options?.id) {
    load(options.id)
  } else {
    toast('缺少商品参数')
  }
})
</script>

<style lang="scss" scoped>
.detail {
  min-height: 100vh;
  padding-bottom: 160rpx;
}

.detail__gallery {
  width: 100%;
  height: 720rpx;
  background-color: $goat-surface-2;
}

.detail__image {
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
  padding: 28rpx 24rpx 0;
}

.detail__price-row {
  display: flex;
  align-items: center;
  gap: 16rpx;
}

.detail__title {
  display: block;
  margin-top: 16rpx;
  color: $goat-text;
  font-size: 32rpx;
  font-weight: 600;
  line-height: 1.5;
}

.detail__figure {
  display: flex;
  flex-direction: column;
  gap: 6rpx;
  margin-top: 20rpx;
  padding: 20rpx 24rpx;
  border-radius: $goat-radius;
  background-color: $goat-surface;
  border: 1rpx solid $goat-border;
}

.detail__figure-name {
  color: $goat-accent;
  font-size: 26rpx;
}

.detail__desc {
  margin-top: 20rpx;
}

.detail__desc-text {
  color: $goat-text-muted;
  font-size: 26rpx;
  line-height: 1.7;
  white-space: pre-wrap;
}

.detail__seller {
  display: flex;
  align-items: center;
  gap: 20rpx;
  margin-top: 20rpx;
}

.detail__seller-title {
  display: block;
  color: $goat-text;
  font-size: 28rpx;
  margin-bottom: 6rpx;
}

.detail__notice {
  margin-top: 20rpx;
  padding: 0 8rpx;
  line-height: 1.7;
}

.detail__bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  display: flex;
  align-items: center;
  gap: 24rpx;
  padding: 20rpx 24rpx calc(20rpx + env(safe-area-inset-bottom));
  background-color: rgba(15, 15, 17, 0.96);
  border-top: 1rpx solid $goat-border;
}

.detail__bar-icon {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4rpx;
  width: 88rpx;
  color: $goat-text-muted;
  font-size: 34rpx;
}

.detail__bar-icon--on {
  color: $goat-accent;
}

.detail__buy {
  flex: 1;
}
</style>

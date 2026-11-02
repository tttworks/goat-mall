<!--
  GOAT 潮玩交易平台 / GOAT 潮玩数据采集
  Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
  Author: Aloysius Luo
  本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
-->

<template>
  <view class="cart">
    <!-- 结算前必须确认寄往哪：放最上方，避免一路下拉到结算按钮才发现没地址 -->
    <view v-if="items.length" class="g-card cart__addr" @tap="openAddresses">
      <view class="g-flex-1">
        <text class="cart__addr-title">{{ address ? '寄往' : '收货地址' }}</text>
        <text v-if="address" class="cart__addr-text">
          {{ address.receiver_name }} {{ address.receiver_phone }}
        </text>
        <text v-if="address" class="cart__addr-text">{{ address.region }}{{ address.detail }}</text>
        <text v-else class="g-dim">请先填写收货地址</text>
      </view>
      <text class="g-dim cart__addr-arrow">›</text>
    </view>

    <view v-if="items.length" class="cart__head">
      <text class="g-muted">共 {{ items.length }} 件 · 已选 {{ selected.length }} 件</text>
      <text class="g-muted" @tap="onClear">清空</text>
    </view>

    <view v-if="items.length" class="cart__list">
      <view v-for="row in items" :key="row.cart_item_id" class="cart__row" :class="{ 'cart__row--off': !row.purchasable }">
        <view
          class="cart__check"
          :class="{ 'cart__check--on': isSelected(row), 'cart__check--off': !row.purchasable }"
          @tap="toggle(row)"
        >
          <text v-if="isSelected(row)">✓</text>
        </view>

        <image
          v-if="row.listing?.cover_url"
          class="cart__cover"
          :src="row.listing.cover_url"
          mode="aspectFill"
          @tap="openListing(row.listing_id)"
        />
        <view v-else class="cart__cover cart__cover--empty" @tap="openListing(row.listing_id)">
          <text>GOAT</text>
        </view>

        <view class="cart__body">
          <text class="cart__title g-clamp-2" @tap="openListing(row.listing_id)">
            {{ row.listing?.title || '商品已删除' }}
          </text>
          <view class="cart__meta">
            <text v-if="!row.purchasable" class="g-tag">已不可购买</text>
            <text v-else class="g-tag g-tag--accent">可立即购买</text>
          </view>
          <view class="g-row--between cart__foot">
            <text class="g-price cart__price">
              <text class="g-price__symbol">¥</text>{{ money(row.listing?.price) }}
            </text>
            <text class="cart__remove" @tap="onRemove(row)">移除</text>
          </view>
        </view>
      </view>
    </view>

    <view v-else-if="!loading" class="cart__empty">
      <view class="cart__empty-mark"><text>GOAT</text></view>
      <text class="g-title">购物车还是空的</text>
      <text class="g-muted cart__empty-tip">看到心仪的潮玩先放进来，避免被别人抢先</text>
      <view class="g-btn g-btn--primary cart__empty-btn" @tap="goHome">
        <text>去逛图鑑</text>
      </view>
    </view>

    <view v-if="loading" class="g-loading"><text>加载中…</text></view>

    <!-- 底部结算条：金额恒为服务端价格之和，前端只做展示 -->
    <view v-if="items.length" class="cart__bar">
      <view class="cart__sum">
        <text class="g-dim">合计</text>
        <text class="g-price cart__sum-value">
          <text class="g-price__symbol">¥</text>{{ money(total) }}
        </text>
      </view>
      <view
        class="g-btn g-btn--primary cart__submit"
        :class="{ 'g-btn--disabled': !selected.length || submitting }"
        @tap="checkout"
      >
        <text>{{ submitting ? '处理中…' : `结算(${selected.length})` }}</text>
      </view>
    </view>
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { listAddresses, takeSelectedAddress } from '../../api/address'
import { clearCart, listCart, removeFromCart } from '../../api/social'
import { createOrder } from '../../api/trade'
import { money } from '../../utils/format'
import { confirmModal, toast } from '../../utils/ui'

const items = ref([])
const selectedIds = ref([])
const loading = ref(false)
const submitting = ref(false)
const address = ref(null)

/** 地址与商品同时刷新：从地址簿返回后结算条上的地址要立刻变 */
async function syncAddress() {
  const picked = takeSelectedAddress()
  if (picked) {
    address.value = picked
    return
  }

  try {
    const payload = await listAddresses()
    const rows = payload.data || []
    address.value = rows.find((row) => row.is_default) || rows[0] || null
  } catch (e) {
    address.value = null
  }
}

function openAddresses() {
  uni.navigateTo({ url: '/pages/me/addresses?select=1' })
}

/** 只把「仍可购买」的行算进已选，避免商品被人买走后金额还挂在结算条上 */
const selected = computed(() =>
  items.value.filter((row) => row.purchasable && selectedIds.value.includes(row.cart_item_id))
)

const total = computed(() =>
  selected.value.reduce((sum, row) => sum + Number(row.listing?.price || 0), 0)
)

function isSelected(row) {
  return selectedIds.value.includes(row.cart_item_id)
}

function toggle(row) {
  if (!row.purchasable) return

  selectedIds.value = isSelected(row)
    ? selectedIds.value.filter((id) => id !== row.cart_item_id)
    : [...selectedIds.value, row.cart_item_id]
}

async function load() {
  loading.value = true

  try {
    const payload = await listCart()
    items.value = payload?.data || []
    // 默认全选可购买项：购物车页最常见的动作就是「一次全结」
    selectedIds.value = items.value.filter((row) => row.purchasable).map((row) => row.cart_item_id)
  } catch (e) {
    items.value = []
  } finally {
    loading.value = false
  }
}

async function onRemove(row) {
  const ok = await confirmModal({ title: '移出购物车', content: '确定移出这件商品吗？' })
  if (!ok) return

  try {
    await removeFromCart(row.listing_id)
    items.value = items.value.filter((item) => item.cart_item_id !== row.cart_item_id)
    selectedIds.value = selectedIds.value.filter((id) => id !== row.cart_item_id)
  } catch (e) {
    /* request 层已经提示过 */
  }
}

async function onClear() {
  const ok = await confirmModal({ title: '清空购物车', content: '将移出全部商品，确定吗？' })
  if (!ok) return

  try {
    await clearCart()
    items.value = []
    selectedIds.value = []
  } catch (e) {
    /* 同上 */
  }
}

/**
 * 结算：C2C 一单一货，所以逐个下单。
 * 批量里某一单失败（别人抢先买走）不打断其余订单，最后统一报告成功/失败数。
 */
async function checkout() {
  if (!selected.value.length || submitting.value) return

  // 服务端会拒无地址下单，但先在这里拦一步，能直接把人带到地址页
  if (!address.value) {
    const goAdd = await confirmModal({
      title: '还没有收货地址',
      content: '实物交易需要收货地址，去添加一条？',
      confirmText: '去填写'
    })
    if (goAdd) uni.navigateTo({ url: '/pages/me/addresses' })
    return
  }

  submitting.value = true
  let created = 0
  const failed = []

  try {
    for (const row of selected.value) {
      try {
        // 同一批结算共用同一条收货地址（快照落进每张订单）
        await createOrder({ listing_id: row.listing_id, address_id: address.value.address_id })
        created += 1
        // 下单成功才移出购物车；失败保留，方便用户回来看看到底哪件没了
        await removeFromCart(row.listing_id).catch(() => {})
      } catch (e) {
        failed.push(row.listing?.title || `#${row.listing_id}`)
      }
    }
  } finally {
    submitting.value = false
  }

  if (created > 0 && failed.length === 0) {
    uni.showToast({ title: `已生成 ${created} 笔订单`, icon: 'none' })
    uni.navigateTo({ url: '/pages/me/orders' })
  } else if (created > 0) {
    uni.showModal({
      title: '部分下单成功',
      content: `成功 ${created} 笔，失败 ${failed.length} 笔：${failed.join('、')}`,
      showCancel: false
    })
  } else {
    toast('下单失败，商品可能已被买走')
  }

  await load()
}

function openListing(id) {
  uni.navigateTo({ url: `/pages/listing/detail?id=${id}` })
}

function goHome() {
  uni.switchTab({ url: '/pages/index/index' })
}

onShow(() => {
  load()
  syncAddress()
})
</script>

<style lang="scss" scoped>
.cart {
  min-height: 100vh;
  padding: 24rpx 24rpx 200rpx;
}

.cart__addr {
  display: flex;
  align-items: center;
  gap: 20rpx;
  margin-bottom: 16rpx;
}

.cart__addr-title {
  display: block;
  margin-bottom: 6rpx;
  color: $goat-text-muted;
  font-size: 24rpx;
}

.cart__addr-text {
  display: block;
  color: $goat-text;
  font-size: 26rpx;
  line-height: 1.5;
}

.cart__addr-arrow {
  font-size: 32rpx;
}

.cart__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8rpx 4rpx 20rpx;
}

.cart__list {
  display: flex;
  flex-direction: column;
  gap: 20rpx;
}

.cart__row {
  display: flex;
  align-items: center;
  gap: 20rpx;
  padding: 20rpx;
  border-radius: $goat-radius;
  background-color: $goat-surface;
  border: 1rpx solid $goat-border;
}

.cart__row--off {
  opacity: 0.55;
}

.cart__check {
  width: 40rpx;
  height: 40rpx;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  border: 2rpx solid $goat-border;
  color: $goat-accent-ink;
  font-size: 22rpx;
}

.cart__check--on {
  background-color: $goat-accent;
  border-color: $goat-accent;
  font-weight: 700;
}

.cart__check--off {
  opacity: 0.4;
}

.cart__cover {
  width: 176rpx;
  height: 176rpx;
  border-radius: $goat-radius-sm;
  background-color: $goat-surface-2;
  flex-shrink: 0;
}

.cart__cover--empty {
  display: flex;
  align-items: center;
  justify-content: center;
  color: rgba(255, 255, 255, 0.12);
  font-size: 22rpx;
  letter-spacing: 2rpx;
}

.cart__body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 12rpx;
}

.cart__title {
  color: $goat-text;
  font-size: 27rpx;
  line-height: 1.45;
}

.cart__meta {
  display: flex;
  gap: 12rpx;
}

.cart__foot {
  margin-top: 4rpx;
}

.cart__price {
  font-size: 34rpx;
}

.cart__remove {
  color: $goat-text-dim;
  font-size: 24rpx;
}

.cart__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 180rpx 0 0;
  gap: 16rpx;
}

.cart__empty-mark {
  width: 200rpx;
  height: 200rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  border: 1rpx solid $goat-border;
  color: rgba(255, 255, 255, 0.1);
  font-size: 32rpx;
  font-weight: 800;
  letter-spacing: 4rpx;
  margin-bottom: 16rpx;
}

.cart__empty-tip {
  max-width: 480rpx;
  text-align: center;
  line-height: 1.6;
}

.cart__empty-btn {
  margin-top: 24rpx;
  width: 320rpx;
}

.cart__bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 24rpx;
  padding: 20rpx 32rpx calc(20rpx + env(safe-area-inset-bottom));
  background-color: rgba(11, 11, 12, 0.94);
  border-top: 1rpx solid $goat-border;
}

.cart__sum {
  display: flex;
  align-items: baseline;
  gap: 12rpx;
}

.cart__sum-value {
  font-size: 40rpx;
}

.cart__submit {
  width: 300rpx;
}
</style>

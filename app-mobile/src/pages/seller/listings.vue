<!--
  GOAT 潮玩交易平台 / GOAT 潮玩数据采集
  Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
  Author: Aloysius Luo
  本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
-->

<template>
  <view class="listings">
    <scroll-view class="listings__tabs" scroll-x :show-scrollbar="false">
      <view class="listings__tabs-inner">
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
    </scroll-view>

    <view v-if="items.length" class="listings__list">
      <view v-for="item in items" :key="item.listing_id" class="row">
        <view class="row__top" @tap="openDetail(item.listing_id)">
          <image v-if="item.cover_url" class="row__cover" :src="item.cover_url" mode="aspectFill" />
          <view v-else class="row__cover row__cover--empty"><text>GOAT</text></view>

          <view class="row__body">
            <text class="row__title g-clamp-2">{{ item.title }}</text>
            <view class="row__tags">
              <text class="g-tag" :class="{ 'g-tag--accent': item.status === 'active' }">
                <text>{{ item.status_label }}</text>
              </text>
              <text class="g-tag"><text>{{ conditionLabel(item.condition) }}</text></text>
            </view>
            <text class="g-price row__price"><text class="g-price__symbol">¥</text>{{ money(item.price) }}</text>
          </view>
        </view>

        <view v-if="item.reject_reason" class="row__reject">
          <text>驳回原因：{{ item.reject_reason }}</text>
        </view>

        <view class="row__actions">
          <view
            v-for="action in listingActions(item)"
            :key="action.key"
            class="g-btn g-btn--sm"
            :class="action.key === 'delist' ? 'g-btn--danger' : 'g-btn--ghost'"
            @tap="run(item, action.key)"
          >
            <text>{{ action.label }}</text>
          </view>
          <view class="g-btn g-btn--sm g-btn--ghost" @tap="openDetail(item.listing_id)">
            <text>预览</text>
          </view>
        </view>
      </view>
    </view>

    <view v-else-if="!loading" class="listings__empty">
      <view class="listings__empty-mark"><text>GOAT</text></view>
      <text class="g-title">{{ status ? '这个状态下还没有商品' : '还没有发布过商品' }}</text>
      <text class="g-muted listings__empty-tip">发布时绑定图鑑，成交后会自动计入行情走势</text>
      <view class="g-btn g-btn--primary listings__empty-btn" @tap="goCreate">
        <text>去发布</text>
      </view>
    </view>

    <view v-if="loading" class="g-loading"><text>加载中…</text></view>
    <view v-else-if="items.length && items.length < total" class="listings__more" @tap="loadMore">
      <text>加载更多（{{ items.length }}/{{ total }}）</text>
    </view>
    <view v-else-if="items.length" class="listings__end"><text>没有更多了</text></view>

    <view class="listings__fab" @tap="goCreate"><text>＋ 发布</text></view>
  </view>
</template>

<script setup>
import { ref } from 'vue'
import { onLoad, onShow } from '@dcloudio/uni-app'
import {
  delistListing,
  listMyListings,
  reopenListing,
  submitListing
} from '../../api/seller'
import { conditionLabel, listingActions, money } from '../../utils/format'
import { confirmModal, toast } from '../../utils/ui'

const TABS = [
  { value: '', label: '全部' },
  { value: 'active', label: '在售' },
  { value: 'pending_review', label: '待审' },
  { value: 'draft', label: '草稿' },
  { value: 'rejected', label: '被驳回' },
  { value: 'delisted', label: '已下架' },
  { value: 'sold', label: '已售出' }
]

const items = ref([])
const total = ref(0)
const page = ref(1)
const status = ref('')
const loading = ref(false)
const busy = ref(false)

async function load({ reset = false } = {}) {
  loading.value = true

  try {
    const payload = await listMyListings({
      status: status.value || undefined,
      per_page: 20,
      page: reset ? 1 : page.value
    })

    const rows = payload?.data || []
    items.value = reset ? rows : [...items.value, ...rows]
    total.value = payload?.meta?.total ?? items.value.length
  } catch (e) {
    if (reset) items.value = []
  } finally {
    loading.value = false
  }
}

function changeStatus(value) {
  if (status.value === value) return
  status.value = value
  page.value = 1
  load({ reset: true })
}

function loadMore() {
  page.value += 1
  load()
}

const CONFIRM_TEXT = {
  submit: '提交后进入审核，审核通过才对买家可见。确定提交？',
  delist: '下架后买家将无法购买，已产生的订单不受影响。确定下架？',
  reopen: '退回草稿后可以继续编辑并重新提交审核。确定退回？'
}

async function run(item, key) {
  if (busy.value) return

  if (key === 'edit') {
    uni.navigateTo({ url: `/pages/seller/edit?id=${item.listing_id}` })
    return
  }

  const ok = await confirmModal({ title: '确认操作', content: CONFIRM_TEXT[key] || '确定执行该操作？' })
  if (!ok) return

  busy.value = true

  try {
    if (key === 'submit') await submitListing(item.listing_id)
    if (key === 'delist') await delistListing(item.listing_id)
    if (key === 'reopen') await reopenListing(item.listing_id)

    toast('操作成功')
    page.value = 1
    await load({ reset: true })
  } catch (e) {
    /* request 层已提示（状态不允许等） */
  } finally {
    busy.value = false
  }
}

function openDetail(id) {
  uni.navigateTo({ url: `/pages/listing/detail?id=${id}` })
}

function goCreate() {
  uni.navigateTo({ url: '/pages/seller/edit' })
}

onLoad((options) => {
  status.value = options?.status || ''
})

onShow(() => {
  page.value = 1
  load({ reset: true })
})
</script>

<style lang="scss" scoped>
.listings {
  min-height: 100vh;
  padding: 24rpx 24rpx 180rpx;
}

.listings__tabs {
  width: 100%;
  white-space: nowrap;
  margin-bottom: 24rpx;
}

.listings__tabs-inner {
  display: inline-flex;
  gap: 16rpx;
}

.listings__list {
  display: flex;
  flex-direction: column;
  gap: 20rpx;
}

.row {
  padding: 20rpx;
  border-radius: $goat-radius;
  background-color: $goat-surface;
  border: 1rpx solid $goat-border;
  display: flex;
  flex-direction: column;
  gap: 16rpx;
}

.row__top {
  display: flex;
  gap: 20rpx;
}

.row__cover {
  width: 160rpx;
  height: 160rpx;
  border-radius: $goat-radius-sm;
  background-color: $goat-surface-2;
  flex-shrink: 0;
}

.row__cover--empty {
  display: flex;
  align-items: center;
  justify-content: center;
  color: rgba(255, 255, 255, 0.12);
  font-size: 20rpx;
  letter-spacing: 2rpx;
}

.row__body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 10rpx;
}

.row__title {
  color: $goat-text;
  font-size: 27rpx;
  line-height: 1.45;
}

.row__tags {
  display: flex;
  flex-wrap: wrap;
  gap: 12rpx;
}

.row__price {
  font-size: 32rpx;
}

.row__reject {
  padding: 12rpx 16rpx;
  border-radius: $goat-radius-sm;
  background-color: rgba(255, 90, 90, 0.1);
  color: $goat-danger;
  font-size: 22rpx;
  line-height: 1.5;
}

.row__actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 12rpx;
}

.listings__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 14rpx;
  padding: 140rpx 0 0;
}

.listings__empty-mark {
  width: 160rpx;
  height: 160rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  border: 1rpx solid $goat-border;
  color: rgba(255, 255, 255, 0.1);
  font-size: 26rpx;
  font-weight: 800;
  letter-spacing: 4rpx;
}

.listings__empty-tip {
  text-align: center;
  line-height: 1.6;
}

.listings__empty-btn {
  margin-top: 16rpx;
  width: 300rpx;
}

.listings__more,
.listings__end {
  padding: 32rpx 0;
  text-align: center;
  color: $goat-text-dim;
  font-size: 24rpx;
}

.listings__fab {
  position: fixed;
  right: 32rpx;
  bottom: calc(48rpx + env(safe-area-inset-bottom));
  height: 88rpx;
  padding: 0 36rpx;
  display: flex;
  align-items: center;
  border-radius: 999rpx;
  background-color: $goat-accent;
  color: $goat-accent-ink;
  font-size: 28rpx;
  font-weight: 700;
  box-shadow: 0 12rpx 32rpx rgba(216, 255, 62, 0.24);
}
</style>

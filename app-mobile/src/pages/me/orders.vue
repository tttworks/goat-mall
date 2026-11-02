<!--
  GOAT 潮玩交易平台 / GOAT 潮玩数据采集
  Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
  Author: Aloysius Luo
  本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
-->

<template>
  <view class="orders">
    <view class="orders__tabs">
      <view
        class="orders__tab"
        :class="{ 'orders__tab--active': role === 'buyer' }"
        @tap="switchRole('buyer')"
      >
        <text>我买到的</text>
      </view>
      <view
        class="orders__tab"
        :class="{ 'orders__tab--active': role === 'seller' }"
        @tap="switchRole('seller')"
      >
        <text>我卖出的</text>
      </view>
    </view>

    <view v-if="orders.length" class="orders__list">
      <view v-for="order in orders" :key="order.order_id" class="g-card order">
        <view class="g-row--between order__head">
          <text class="g-dim">{{ order.order_no }}</text>
          <text class="order__status" :class="`order__status--${statusOf(order).tone}`">
            {{ order.status_label }}
          </text>
        </view>

        <view v-for="item in order.items" :key="item.order_item_id" class="order__item">
          <image v-if="item.cover_url" class="order__cover" :src="item.cover_url" mode="aspectFill" />
          <view v-else class="order__cover order__cover--empty"><text>GOAT</text></view>
          <view class="g-flex-1">
            <text class="order__title g-clamp-2">{{ item.title }}</text>
            <text class="g-dim">¥{{ money(item.unit_price) }} × {{ item.quantity }}</text>
          </view>
        </view>

        <view class="g-row--between order__foot">
          <text class="g-dim">{{ order.shipping_company ? `${order.shipping_company} ${order.tracking_no}` : relativeTime(order.created_at) }}</text>
          <text class="g-price order__amount"><text class="g-price__symbol">¥</text>{{ money(order.payable_amount) }}</text>
        </view>

        <view v-if="actionOf(order) !== 'none'" class="order__actions">
          <view
            v-if="actionOf(order) === 'pay'"
            class="g-btn g-btn--ghost g-btn--sm"
            @tap="cancel(order)"
          >
            <text>取消订单</text>
          </view>
          <view
            v-if="actionOf(order) === 'ship'"
            class="g-btn g-btn--primary g-btn--sm"
            @tap="openShip(order)"
          >
            <text>发货</text>
          </view>
          <view
            v-if="actionOf(order) === 'pay'"
            class="g-btn g-btn--primary g-btn--sm"
            @tap="pay(order)"
          >
            <text>去支付</text>
          </view>
          <view
            v-if="actionOf(order) === 'confirm'"
            class="g-btn g-btn--primary g-btn--sm"
            @tap="confirmReceipt(order)"
          >
            <text>确认收货</text>
          </view>
          <view
            v-if="actionOf(order) === 'review'"
            class="g-btn g-btn--primary g-btn--sm"
            @tap="openReview(order)"
          >
            <text>评价卖家</text>
          </view>
        </view>

        <!-- 发货内联表单：不用弹窗，避免小程序端弹窗里再套输入框的层级问题 -->
        <view v-if="shipFor === order.order_id" class="order__form">
          <input v-model="shipForm.company" class="g-input" placeholder="快递公司，如 SF" placeholder-class="g-placeholder" :maxlength="32" />
          <input v-model="shipForm.tracking" class="g-input" placeholder="运单号" placeholder-class="g-placeholder" :maxlength="64" />
          <view class="g-row order__form-actions">
            <view class="g-btn g-btn--ghost g-btn--sm" @tap="shipFor = 0"><text>取消</text></view>
            <view class="g-btn g-btn--primary g-btn--sm" @tap="ship(order)"><text>确认发货</text></view>
          </view>
        </view>

        <view v-if="reviewFor === order.order_id" class="order__form">
          <view class="order__stars">
            <text
              v-for="star in 5"
              :key="star"
              class="order__star"
              :class="{ 'order__star--on': star <= reviewForm.rating }"
              @tap="reviewForm.rating = star"
            >★</text>
          </view>
          <input v-model="reviewForm.content" class="g-input" placeholder="说说这次交易体验" placeholder-class="g-placeholder" :maxlength="200" />
          <view class="g-row order__form-actions">
            <view class="g-btn g-btn--ghost g-btn--sm" @tap="reviewFor = 0"><text>取消</text></view>
            <view class="g-btn g-btn--primary g-btn--sm" @tap="submitReview(order)"><text>提交评价</text></view>
          </view>
        </view>
      </view>
    </view>

    <view v-else-if="!loading" class="g-empty">
      <text>{{ role === 'buyer' ? '还没有买过东西' : '还没有卖出记录' }}</text>
    </view>

    <view v-if="loading" class="g-loading"><text>加载中…</text></view>
  </view>
</template>

<script setup>
import { reactive, ref } from 'vue'
import { onLoad, onPullDownRefresh } from '@dcloudio/uni-app'
import { reviewOrder, cancelOrder, confirmOrder, listOrders, payOrder, shipOrder, simulatePayment } from '../../api/trade'
import { isLoggedIn } from '../../store/session'
import { money, orderStatus, relativeTime } from '../../utils/format'
import { confirmModal, toast } from '../../utils/ui'

const role = ref('buyer')
const orders = ref([])
const loading = ref(false)
const shipFor = ref(0)
const reviewFor = ref(0)
const shipForm = reactive({ company: '', tracking: '' })
const reviewForm = reactive({ rating: 5, content: '' })

function statusOf(order) {
  return orderStatus(order.status)
}

/**
 * 可执行动作按「角色 + 订单状态」判定：
 * 同一状态在买家侧和卖家侧能做的事完全不同（例如 paid：买家等发货，卖家要发货）。
 */
function actionOf(order) {
  const action = statusOf(order).action
  if (role.value === 'seller') {
    return action === 'ship' ? 'ship' : 'none'
  }

  if (action === 'review') return 'review'
  if (action === 'ship') return 'none'

  return action
}

async function load() {
  if (!isLoggedIn()) {
    uni.navigateTo({ url: '/pages/login/index' })
    return
  }

  loading.value = true
  try {
    const payload = await listOrders({ as: role.value, per_page: 20 })
    orders.value = payload.data || []
  } catch (e) {
    toast('订单加载失败')
  } finally {
    loading.value = false
  }
}

function switchRole(next) {
  if (role.value === next) return
  role.value = next
  orders.value = []
  shipFor.value = 0
  reviewFor.value = 0
  load()
}

/** 支付 = 发起支付单 + （沙箱）模拟渠道回调成功 */
async function pay(order) {
  try {
    const payment = await payOrder(order.order_id)
    const paymentId = payment.data?.payment_id

    if (payment.data?.channel === 'sandbox' && paymentId) {
      await simulatePayment(paymentId)
      toast('支付成功，资金已冻结')
    } else {
      toast('支付单已创建，请在收银台完成支付')
    }

    load()
  } catch (e) {
    // 请求层已提示
  }
}

async function cancel(order) {
  const ok = await confirmModal({ title: '取消订单', content: '取消后商品会重新上架，确认取消？' })
  if (!ok) return

  try {
    await cancelOrder(order.order_id, '买家取消')
    toast('订单已取消')
    load()
  } catch (e) {
    // 请求层已提示
  }
}

function openShip(order) {
  shipFor.value = order.order_id
  shipForm.company = ''
  shipForm.tracking = ''
}

async function ship(order) {
  if (!shipForm.company.trim() || !shipForm.tracking.trim()) {
    toast('请填写快递公司与运单号')
    return
  }

  try {
    await shipOrder(order.order_id, {
      shipping_company: shipForm.company.trim(),
      tracking_no: shipForm.tracking.trim()
    })
    shipFor.value = 0
    toast('已发货')
    load()
  } catch (e) {
    // 请求层已提示
  }
}

async function confirmReceipt(order) {
  const ok = await confirmModal({
    title: '确认收货',
    content: '确认已收到商品且无误？确认后货款将结算给卖家。',
    confirmText: '确认收货'
  })
  if (!ok) return

  try {
    await confirmOrder(order.order_id)
    toast('已确认收货，订单完成')
    load()
  } catch (e) {
    // 请求层已提示
  }
}

function openReview(order) {
  reviewFor.value = order.order_id
  reviewForm.rating = 5
  reviewForm.content = ''
}

async function submitReview(order) {
  try {
    await reviewOrder(order.order_id, { rating: reviewForm.rating, content: reviewForm.content.trim() })
    reviewFor.value = 0
    toast('评价已提交')
    load()
  } catch (e) {
    // 请求层已提示
  }
}

onLoad((options) => {
  if (options?.as === 'seller') role.value = 'seller'
  load()
})

onPullDownRefresh(async () => {
  await load()
  uni.stopPullDownRefresh()
})
</script>

<style lang="scss" scoped>
.orders {
  min-height: 100vh;
  padding: 24rpx;
}

.orders__tabs {
  display: flex;
  gap: 16rpx;
  margin-bottom: 24rpx;
}

.orders__tab {
  flex: 1;
  height: 80rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 999rpx;
  background-color: $goat-surface;
  border: 1rpx solid $goat-border;
  color: $goat-text-muted;
  font-size: 26rpx;
}

.orders__tab--active {
  background-color: $goat-accent;
  border-color: $goat-accent;
  color: $goat-accent-ink;
  font-weight: 600;
}

.orders__list {
  display: flex;
  flex-direction: column;
  gap: 20rpx;
}

.order__head {
  margin-bottom: 18rpx;
}

.order__status {
  font-size: 24rpx;
  font-weight: 600;
}

.order__status--accent {
  color: $goat-accent;
}

.order__status--normal {
  color: $goat-text-muted;
}

.order__status--dim {
  color: $goat-text-dim;
}

.order__status--danger {
  color: $goat-danger;
}

.order__item {
  display: flex;
  gap: 16rpx;
  margin-bottom: 16rpx;
}

.order__cover {
  width: 132rpx;
  height: 132rpx;
  border-radius: $goat-radius-sm;
  background-color: $goat-surface-2;
  flex-shrink: 0;
}

.order__cover--empty {
  display: flex;
  align-items: center;
  justify-content: center;
  color: rgba(255, 255, 255, 0.12);
  font-size: 20rpx;
}

.order__title {
  color: $goat-text;
  font-size: 26rpx;
  line-height: 1.45;
  margin-bottom: 8rpx;
}

.order__foot {
  padding-top: 12rpx;
  border-top: 1rpx solid $goat-border;
}

.order__amount {
  font-size: 34rpx;
}

.order__actions {
  display: flex;
  justify-content: flex-end;
  gap: 16rpx;
  margin-top: 20rpx;
}

.order__form {
  display: flex;
  flex-direction: column;
  gap: 16rpx;
  margin-top: 20rpx;
  padding: 20rpx;
  border-radius: $goat-radius;
  background-color: $goat-surface-2;
}

.order__form-actions {
  justify-content: flex-end;
  gap: 16rpx;
}

.order__stars {
  display: flex;
  gap: 12rpx;
}

.order__star {
  color: rgba(255, 255, 255, 0.18);
  font-size: 40rpx;
}

.order__star--on {
  color: $goat-accent;
}
</style>

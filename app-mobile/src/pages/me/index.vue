<!--
  GOAT 潮玩交易平台 / GOAT 潮玩数据采集
  Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
  Author: Aloysius Luo
  本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
-->

<template>
  <view class="me">
    <view v-if="session.user" class="g-card profile">
      <image
        v-if="session.user.avatar_url"
        class="profile__avatar"
        :src="session.user.avatar_url"
        mode="aspectFill"
      />
      <view v-else class="profile__avatar profile__avatar--empty">
        <text>{{ (session.user.nickname || session.user.username || 'G').slice(0, 1) }}</text>
      </view>
      <view class="g-flex-1">
        <text class="profile__name">{{ session.user.nickname || session.user.username }}</text>
        <text class="g-dim">@{{ session.user.username }} · {{ roleLabel }}</text>
      </view>
    </view>

    <view v-else class="g-card profile">
      <view class="profile__avatar profile__avatar--empty"><text>G</text></view>
      <view class="g-flex-1">
        <text class="profile__name">未登录</text>
        <text class="g-dim">登录后可下单、收藏与私信</text>
      </view>
      <view class="g-btn g-btn--primary g-btn--sm" @tap="goLogin"><text>登录</text></view>
    </view>

    <view class="g-card entries">
      <view class="entry" @tap="go('/pages/me/orders?as=buyer')">
        <text class="entry__icon">◈</text>
        <text class="entry__label">我的订单</text>
        <text class="entry__arrow">›</text>
      </view>
      <view class="entry" @tap="go('/pages/me/orders?as=seller')">
        <text class="entry__icon">◐</text>
        <text class="entry__label">我卖出的</text>
        <text class="entry__arrow">›</text>
      </view>
      <view class="entry" @tap="go('/pages/cart/index')">
        <text class="entry__icon">▤</text>
        <text class="entry__label">购物车</text>
        <text class="entry__arrow">›</text>
      </view>
      <view class="entry" @tap="go('/pages/me/favorites')">
        <text class="entry__icon">★</text>
        <text class="entry__label">我的收藏</text>
        <text class="entry__arrow">›</text>
      </view>
      <view class="entry" @tap="go('/pages/me/addresses')">
        <text class="entry__icon">⌂</text>
        <text class="entry__label">收货地址</text>
        <text class="entry__arrow">›</text>
      </view>
      <view class="entry" @tap="go('/pages/me/refunds')">
        <text class="entry__icon">↺</text>
        <text class="entry__label">退款/售后</text>
        <text class="entry__arrow">›</text>
      </view>
      <view class="entry" @tap="go('/pages/community/mine')">
        <text class="entry__icon">◇</text>
        <text class="entry__label">我的帖子</text>
        <text class="entry__arrow">›</text>
      </view>
    </view>

    <view class="g-card entries">
      <view class="entry" @tap="goSeller">
        <text class="entry__icon">⚑</text>
        <text class="entry__label">卖家中心</text>
        <text class="entry__arrow">›</text>
      </view>
      <view class="entry" @tap="go('/pages/identity/index')">
        <text class="entry__icon">☑</text>
        <text class="entry__label">实名认证</text>
        <text class="entry__arrow">›</text>
      </view>
      <view class="entry" @tap="goAuctions">
        <text class="entry__icon">⏱</text>
        <text class="entry__label">竞拍专场</text>
        <text class="entry__arrow">›</text>
      </view>
      <view class="entry" @tap="go('/pages/im/index')">
        <text class="entry__icon">✎</text>
        <text class="entry__label">私信</text>
        <text class="entry__arrow">›</text>
      </view>
      <view class="entry" @tap="go('/pages/me/notifications')">
        <text class="entry__icon">✉</text>
        <text class="entry__label">通知</text>
        <view v-if="unread" class="entry__badge"><text>{{ unread }}</text></view>
        <text class="entry__arrow">›</text>
      </view>
    </view>

    <view v-if="session.user" class="g-btn g-btn--ghost me__logout" @tap="doLogout">
      <text>退出登录</text>
    </view>

    <text class="me__version">GOAT 潮玩 v1.0.0</text>
  </view>
</template>

<script setup>
import { ref } from 'vue'
import { onShow, onUnload } from '@dcloudio/uni-app'
import { logout as logoutApi } from '../../api/auth'
import { listNotifications } from '../../api/social'
import { onUserEvent } from '../../realtime/client'
import { isLoggedIn, session, signOut } from '../../store/session'
import { confirmModal, toast } from '../../utils/ui'

const unread = ref(0)
let offRealtime = null

const ROLE_LABELS = { buyer: '买家', seller: '卖家', admin: '平台运营' }

function roleLabel() {
  return ROLE_LABELS[session.user?.role] || '玩家'
}

function go(url) {
  if (!requireAuth()) return
  uni.navigateTo({ url })
}

function goLogin() {
  uni.navigateTo({ url: '/pages/login/index' })
}

function goSeller() {
  if (!requireAuth()) return
  uni.navigateTo({ url: '/pages/seller/index' })
}

/** 竞拍是浏览型页面，未登录也能看，登录只在出价时才要求 */
function goAuctions() {
  uni.navigateTo({ url: '/pages/auction/index' })
}

function requireAuth() {
  if (isLoggedIn()) return true

  goLogin()
  return false
}

async function loadUnread() {
  if (!isLoggedIn()) {
    unread.value = 0
    return
  }

  try {
    const payload = await listNotifications({ per_page: 1 })
    unread.value = payload.unread_count ?? 0
  } catch (e) {
    unread.value = 0
  }
}

async function doLogout() {
  const ok = await confirmModal({ title: '退出登录', content: '确认退出当前账号？' })
  if (!ok) return

  try {
    await logoutApi()
  } catch (e) {
    // 即便服务端撤销失败，本地也要退出，不能让用户卡在「退不掉」的状态
  }

  signOut()
  unread.value = 0
  toast('已退出登录')
}

/** 角标实时化（T6）：服务端推送自带 unread_count，不必再打一次列表接口 */
function listenRealtime() {
  const userId = session.user?.user_id
  if (!userId || offRealtime) return

  offRealtime = onUserEvent(userId, (event, data) => {
    if (event !== 'notify.created') return
    unread.value = Number(data?.unread_count ?? unread.value + 1)
  })
}

onShow(() => {
  loadUnread()
  listenRealtime()
})

onUnload(() => {
  if (offRealtime) {
    offRealtime()
    offRealtime = null
  }
})
</script>

<style lang="scss" scoped>
.me {
  min-height: 100vh;
  padding: 24rpx 24rpx 48rpx;
}

.profile {
  display: flex;
  align-items: center;
  gap: 20rpx;
}

.profile__avatar {
  width: 96rpx;
  height: 96rpx;
  border-radius: 50%;
  background-color: $goat-surface-2;
  flex-shrink: 0;
}

.profile__avatar--empty {
  display: flex;
  align-items: center;
  justify-content: center;
  color: $goat-accent;
  font-size: 36rpx;
  font-weight: 700;
}

.profile__name {
  display: block;
  color: $goat-text;
  font-size: 32rpx;
  font-weight: 600;
  margin-bottom: 6rpx;
}

.entries {
  margin-top: 24rpx;
  padding: 8rpx 24rpx;
}

.entry {
  display: flex;
  align-items: center;
  gap: 20rpx;
  height: 104rpx;
  border-bottom: 1rpx solid $goat-border;
}

.entry:last-child {
  border-bottom: none;
}

.entry__icon {
  width: 40rpx;
  color: $goat-accent;
  font-size: 28rpx;
}

.entry__label {
  flex: 1;
  color: $goat-text;
  font-size: 28rpx;
}

.entry__badge {
  min-width: 36rpx;
  height: 36rpx;
  padding: 0 10rpx;
  border-radius: 999rpx;
  background-color: $goat-accent;
  color: $goat-accent-ink;
  font-size: 20rpx;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
}

.entry__arrow {
  color: $goat-text-dim;
  font-size: 32rpx;
}

.me__logout {
  margin-top: 40rpx;
}

.me__version {
  display: block;
  margin-top: 48rpx;
  text-align: center;
  color: $goat-text-dim;
  font-size: 22rpx;
}
</style>

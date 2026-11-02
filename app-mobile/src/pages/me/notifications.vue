<!--
  GOAT 潮玩交易平台 / GOAT 潮玩数据采集
  Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
  Author: Aloysius Luo
  本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
-->

<template>
  <view class="notices">
    <view class="g-row--between notices__head">
      <text class="g-muted">{{ unread ? `${unread} 条未读` : '没有未读通知' }}</text>
      <view v-if="unread" class="g-btn g-btn--sm g-btn--ghost" @tap="markAll">
        <text>全部已读</text>
      </view>
    </view>

    <view v-if="notices.length" class="notices__list">
      <view
        v-for="item in notices"
        :key="item.notification_id"
        class="g-card notice"
        :class="{ 'notice--unread': !item.read_at }"
        @tap="markRead(item)"
      >
        <view class="g-row--between">
          <text class="notice__title">{{ item.title }}</text>
          <text class="g-dim">{{ relativeTime(item.created_at) }}</text>
        </view>
        <text class="notice__body">{{ item.body }}</text>
      </view>
    </view>

    <view v-else-if="!loading" class="g-empty"><text>还没有通知</text></view>
    <view v-if="loading" class="g-loading"><text>加载中…</text></view>
  </view>
</template>

<script setup>
import { ref } from 'vue'
import { onPullDownRefresh } from '@dcloudio/uni-app'
import { listNotifications, readAllNotifications, readNotification } from '../../api/social'
import { relativeTime } from '../../utils/format'
import { toast } from '../../utils/ui'

const notices = ref([])
const unread = ref(0)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const payload = await listNotifications({ per_page: 30 })
    notices.value = payload.data || []
    unread.value = payload.unread_count ?? 0
  } catch (e) {
    toast('通知加载失败')
  } finally {
    loading.value = false
  }
}

async function markRead(item) {
  if (item.read_at) return

  try {
    await readNotification(item.notification_id)
    item.read_at = new Date().toISOString()
    unread.value = Math.max(0, unread.value - 1)
  } catch (e) {
    // 请求层已提示
  }
}

async function markAll() {
  try {
    await readAllNotifications()
    notices.value = notices.value.map((item) => ({
      ...item,
      read_at: item.read_at || new Date().toISOString()
    }))
    unread.value = 0
    toast('已全部标记为已读')
  } catch (e) {
    // 请求层已提示
  }
}

onPullDownRefresh(async () => {
  await load()
  uni.stopPullDownRefresh()
})

load()
</script>

<style lang="scss" scoped>
.notices {
  min-height: 100vh;
  padding: 24rpx;
}

.notices__head {
  margin-bottom: 20rpx;
  padding: 0 8rpx;
}

.notices__list {
  display: flex;
  flex-direction: column;
  gap: 16rpx;
}

.notice {
  border-left: 6rpx solid transparent;
}

.notice--unread {
  border-left-color: $goat-accent;
}

.notice__title {
  color: $goat-text;
  font-size: 28rpx;
  font-weight: 600;
}

.notice__body {
  display: block;
  margin-top: 10rpx;
  color: $goat-text-muted;
  font-size: 24rpx;
  line-height: 1.6;
}
</style>

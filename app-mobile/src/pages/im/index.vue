<!--
  GOAT 潮玩交易平台 / GOAT 潮玩数据采集
  Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
  Author: Aloysius Luo
  本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
-->

<template>
  <view class="chats">
    <view v-if="!authed" class="g-empty">
      <text>登录后查看私信</text>
      <view class="g-btn g-btn--sm empty__btn" @tap="goLogin"><text>去登录</text></view>
    </view>

    <block v-else>
      <view v-if="rows.length" class="chats__list">
        <view v-for="row in rows" :key="row.conversation_id" class="g-card chat" @tap="openChat(row)">
          <image v-if="row.peer?.avatar_url" class="chat__avatar" :src="row.peer.avatar_url" mode="aspectFill" />
          <view v-else class="chat__avatar chat__avatar--empty">
            <text>{{ initial(row.peer?.nickname) }}</text>
          </view>

          <view class="chat__body">
            <view class="g-row--between">
              <text class="chat__name">{{ row.peer?.nickname || `用户 #${row.peer?.user_id}` }}</text>
              <text class="g-dim">{{ relativeTime(row.last_message_at) }}</text>
            </view>
            <view class="g-row--between chat__meta">
              <text class="chat__preview">{{ preview(row) }}</text>
              <text v-if="row.unread_count" class="chat__badge">{{ row.unread_count }}</text>
            </view>
          </view>
        </view>
      </view>

      <view v-else-if="!loading" class="g-empty">
        <text>还没有私信</text>
        <text class="g-dim empty__hint">在商品详情点「私信卖家」就能开聊，交易全程平台担保</text>
      </view>

      <view v-if="loading" class="g-loading"><text>加载中…</text></view>
    </block>
  </view>
</template>

<script setup>
import { ref } from 'vue'
import { onPullDownRefresh, onShow } from '@dcloudio/uni-app'
import { listConversations } from '../../api/social'
import { isLoggedIn } from '../../store/session'
import { relativeTime } from '../../utils/format'

const rows = ref([])
const loading = ref(false)
const authed = ref(isLoggedIn())

function initial(name) {
  return String(name || '?').slice(0, 1).toUpperCase()
}

function preview(row) {
  return row.last_message_preview || '还没有消息'
}

async function load() {
  authed.value = isLoggedIn()

  if (!authed.value) {
    rows.value = []
    return
  }

  loading.value = true

  try {
    const payload = await listConversations({ per_page: 30 })
    rows.value = payload?.data || []
  } catch (e) {
    // 请求层已提示，保留上一次列表
  } finally {
    loading.value = false
  }
}

function openChat(row) {
  const peer = row.peer?.user_id

  uni.navigateTo({ url: `/pages/im/chat?id=${row.conversation_id}&peer=${peer}` })
}

function goLogin() {
  uni.navigateTo({ url: '/pages/login/index' })
}

onShow(load)

onPullDownRefresh(async () => {
  await load()
  uni.stopPullDownRefresh()
})
</script>

<style lang="scss" scoped>
.chats {
  min-height: 100vh;
  padding: 24rpx;
}

.chats__list {
  display: flex;
  flex-direction: column;
  gap: 16rpx;
}

.chat {
  display: flex;
  gap: 20rpx;
  align-items: center;
}

.chat__avatar {
  width: 88rpx;
  height: 88rpx;
  border-radius: 50%;
  background-color: $goat-surface-2;
  flex-shrink: 0;
}

.chat__avatar--empty {
  display: flex;
  align-items: center;
  justify-content: center;
  color: $goat-accent;
  font-size: 32rpx;
  font-weight: 700;
}

.chat__body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 10rpx;
}

.chat__name {
  color: $goat-text;
  font-size: 28rpx;
  font-weight: 600;
}

.chat__meta {
  align-items: center;
}

.chat__preview {
  flex: 1;
  min-width: 0;
  color: $goat-text-muted;
  font-size: 24rpx;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.chat__badge {
  min-width: 36rpx;
  height: 36rpx;
  padding: 0 8rpx;
  border-radius: 18rpx;
  background-color: $goat-danger;
  color: #fff;
  font-size: 20rpx;
  text-align: center;
  line-height: 36rpx;
}

.empty__btn {
  margin-top: 24rpx;
}

.empty__hint {
  margin-top: 12rpx;
  font-size: 24rpx;
  text-align: center;
}
</style>

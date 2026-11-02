<!--
  GOAT 潮玩交易平台 / GOAT 潮玩数据采集
  Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
  Author: Aloysius Luo
  本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
-->

<template>
  <view class="chat">
    <scroll-view
      class="chat__scroll"
      scroll-y
      :scroll-into-view="anchor"
      :scroll-with-animation="true"
    >
      <view class="chat__list">
        <view
          v-for="message in messages"
          :key="message.message_id"
          :id="`msg-${message.message_id}`"
          class="chat__row"
          :class="{ 'chat__row--mine': message.is_mine }"
        >
          <view class="chat__bubble" :class="{ 'chat__bubble--mine': message.is_mine }">
            <text class="chat__text">{{ message.body }}</text>
          </view>
          <text class="chat__time">{{ relativeTime(message.created_at) }}</text>
        </view>
      </view>
      <view id="chat-bottom" class="chat__bottom" />
    </scroll-view>

    <view class="chat__composer">
      <input
        v-model="draft"
        class="g-input chat__input"
        placeholder="说点什么…"
        placeholder-class="g-placeholder"
        confirm-type="send"
        :maxlength="500"
        @confirm="send"
      />
      <view
        class="g-btn g-btn--primary g-btn--sm chat__send"
        :class="{ 'g-btn--disabled': sending || !draft.trim() }"
        @tap="send"
      >
        <text>发送</text>
      </view>
    </view>
  </view>
</template>

<script setup>
import { nextTick, ref } from 'vue'
import { onLoad, onPullDownRefresh } from '@dcloudio/uni-app'
import { listMessages, sendMessage } from '../../api/social'
import { relativeTime } from '../../utils/format'
import { toast } from '../../utils/ui'

const conversationId = ref(0)
const messages = ref([])
const draft = ref('')
const sending = ref(false)
const anchor = ref('')

async function load() {
  if (!conversationId.value) return

  try {
    const payload = await listMessages(conversationId.value, { per_page: 50 })
    messages.value = payload.data || []
    scrollToBottom()
  } catch (e) {
    toast('会话不存在或无权查看')
  }
}

function scrollToBottom() {
  // 拉取历史会同时把这批消息置为已读，所以列表刷新 = 未读清零
  nextTick(() => {
    anchor.value = ''
    nextTick(() => {
      anchor.value = 'chat-bottom'
    })
  })
}

async function send() {
  const body = draft.value.trim()
  if (!body || sending.value) return

  sending.value = true
  try {
    const payload = await sendMessage(conversationId.value, body)
    messages.value.push(payload.data)
    draft.value = ''
    scrollToBottom()
  } catch (e) {
    // 请求层已提示
  } finally {
    sending.value = false
  }
}

onLoad((options) => {
  conversationId.value = Number(options?.id || 0)
  if (!conversationId.value) {
    toast('缺少会话参数')
    return
  }

  if (options?.title) {
    uni.setNavigationBarTitle({ title: decodeURIComponent(options.title) })
  }

  load()
})

onPullDownRefresh(async () => {
  await load()
  uni.stopPullDownRefresh()
})
</script>

<style lang="scss" scoped>
.chat {
  display: flex;
  flex-direction: column;
  height: 100vh;
  background-color: $goat-bg;
}

.chat__scroll {
  flex: 1;
  min-height: 0;
}

.chat__list {
  padding: 24rpx;
}

.chat__row {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  margin-bottom: 28rpx;
}

.chat__row--mine {
  align-items: flex-end;
}

.chat__bubble {
  max-width: 76%;
  padding: 18rpx 24rpx;
  border-radius: $goat-radius;
  border-top-left-radius: 8rpx;
  background-color: $goat-surface;
  border: 1rpx solid $goat-border;
}

.chat__bubble--mine {
  background-color: $goat-accent;
  border-color: $goat-accent;
  border-top-left-radius: $goat-radius;
  border-top-right-radius: 8rpx;
}

.chat__text {
  color: $goat-text;
  font-size: 28rpx;
  line-height: 1.55;
  word-break: break-all;
}

.chat__bubble--mine .chat__text {
  color: $goat-accent-ink;
}

.chat__time {
  margin-top: 8rpx;
  color: $goat-text-dim;
  font-size: 20rpx;
}

.chat__bottom {
  height: 8rpx;
}

.chat__composer {
  display: flex;
  align-items: center;
  gap: 16rpx;
  padding: 16rpx 24rpx calc(16rpx + env(safe-area-inset-bottom));
  border-top: 1rpx solid $goat-border;
  background-color: $goat-surface;
}

.chat__input {
  flex: 1;
  height: 80rpx;
}

.chat__send {
  flex-shrink: 0;
}
</style>

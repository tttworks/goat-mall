<!--
  GOAT 潮玩交易平台 / GOAT 潮玩数据采集
  Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
  Author: Aloysius Luo
  本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
-->

<template>
  <view class="community">
    <view class="g-card composer">
      <textarea
        v-model="draft"
        class="composer__input"
        placeholder="晒晒刚到的货，或者聊聊这只的涂装…"
        placeholder-class="g-placeholder"
        :maxlength="1000"
        auto-height
      />
      <view class="g-row--between composer__footer">
        <text class="g-dim">{{ draft.length }}/1000 · 发布后需过机审</text>
        <view
          class="g-btn g-btn--primary g-btn--sm"
          :class="{ 'g-btn--disabled': publishing || !draft.trim() }"
          @tap="publish"
        >
          <text>{{ publishing ? '发布中…' : '发布' }}</text>
        </view>
      </view>
    </view>

    <view v-if="posts.length" class="posts">
      <view v-for="post in posts" :key="post.post_id" class="g-card post">
        <view class="post__head">
          <image v-if="post.author?.avatar_url" class="post__avatar" :src="post.author.avatar_url" mode="aspectFill" />
          <view v-else class="post__avatar post__avatar--empty">
            <text>{{ (post.author?.nickname || post.author?.username || 'G').slice(0, 1) }}</text>
          </view>
          <view class="g-flex-1">
            <text class="post__name">{{ post.author?.nickname || post.author?.username || '匿名玩家' }}</text>
            <text class="g-dim">{{ relativeTime(post.created_at) }}</text>
          </view>
        </view>

        <text class="post__content">{{ post.content }}</text>

        <view v-if="post.images?.length" class="post__images">
          <image
            v-for="(url, index) in post.images"
            :key="index"
            class="post__image"
            :src="url"
            mode="aspectFill"
            @tap="preview(post.images, index)"
          />
        </view>
      </view>
    </view>

    <view v-else-if="!loading" class="g-empty">
      <text>还没有帖子，来发第一条</text>
    </view>

    <view v-if="loading" class="g-loading"><text>加载中…</text></view>
    <view v-else-if="posts.length && !hasMore" class="g-loading"><text>已经到底了</text></view>
  </view>
</template>

<script setup>
import { ref } from 'vue'
import { onPullDownRefresh, onReachBottom } from '@dcloudio/uni-app'
import { createPost, listPosts } from '../../api/social'
import { requireLogin } from '../../store/session'
import { relativeTime } from '../../utils/format'
import { toast } from '../../utils/ui'

const posts = ref([])
const draft = ref('')
const publishing = ref(false)
const loading = ref(false)
const hasMore = ref(true)
const page = ref(1)

async function load(reset = false) {
  if (loading.value) return
  if (!reset && !hasMore.value) return

  loading.value = true
  try {
    const payload = await listPosts({ page: reset ? 1 : page.value, per_page: 10 })
    const rows = payload.data || []
    posts.value = reset ? rows : posts.value.concat(rows)

    const total = payload.meta?.total ?? 0
    hasMore.value = posts.value.length < total
    page.value = reset ? 2 : page.value + 1
  } catch (e) {
    toast('社区内容加载失败')
  } finally {
    loading.value = false
  }
}

/**
 * 发布结果三种：approved（机审通过直接上墙）、pending（转人工）、rejected（命中词表）。
 * 三种都要给明确反馈——用户最怕的是「点了发布什么都没发生」。
 */
async function publish() {
  if (!requireLogin()) return
  if (!draft.value.trim() || publishing.value) return

  publishing.value = true
  try {
    const payload = await createPost({ content: draft.value.trim() })
    const status = payload.data?.status

    if (status === 'approved') {
      posts.value.unshift(payload.data)
      draft.value = ''
      toast('发布成功')
    } else if (status === 'pending') {
      draft.value = ''
      toast('已提交，审核通过后展示')
    } else {
      toast(payload.data?.reject_reason || '内容未通过机审，请修改后重试')
    }
  } catch (e) {
    // 命中敏感词时后端返回 422 + reject_reason，请求层已 Toast
  } finally {
    publishing.value = false
  }
}

function preview(urls, index) {
  uni.previewImage({ urls, current: urls[index] })
}

onPullDownRefresh(async () => {
  hasMore.value = true
  page.value = 1
  await load(true)
  uni.stopPullDownRefresh()
})

onReachBottom(() => load(false))

load(true)
</script>

<style lang="scss" scoped>
.community {
  min-height: 100vh;
  padding: 24rpx 24rpx 48rpx;
}

.composer {
  display: flex;
  flex-direction: column;
  gap: 16rpx;
  margin-bottom: 28rpx;
}

.composer__input {
  width: 100%;
  min-height: 140rpx;
  color: $goat-text;
  font-size: 28rpx;
  line-height: 1.6;
}

.composer__footer {
  align-items: flex-end;
}

.posts {
  display: flex;
  flex-direction: column;
  gap: 20rpx;
}

.post__head {
  display: flex;
  align-items: center;
  gap: 16rpx;
  margin-bottom: 18rpx;
}

.post__avatar {
  width: 72rpx;
  height: 72rpx;
  border-radius: 50%;
  background-color: $goat-surface-2;
  flex-shrink: 0;
}

.post__avatar--empty {
  display: flex;
  align-items: center;
  justify-content: center;
  color: $goat-accent;
  font-size: 28rpx;
  font-weight: 700;
}

.post__name {
  display: block;
  color: $goat-text;
  font-size: 26rpx;
  font-weight: 600;
}

.post__content {
  display: block;
  color: $goat-text;
  font-size: 28rpx;
  line-height: 1.7;
  white-space: pre-wrap;
  word-break: break-all;
}

.post__images {
  display: flex;
  flex-wrap: wrap;
  gap: 10rpx;
  margin-top: 18rpx;
}

.post__image {
  width: 200rpx;
  height: 200rpx;
  border-radius: $goat-radius-sm;
  background-color: $goat-surface-2;
}
</style>

<!--
  GOAT 潮玩交易平台 / GOAT 潮玩数据采集
  Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
  Author: Aloysius Luo
  本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
-->

<template>
  <view class="mine">
    <scroll-view class="mine__tabs" scroll-x>
      <view class="mine__tabs-inner">
        <view
          v-for="tab in tabs"
          :key="tab.value || 'all'"
          class="mine__tab"
          :class="{ 'mine__tab--active': status === tab.value }"
          @tap="switchTab(tab.value)"
        >
          <text>{{ tab.label }}</text>
        </view>
      </view>
    </scroll-view>

    <view v-for="post in posts" :key="post.post_id" class="g-card post" @tap="openPost(post)">
      <view class="post__head">
        <text class="post__status" :class="`post__status--${post.status}`">{{ statusLabel(post.status) }}</text>
        <text class="g-dim">{{ relativeTime(post.created_at) }}</text>
      </view>

      <text v-if="post.content" class="post__content">{{ post.content }}</text>

      <view v-if="post.images?.length" class="post__images">
        <image v-for="(url, index) in post.images.slice(0, 3)" :key="index" class="post__image" :src="url" mode="aspectFill" />
        <text v-if="post.images.length > 3" class="post__more">+{{ post.images.length - 3 }}</text>
      </view>

      <view v-if="post.reject_reason" class="post__reason">
        <text class="g-dim">未通过原因：{{ post.reject_reason }}</text>
      </view>

      <view class="post__foot">
        <text class="g-dim">赞 {{ post.likes_count || 0 }} · 评论 {{ post.comments_count || 0 }}</text>
        <text v-if="canWithdraw(post)" class="post__withdraw" @tap.stop="withdraw(post)">撤回</text>
      </view>
    </view>

    <view v-if="!posts.length && !loading" class="g-empty">
      <text>{{ status ? '这个状态下还没有帖子' : '还没发过帖子，去社群晒一张' }}</text>
    </view>

    <view v-if="loading" class="g-loading"><text>加载中…</text></view>
    <view v-else-if="posts.length && !hasMore" class="g-loading"><text>已经到底了</text></view>
  </view>
</template>

<script setup>
import { ref } from 'vue'
import { onPullDownRefresh, onReachBottom, onShow } from '@dcloudio/uni-app'
import { listMyPosts, withdrawPost } from '../../api/social'
import { isLoggedIn } from '../../store/session'
import { relativeTime } from '../../utils/format'
import { confirmModal, toast } from '../../utils/ui'

/**
 * 我的帖子：五个状态分 tab。
 *
 * 状态筛选交给服务端（status 参数），前端只负责切换与翻页——
 * 拉全量再本地筛会在分页交界处漏帖。
 */
const tabs = [
  { label: '全部', value: '' },
  { label: '审核中', value: 'pending' },
  { label: '已发布', value: 'approved' },
  { label: '未通过', value: 'rejected' },
  { label: '已撤回', value: 'withdrawn' }
]

const STATUS_LABELS = {
  pending: '审核中',
  approved: '已发布',
  rejected: '未通过',
  withdrawn: '已撤回'
}

const posts = ref([])
const status = ref('')
const loading = ref(false)
const hasMore = ref(true)
const page = ref(1)

onShow(() => {
  if (!isLoggedIn()) {
    uni.navigateTo({ url: '/pages/login/index' })
    return
  }

  // 从详情页点赞/评论后返回，列表里的计数要跟着变，所以每次显示都重拉第一页
  if (!loading.value) load(true)
})

onPullDownRefresh(async () => {
  await load(true)
  uni.stopPullDownRefresh()
})

onReachBottom(() => load(false))

function statusLabel(value) {
  return STATUS_LABELS[value] || value
}

/** 撤回只对「已发布 / 审核中」有意义；未通过与已撤回的再撤回只是噪音 */
function canWithdraw(post) {
  return post.status === 'approved' || post.status === 'pending'
}

function switchTab(value) {
  if (status.value === value) return

  status.value = value
  posts.value = []
  hasMore.value = true
  page.value = 1
  load(true)
}

async function load(reset = false) {
  if (loading.value) return
  if (!reset && !hasMore.value) return

  loading.value = true
  try {
    const target = reset ? 1 : page.value + 1
    const params = { page: target, per_page: 20 }
    if (status.value) params.status = status.value

    const payload = await listMyPosts(params)
    const rows = payload.data || []

    posts.value = reset ? rows : posts.value.concat(rows)
    page.value = target

    const meta = payload.meta || {}
    hasMore.value = target * Number(meta.per_page || 20) < Number(meta.total || 0)
  } catch (e) {
    // 请求层已提示
  } finally {
    loading.value = false
  }
}

function openPost(post) {
  uni.navigateTo({ url: `/pages/community/post?id=${post.post_id}` })
}

async function withdraw(post) {
  const ok = await confirmModal({
    title: '撤回这条帖子？',
    content: '撤回后不再公开展示，仅自己可见',
    confirmText: '撤回'
  })
  if (!ok) return

  try {
    const payload = await withdrawPost(post.post_id)
    post.status = payload.data?.status || 'withdrawn'
    toast('已撤回')
  } catch (e) {
    // 请求层已提示
  }
}
</script>

<style lang="scss" scoped>
.mine {
  min-height: 100vh;
  padding: 0 24rpx 48rpx;
}

.mine__tabs {
  position: sticky;
  top: 0;
  z-index: 5;
  margin: 0 -24rpx 20rpx;
  background-color: $goat-bg;
}

.mine__tabs-inner {
  display: flex;
  gap: 32rpx;
  padding: 20rpx 24rpx;
}

.mine__tab {
  flex-shrink: 0;
  padding-bottom: 8rpx;
  color: $goat-text-dim;
  font-size: 26rpx;
  border-bottom: 4rpx solid transparent;
}

.mine__tab--active {
  color: $goat-text;
  font-weight: 600;
  border-bottom-color: $goat-accent;
}

.post__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12rpx;
}

.post__status {
  padding: 4rpx 12rpx;
  border-radius: 999rpx;
  font-size: 22rpx;
}

.post__status--approved {
  color: $goat-accent;
  background-color: rgba(216, 255, 62, 0.12);
}

.post__status--pending {
  color: #e0b64a;
  background-color: rgba(224, 182, 74, 0.12);
}

.post__status--rejected {
  color: #e05a5a;
  background-color: rgba(224, 90, 90, 0.12);
}

.post__status--withdrawn {
  color: $goat-text-dim;
  background-color: $goat-surface-2;
}

.post__content {
  display: block;
  color: $goat-text;
  font-size: 28rpx;
  line-height: 1.6;
  word-break: break-all;
}

.post__images {
  position: relative;
  display: flex;
  gap: 12rpx;
  margin-top: 16rpx;
}

.post__image {
  width: 180rpx;
  height: 180rpx;
  border-radius: $goat-radius-sm;
  background-color: $goat-surface-2;
}

.post__more {
  position: absolute;
  right: 0;
  bottom: 8rpx;
  padding: 2rpx 10rpx;
  border-radius: 999rpx;
  background-color: rgba(11, 11, 12, 0.7);
  color: $goat-text;
  font-size: 22rpx;
}

.post__reason {
  margin-top: 12rpx;
}

.post__foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 16rpx;
  padding-top: 16rpx;
  border-top: 1rpx solid $goat-border;
}

.post__withdraw {
  color: $goat-text-dim;
  font-size: 24rpx;
}
</style>

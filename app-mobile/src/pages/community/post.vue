<!--
  GOAT 潮玩交易平台 / GOAT 潮玩数据采集
  Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
  Author: Aloysius Luo
  本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
-->

<template>
  <view class="post-page">
    <view v-if="post" class="g-card post">
      <view class="post__head">
        <image v-if="post.author?.avatar_url" class="post__avatar" :src="post.author.avatar_url" mode="aspectFill" />
        <view v-else class="post__avatar post__avatar--empty">
          <text>{{ (post.author?.nickname || post.author?.username || 'G').slice(0, 1) }}</text>
        </view>
        <view class="g-flex-1">
          <text class="post__name">{{ post.author?.nickname || post.author?.username || '匿名玩家' }}</text>
          <text class="g-dim">{{ relativeTime(post.created_at) }}</text>
        </view>
        <text v-if="canReport" class="post__report" @tap="report">举报</text>
      </view>

      <text v-if="post.content" class="post__content">{{ post.content }}</text>

      <view v-if="post.images?.length" class="post__images">
        <image
          v-for="(url, index) in post.images"
          :key="index"
          class="post__image"
          :src="url"
          mode="widthFix"
          @tap="previewImage(index)"
        />
      </view>

      <view v-if="post.status !== 'approved'" class="post__banner">
        <text class="g-dim">{{ statusHint }}</text>
      </view>

      <view class="post__actions">
        <view class="post__action" :class="{ 'post__action--on': post.liked }" @tap="toggleLike">
          <text class="post__action-icon">{{ post.liked ? '♥' : '♡' }}</text>
          <text class="post__action-count">{{ post.likes_count || 0 }}</text>
        </view>
        <view class="post__action">
          <text class="post__action-label">评论</text>
          <text class="post__action-count">{{ post.comments_count || 0 }}</text>
        </view>
      </view>
    </view>

    <view class="g-card comments">
      <text class="comments__title">评论 {{ post?.comments_count || 0 }}</text>

      <view v-for="item in comments" :key="item.comment_id" class="comment">
        <view class="comment__head">
          <text class="comment__name">{{ item.author?.nickname || item.author?.username || '匿名玩家' }}</text>
          <text class="g-dim">{{ relativeTime(item.created_at) }}</text>
          <text v-if="isMine(item)" class="comment__delete" @tap="removeComment(item)">删除</text>
        </view>
        <text class="comment__content">{{ item.content }}</text>
      </view>

      <view v-if="!comments.length && !loadingComments" class="g-dim comments__empty">
        <text>还没有评论，来说第一句</text>
      </view>

      <view v-if="comments.length && hasMoreComments" class="comments__more" @tap="loadComments(false)">
        <text class="g-dim">{{ loadingComments ? '加载中…' : '查看更多评论' }}</text>
      </view>
    </view>

    <view class="comment-bar">
      <input
        v-model="draft"
        class="comment-bar__input"
        placeholder="友好交流，说点有用的"
        placeholder-class="g-placeholder"
        :maxlength="500"
        confirm-type="send"
        @confirm="submitComment"
      />
      <view
        class="g-btn g-btn--primary g-btn--sm"
        :class="{ 'g-btn--disabled': submitting || !draft.trim() }"
        @tap="submitComment"
      >
        <text>{{ submitting ? '发送中' : '发送' }}</text>
      </view>
    </view>
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onLoad, onPullDownRefresh } from '@dcloudio/uni-app'
import {
  createComment,
  deleteComment,
  getPost,
  likePost,
  listComments,
  unlikePost
} from '../../api/social'
import { requireLogin, session } from '../../store/session'
import { relativeTime } from '../../utils/format'
import { back, confirmModal, toast } from '../../utils/ui'

/**
 * 帖子详情 + 评论。
 *
 * 评论与点赞都按服务端返回刷新，不做本地乐观累加：
 * 机审「审核中」的评论不会出现在列表里，乐观插入会让人以为发出去又被吞了。
 */
const postId = ref(0)
const post = ref(null)
const comments = ref([])
const draft = ref('')
const submitting = ref(false)
const loadingComments = ref(false)
const liking = ref(false)
const hasMoreComments = ref(false)
const commentPage = ref(1)

const meId = computed(() => Number(session.user?.user_id || 0))

const canReport = computed(() => {
  if (!post.value || !meId.value) return false
  return Number(post.value.author?.user_id) !== meId.value
})

const statusHint = computed(() => {
  const map = {
    pending: '审核中，仅自己可见',
    rejected: '未通过审核，仅自己可见',
    withdrawn: '已撤回，仅自己可见'
  }
  return map[post.value?.status] || ''
})

onLoad((query = {}) => {
  postId.value = Number(query.id || 0)
  if (!postId.value) {
    toast('帖子不存在')
    back()
    return
  }
  loadPost()
  loadComments(true)
})

onPullDownRefresh(async () => {
  await Promise.all([loadPost(), loadComments(true)])
  uni.stopPullDownRefresh()
})

async function loadPost() {
  try {
    const payload = await getPost(postId.value)
    post.value = payload.data
  } catch (e) {
    toast(e?.message || '帖子不存在或已下线')
    setTimeout(() => back(), 600)
  }
}

async function loadComments(reset = false) {
  if (loadingComments.value) return

  loadingComments.value = true
  try {
    const page = reset ? 1 : commentPage.value + 1
    const payload = await listComments(postId.value, { page, per_page: 20 })
    const rows = payload.data || []

    if (reset) {
      comments.value = rows
      commentPage.value = 1
    } else {
      comments.value = comments.value.concat(rows)
      commentPage.value = page
    }

    const meta = payload.meta || {}
    hasMoreComments.value = page * Number(meta.per_page || 20) < Number(meta.total || 0)
  } catch (e) {
    // 帖子不可见时由 loadPost 统一提示
  } finally {
    loadingComments.value = false
  }
}

async function toggleLike() {
  if (!requireLogin()) return
  if (liking.value || !post.value) return

  liking.value = true
  try {
    const payload = post.value.liked ? await unlikePost(postId.value) : await likePost(postId.value)
    post.value.liked = Boolean(payload.data?.liked)
    post.value.likes_count = Number(payload.data?.likes_count || 0)
  } catch (e) {
    // 请求层已提示
  } finally {
    liking.value = false
  }
}

async function submitComment() {
  if (!requireLogin()) return
  if (!draft.value.trim() || submitting.value) return

  submitting.value = true
  try {
    const payload = await createComment(postId.value, draft.value.trim())
    draft.value = ''

    if (payload.data?.status === 'approved') {
      comments.value.push(payload.data)
      post.value.comments_count = Number(post.value.comments_count || 0) + 1
      toast('评论已发布')
    } else {
      toast(payload.message || '评论已提交审核')
    }
  } catch (e) {
    // 机审拦截（422）等由请求层提示
  } finally {
    submitting.value = false
  }
}

function isMine(comment) {
  return meId.value > 0 && Number(comment.author?.user_id) === meId.value
}

async function removeComment(comment) {
  const ok = await confirmModal({ title: '删除这条评论？', content: '删除后不可恢复', confirmText: '删除' })
  if (!ok) return

  try {
    await deleteComment(postId.value, comment.comment_id)
    comments.value = comments.value.filter((item) => item.comment_id !== comment.comment_id)
    post.value.comments_count = Math.max(0, Number(post.value.comments_count || 0) - 1)
    toast('已删除')
  } catch (e) {
    // 请求层已提示
  }
}

function report() {
  if (!requireLogin()) return

  const title = (post.value?.content || '纯图片帖').slice(0, 20)
  uni.navigateTo({
    url: `/pages/report/index?target_type=post&target_id=${postId.value}&title=${encodeURIComponent(title)}`
  })
}

function previewImage(index) {
  uni.previewImage({ urls: post.value.images, current: post.value.images[index] })
}
</script>

<style lang="scss" scoped>
.post-page {
  min-height: 100vh;
  padding: 24rpx 24rpx 180rpx;
  display: flex;
  flex-direction: column;
  gap: 20rpx;
}

.post__head {
  display: flex;
  align-items: center;
  gap: 16rpx;
  margin-bottom: 16rpx;
}

.post__avatar {
  width: 64rpx;
  height: 64rpx;
  border-radius: 50%;
  background-color: $goat-surface-2;
}

.post__avatar--empty {
  display: flex;
  align-items: center;
  justify-content: center;
  color: $goat-text-dim;
  font-size: 28rpx;
}

.post__name {
  display: block;
  color: $goat-text;
  font-size: 26rpx;
  font-weight: 600;
}

.post__report {
  flex-shrink: 0;
  color: $goat-text-dim;
  font-size: 24rpx;
}

.post__content {
  display: block;
  color: $goat-text;
  font-size: 30rpx;
  line-height: 1.7;
  word-break: break-all;
}

.post__images {
  display: flex;
  flex-direction: column;
  gap: 12rpx;
  margin-top: 16rpx;
}

.post__image {
  width: 100%;
  border-radius: $goat-radius-sm;
  background-color: $goat-surface-2;
}

.post__banner {
  margin-top: 16rpx;
  padding: 12rpx 16rpx;
  border-radius: $goat-radius-sm;
  background-color: $goat-surface-2;
}

.post__actions {
  display: flex;
  align-items: center;
  gap: 40rpx;
  margin-top: 20rpx;
  padding-top: 18rpx;
  border-top: 1rpx solid $goat-border;
}

.post__action {
  display: flex;
  align-items: center;
  gap: 8rpx;
  color: $goat-text-dim;
}

.post__action--on {
  color: #e0507a;
}

.post__action-icon {
  font-size: 32rpx;
  line-height: 1;
}

.post__action-label,
.post__action-count {
  font-size: 24rpx;
}

.comments__title {
  display: block;
  margin-bottom: 16rpx;
  color: $goat-text;
  font-size: 28rpx;
  font-weight: 600;
}

.comment {
  padding: 18rpx 0;
  border-bottom: 1rpx solid $goat-border;

  &:last-of-type {
    border-bottom: none;
  }
}

.comment__head {
  display: flex;
  align-items: center;
  gap: 16rpx;
  margin-bottom: 8rpx;
}

.comment__name {
  color: $goat-text;
  font-size: 26rpx;
  font-weight: 600;
}

.comment__delete {
  margin-left: auto;
  color: $goat-text-dim;
  font-size: 24rpx;
}

.comment__content {
  color: $goat-text;
  font-size: 28rpx;
  line-height: 1.6;
  word-break: break-all;
}

.comments__empty,
.comments__more {
  padding: 24rpx 0;
  text-align: center;
}

.comment-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  display: flex;
  align-items: center;
  gap: 16rpx;
  padding: 16rpx 24rpx calc(16rpx + env(safe-area-inset-bottom));
  background-color: $goat-surface;
  border-top: 1rpx solid $goat-border;
}

.comment-bar__input {
  flex: 1;
  height: 72rpx;
  padding: 0 20rpx;
  color: $goat-text;
  font-size: 28rpx;
  background-color: $goat-surface-2;
  border-radius: $goat-radius-sm;
}
</style>

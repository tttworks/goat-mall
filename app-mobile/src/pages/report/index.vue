<!--
  GOAT 潮玩交易平台 / GOAT 潮玩数据采集
  Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
  Author: Aloysius Luo
  本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
-->

<template>
  <view class="report">
    <view class="g-card">
      <text class="report__target">{{ targetLabel }}：{{ title || `#${targetId}` }}</text>
      <text class="g-dim">请选择最接近的问题类型，平台会尽快核实。恶意举报会影响你的信用。</text>
    </view>

    <view class="g-card report__reasons">
      <view
        v-for="item in reasons"
        :key="item.value"
        class="report__reason"
        :class="{ 'report__reason--on': reason === item.value }"
        @tap="reason = item.value"
      >
        <text class="report__reason-label">{{ item.label }}</text>
        <text class="report__radio">{{ reason === item.value ? '●' : '○' }}</text>
      </view>
    </view>

    <view class="g-card report__detail">
      <text class="report__detail-title">补充说明（选填）</text>
      <textarea
        v-model="detail"
        class="report__textarea"
        placeholder="时间、经过、涉及金额等，越具体越容易核实"
        placeholder-class="g-placeholder"
        :maxlength="500"
        auto-height
      />
      <text class="g-dim">{{ detail.length }}/500</text>
    </view>

    <view
      class="g-btn g-btn--primary report__submit"
      :class="{ 'g-btn--disabled': submitting || !reason }"
      @tap="submit"
    >
      <text>{{ submitting ? '提交中…' : '提交举报' }}</text>
    </view>
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { submitReport } from '../../api/social'
import { requireLogin } from '../../store/session'
import { back, toast } from '../../utils/ui'

/**
 * 举报页（商品 / 帖子 / 评论 / 用户共用）。
 *
 * 入口只传 target_type + target_id（标题仅用于展示），不信任 URL 里的标题做任何判断；
 * 目标是否存在、是否可见、是不是自己的，全部由服务端判定。
 */
const REASONS = [
  { value: 'fake', label: '假货 / 仿品' },
  { value: 'prohibited', label: '违禁品' },
  { value: 'fraud', label: '欺诈 / 骗取钱财' },
  { value: 'abuse', label: '辱骂 / 人身攻击' },
  { value: 'spam', label: '广告 / 刷屏' },
  { value: 'other', label: '其他' }
]

const targetType = ref('post')
const targetId = ref(0)
const title = ref('')
const reason = ref('')
const detail = ref('')
const submitting = ref(false)

const reasons = REASONS

const targetLabel = computed(() => {
  return { listing: '举报商品', post: '举报帖子', comment: '举报评论', user: '举报用户' }[targetType.value] || '举报'
})

onLoad((query = {}) => {
  targetType.value = ['listing', 'post', 'comment', 'user'].includes(query.target_type) ? query.target_type : 'post'
  targetId.value = Number(query.target_id || 0)
  title.value = query.title ? decodeURIComponent(query.title) : ''

  uni.setNavigationBarTitle({ title: targetLabel.value })
})

async function submit() {
  if (submitting.value) return
  if (!reason.value) {
    toast('请先选择举报类型')
    return
  }
  if (!targetId.value) {
    toast('举报对象无效')
    return
  }
  if (!requireLogin()) return

  submitting.value = true
  try {
    const payload = await submitReport({
      targetType: targetType.value,
      targetId: targetId.value,
      reason: reason.value,
      detail: detail.value.trim()
    })

    toast(payload.message || '已收到举报，平台会尽快核实')
    setTimeout(() => back(), 600)
  } catch (e) {
    // 已举报过（409）/ 目标不存在（404）等由请求层统一提示
  } finally {
    submitting.value = false
  }
}
</script>

<style lang="scss" scoped>
.report {
  min-height: 100vh;
  padding: 24rpx;
  display: flex;
  flex-direction: column;
  gap: 20rpx;
}

.report__target {
  display: block;
  margin-bottom: 10rpx;
  color: $goat-text;
  font-size: 28rpx;
  font-weight: 600;
  word-break: break-all;
}

.report__reasons {
  padding: 0;
  overflow: hidden;
}

.report__reason {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 28rpx 24rpx;
  border-bottom: 1rpx solid $goat-border;

  &:last-child {
    border-bottom: none;
  }
}

.report__reason--on .report__reason-label,
.report__reason--on .report__radio {
  color: $goat-accent;
}

.report__reason-label {
  color: $goat-text;
  font-size: 28rpx;
}

.report__radio {
  color: $goat-text-dim;
  font-size: 28rpx;
}

.report__detail-title {
  display: block;
  margin-bottom: 12rpx;
  color: $goat-text;
  font-size: 26rpx;
  font-weight: 600;
}

.report__textarea {
  width: 100%;
  min-height: 160rpx;
  margin-bottom: 10rpx;
  color: $goat-text;
  font-size: 28rpx;
  line-height: 1.6;
}

.report__submit {
  margin-top: 12rpx;
}
</style>

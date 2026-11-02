<!--
  GOAT 潮玩交易平台 / GOAT 潮玩数据采集
  Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
  Author: Aloysius Luo
  本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
-->

<template>
  <view class="identity">
    <view v-if="loading" class="g-loading"><text>加载中…</text></view>

    <block v-else>
      <view class="status" :class="`status--${state}`">
        <text class="status__label">{{ stateLabel }}</text>
        <text class="status__desc">{{ stateDesc }}</text>
      </view>

      <!-- 已实名/审核中：只展示脱敏结果，绝不回显证件号明文 -->
      <view v-if="verification" class="record">
        <view class="record__row">
          <text class="g-muted">证件号</text>
          <text class="record__value">{{ verification.id_number_mask || '已提交' }}</text>
        </view>
        <view class="record__row">
          <text class="g-muted">提交时间</text>
          <text class="record__value">{{ verification.submitted_at ? dateTime(verification.submitted_at) : '—' }}</text>
        </view>
        <view v-if="verification.verified_at" class="record__row">
          <text class="g-muted">核验时间</text>
          <text class="record__value">{{ dateTime(verification.verified_at) }}</text>
        </view>
        <view v-if="verification.reason" class="record__row">
          <text class="g-muted">失败原因</text>
          <text class="record__value record__value--danger">{{ verification.reason }}</text>
        </view>
      </view>

      <view v-if="canSubmit" class="form">
        <text class="form__title">{{ state === 'rejected' ? '重新提交实名信息' : '提交实名信息' }}</text>

        <view class="field">
          <text class="field__label">真实姓名</text>
          <input v-model="form.real_name" class="g-input" maxlength="32" placeholder="与证件一致" placeholder-class="g-placeholder" />
        </view>

        <view class="field">
          <text class="field__label">证件号</text>
          <input v-model="form.id_number" class="g-input" maxlength="32" placeholder="身份证号 / 护照号" placeholder-class="g-placeholder" />
        </view>

        <view class="g-btn g-btn--primary" :class="{ 'g-btn--disabled': submitting }" @tap="submit">
          <text>{{ submitting ? '提交中…' : '提交核验' }}</text>
        </view>

        <text class="g-dim form__hint">
          核验由第三方通道完成，平台只保存核验结果与脱敏信息；同一证件号不可绑定多个账号。
        </text>
      </view>

      <view v-else-if="state === 'approved'" class="done">
        <view class="g-btn g-btn--ghost" @tap="goSeller">
          <text>去卖家中心</text>
        </view>
      </view>

      <view class="notes">
        <text class="g-title notes__title">为什么需要实名</text>
        <text class="g-muted notes__line">· 担保交易与提现需与实名主体一致，保障资金安全</text>
        <text class="g-muted notes__line">· 拍拍/竞拍成交后按实名主体开具凭证</text>
        <text class="g-muted notes__line">· 依据《网络安全法》要求，交易类服务需完成真实身份信息认证</text>
      </view>
    </block>
  </view>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { getIdentity, submitIdentity } from '../../api/seller'
import { dateTime } from '../../utils/format'
import { toast } from '../../utils/ui'

const state = ref('none')
const verification = ref(null)
const loading = ref(true)
const submitting = ref(false)
const form = reactive({ real_name: '', id_number: '' })

const STATE_TEXT = {
  none: { label: '未实名', desc: '完成实名后才能开店、提现与参与竞拍' },
  pending: { label: '核身中', desc: '第三方通道正在核验，通常几分钟内出结果' },
  approved: { label: '已实名', desc: '身份信息已通过核验' },
  rejected: { label: '核身失败', desc: '请核对姓名与证件号后重新提交' }
}

const stateLabel = computed(() => STATE_TEXT[state.value]?.label || '未实名')
const stateDesc = computed(() => STATE_TEXT[state.value]?.desc || '')
// 与后端 VerificationStatus::canSubmit() 同口径：只有「未提交」与「失败」允许再提交
const canSubmit = computed(() => ['none', 'rejected'].includes(state.value))

async function load() {
  loading.value = true

  try {
    const payload = await getIdentity()
    state.value = payload?.status || 'none'
    verification.value = payload?.verification || null
  } catch (e) {
    state.value = 'none'
  } finally {
    loading.value = false
  }
}

async function submit() {
  if (submitting.value) return

  if (form.real_name.trim().length < 2) {
    toast('请填写真实姓名')
    return
  }
  if (form.id_number.trim().length < 5) {
    toast('请填写证件号')
    return
  }

  submitting.value = true

  try {
    const payload = await submitIdentity({
      real_name: form.real_name.trim(),
      id_number: form.id_number.trim()
    })
    state.value = payload?.verification?.status || 'pending'
    verification.value = payload?.verification || null
    toast(state.value === 'approved' ? '实名成功' : '已提交，等待核验')
  } catch (e) {
    /* request 层已提示（证件号占用等） */
  } finally {
    submitting.value = false
  }
}

function goSeller() {
  uni.navigateTo({ url: '/pages/seller/index' })
}

onShow(() => {
  load()
})
</script>

<style lang="scss" scoped>
.identity {
  min-height: 100vh;
  padding: 24rpx 24rpx 64rpx;
  display: flex;
  flex-direction: column;
  gap: 24rpx;
}

.status {
  padding: 32rpx 24rpx;
  border-radius: $goat-radius;
  border: 1rpx solid $goat-border;
  background-color: $goat-surface;
  display: flex;
  flex-direction: column;
  gap: 10rpx;
}

.status--approved {
  background: linear-gradient(150deg, rgba(74, 222, 128, 0.16), rgba(20, 20, 22, 0.92));
  border-color: rgba(74, 222, 128, 0.32);
}

.status--pending {
  background: linear-gradient(150deg, rgba(216, 255, 62, 0.14), rgba(20, 20, 22, 0.92));
  border-color: rgba(216, 255, 62, 0.3);
}

.status--rejected {
  background: linear-gradient(150deg, rgba(255, 90, 90, 0.14), rgba(20, 20, 22, 0.92));
  border-color: rgba(255, 90, 90, 0.3);
}

.status__label {
  color: $goat-text;
  font-size: 36rpx;
  font-weight: 800;
}

.status__desc {
  color: $goat-text-muted;
  font-size: 24rpx;
  line-height: 1.5;
}

.record {
  padding: 8rpx 24rpx;
  border-radius: $goat-radius;
  background-color: $goat-surface;
  border: 1rpx solid $goat-border;
}

.record__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 24rpx;
  padding: 22rpx 0;
  border-bottom: 1rpx solid $goat-border;
}

.record__row:last-child {
  border-bottom: none;
}

.record__value {
  color: $goat-text;
  font-size: 26rpx;
}

.record__value--danger {
  color: $goat-danger;
}

.form {
  padding: 24rpx;
  border-radius: $goat-radius;
  background-color: $goat-surface;
  border: 1rpx solid $goat-border;
  display: flex;
  flex-direction: column;
  gap: 24rpx;
}

.form__title {
  color: $goat-text;
  font-size: 30rpx;
  font-weight: 600;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 12rpx;
}

.field__label {
  color: $goat-text-muted;
  font-size: 24rpx;
}

.form__hint {
  line-height: 1.6;
}

.done {
  display: flex;
}

.done .g-btn {
  flex: 1;
}

.notes {
  padding: 24rpx;
  border-radius: $goat-radius;
  background-color: $goat-surface;
  border: 1rpx solid $goat-border;
  display: flex;
  flex-direction: column;
  gap: 10rpx;
}

.notes__title {
  display: block;
  margin-bottom: 6rpx;
}

.notes__line {
  line-height: 1.6;
}
</style>

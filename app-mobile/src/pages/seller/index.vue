<!--
  GOAT 潮玩交易平台 / GOAT 潮玩数据采集
  Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
  Author: Aloysius Luo
  本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
-->

<template>
  <view class="seller">
    <view v-if="loading" class="g-loading"><text>加载中…</text></view>

    <!-- 已成为卖家：店铺名片 + 经营入口 -->
    <block v-else-if="profile">
      <view class="shop">
        <image v-if="logoUrl" class="shop__logo" :src="logoUrl" mode="aspectFill" />
        <view v-else class="shop__logo shop__logo--empty"><text>GOAT</text></view>

        <view class="shop__body">
          <text class="shop__name">{{ profile.shop_name }}</text>
          <text class="shop__intro g-clamp-2">{{ profile.shop_intro || '还没有店铺简介，写一句让买家更放心' }}</text>
          <view class="shop__tags">
            <text class="g-tag" :class="{ 'g-tag--accent': profile.can_list }">
              <text>{{ profile.status_label }}</text>
            </text>
            <text class="g-tag"><text>{{ verificationLabel(profile.identity_status) }}</text></text>
            <text class="g-tag"><text>已售 {{ profile.sold_count }} 件</text></text>
          </view>
        </view>
      </view>

      <view v-if="profile.status === 'suspended'" class="notice notice--danger">
        <text>{{ profile.reject_reason || '店铺已被封禁，请联系客服申诉' }}</text>
      </view>
      <view v-else-if="profile.status === 'pending'" class="notice">
        <text>
          {{ profile.identity_status === 'approved' ? '资料已提交，等待运营审核（通常 1 个工作日）' : '实名核身中，通过后自动进入店铺审核' }}
        </text>
      </view>

      <view class="seller__stats">
        <view class="stat" @tap="goListings('active')">
          <text class="stat__value">{{ profile.sold_count }}</text>
          <text class="g-dim">累计成交</text>
        </view>
        <view class="stat" @tap="goReviews">
          <text class="stat__value">{{ ratingText }}</text>
          <text class="g-dim">店铺评分</text>
        </view>
        <view class="stat" @tap="goIdentity">
          <text class="stat__value">{{ verificationLabel(profile.identity_status) }}</text>
          <text class="g-dim">实名状态</text>
        </view>
      </view>

      <view class="seller__menu">
        <view class="menu-row" @tap="goListings('')">
          <text class="menu-row__label">我的商品</text>
          <text class="g-dim">草稿 / 待审 / 在售 / 下架 ›</text>
        </view>
        <view class="menu-row" @tap="goCreate">
          <text class="menu-row__label">发布商品</text>
          <text class="g-dim">拍照上传，定价上架 ›</text>
        </view>
        <view class="menu-row" @tap="goReviews">
          <text class="menu-row__label">店铺评价</text>
          <text class="g-dim">查看并回复买家 ›</text>
        </view>
        <view class="menu-row" @tap="goShopPreview">
          <text class="menu-row__label">我的店铺主页</text>
          <text class="g-dim">看看买家眼中的样子 ›</text>
        </view>
        <view class="menu-row" @tap="goIdentity">
          <text class="menu-row__label">实名认证</text>
          <text class="g-dim">{{ verificationLabel(profile.identity_status) }} ›</text>
        </view>
      </view>

      <view v-if="!profile.can_list" class="seller__tip">
        <text class="g-dim">店铺未开通上架权限：审核通过后才能发布商品。</text>
      </view>
    </block>

    <!-- 还不是卖家：一张表单把开店与实名一次提交 -->
    <block v-else>
      <view class="intro">
        <text class="intro__title">成为 GOAT 卖家</text>
        <text class="g-muted intro__desc">
          平台担保交易、成交即回写行情。开店需完成实名核验（与店铺资料一次提交，无需来回切换）。
        </text>
        <view class="intro__points">
          <text class="g-muted">· 0 元开店，成交后按类目费率结算</text>
          <text class="g-muted">· 买家付款先冻结，确认收货后才结算给你</text>
          <text class="g-muted">· 支持图鑑绑定，商品自动进入行情曲线</text>
        </view>
      </view>

      <view class="form">
        <view class="field">
          <text class="field__label">店铺名称</text>
          <input v-model="form.shop_name" class="g-input" maxlength="48" placeholder="例如：老罗潮玩仓" placeholder-class="g-placeholder" />
        </view>

        <view class="field">
          <text class="field__label">店铺简介（选填）</text>
          <textarea
            v-model="form.shop_intro"
            class="g-input form__textarea"
            maxlength="500"
            placeholder="主营品类、发货时效、是否支持验货"
            placeholder-class="g-placeholder"
          />
        </view>

        <view class="field">
          <text class="field__label">真实姓名</text>
          <input v-model="form.real_name" class="g-input" maxlength="32" placeholder="与证件一致" placeholder-class="g-placeholder" />
        </view>

        <view class="field">
          <text class="field__label">证件号</text>
          <input v-model="form.id_number" class="g-input" maxlength="32" placeholder="身份证号 / 护照号" placeholder-class="g-placeholder" />
        </view>

        <view class="agree" @tap="form.agree_terms = !form.agree_terms">
          <view class="agree__box" :class="{ 'agree__box--on': form.agree_terms }">
            <text v-if="form.agree_terms">✓</text>
          </view>
          <text class="g-muted">我已阅读并同意《卖家协议》与《担保交易规则》</text>
        </view>

        <view class="g-btn g-btn--primary" :class="{ 'g-btn--disabled': submitting }" @tap="submit">
          <text>{{ submitting ? '提交中…' : '提交开店申请' }}</text>
        </view>

        <text class="g-dim form__hint">
          实名信息仅用于核身，平台不明文留存证件号；审核结果会在「消息」里通知。
        </text>
      </view>
    </block>
  </view>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { applySeller, getSellerProfile } from '../../api/seller'
import { verificationLabel } from '../../utils/format'
import { toast } from '../../utils/ui'

const profile = ref(null)
const loading = ref(true)
const submitting = ref(false)
const form = reactive({
  shop_name: '',
  shop_intro: '',
  real_name: '',
  id_number: '',
  agree_terms: false
})

const logoUrl = computed(() => {
  const key = profile.value?.shop_logo_key
  if (!key) return ''
  return String(key).startsWith('http') ? key : ''
})

/** rating_bp = 均分 / 5 * 10000（5 星 = 10000），展示时换算回 5 分制一位小数 */
const ratingText = computed(() => {
  const bp = Number(profile.value?.rating_bp || 0)
  return bp > 0 ? (bp / 2000).toFixed(1) : '—'
})

async function load() {
  loading.value = true

  try {
    const payload = await getSellerProfile()
    profile.value = payload?.seller || null
  } catch (e) {
    profile.value = null
  } finally {
    loading.value = false
  }
}

async function submit() {
  if (submitting.value) return

  if (!form.shop_name.trim() || form.shop_name.trim().length < 2) {
    toast('店铺名称至少 2 个字')
    return
  }
  if (!form.real_name.trim() || form.real_name.trim().length < 2) {
    toast('请填写真实姓名')
    return
  }
  if (!form.id_number.trim() || form.id_number.trim().length < 5) {
    toast('请填写证件号')
    return
  }
  if (!form.agree_terms) {
    toast('请先同意卖家协议')
    return
  }

  submitting.value = true

  try {
    await applySeller({
      shop_name: form.shop_name.trim(),
      shop_intro: form.shop_intro.trim(),
      real_name: form.real_name.trim(),
      id_number: form.id_number.trim(),
      agree_terms: true
    })
    toast('申请已提交')
    await load()
  } catch (e) {
    /* request 层已提示具体字段错误 */
  } finally {
    submitting.value = false
  }
}

function goListings(status) {
  uni.navigateTo({ url: `/pages/seller/listings?status=${status}` })
}

function goCreate() {
  uni.navigateTo({ url: '/pages/seller/edit' })
}

function goReviews() {
  // 评价墙按卖家账号 id 查（user_id），不是店铺主键 seller_id
  if (!profile.value?.user_id) return
  uni.navigateTo({ url: `/pages/seller/reviews?id=${profile.value.user_id}` })
}

function goShopPreview() {
  if (!profile.value?.user_id) return
  uni.navigateTo({ url: `/pages/seller/shop?id=${profile.value.user_id}` })
}

function goIdentity() {
  uni.navigateTo({ url: '/pages/identity/index' })
}

onShow(() => {
  load()
})
</script>

<style lang="scss" scoped>
.seller {
  min-height: 100vh;
  padding: 24rpx 24rpx 64rpx;
  display: flex;
  flex-direction: column;
  gap: 24rpx;
}

.shop {
  display: flex;
  gap: 20rpx;
  padding: 24rpx;
  border-radius: $goat-radius;
  background-color: $goat-surface;
  border: 1rpx solid $goat-border;
}

.shop__logo {
  width: 140rpx;
  height: 140rpx;
  border-radius: $goat-radius-sm;
  background-color: $goat-surface-2;
  flex-shrink: 0;
}

.shop__logo--empty {
  display: flex;
  align-items: center;
  justify-content: center;
  color: rgba(255, 255, 255, 0.12);
  font-size: 22rpx;
  letter-spacing: 3rpx;
}

.shop__body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 10rpx;
}

.shop__name {
  color: $goat-text;
  font-size: 32rpx;
  font-weight: 700;
}

.shop__intro {
  color: $goat-text-muted;
  font-size: 24rpx;
  line-height: 1.5;
}

.shop__tags {
  display: flex;
  flex-wrap: wrap;
  gap: 12rpx;
}

.notice {
  padding: 20rpx 24rpx;
  border-radius: $goat-radius-sm;
  background-color: rgba(216, 255, 62, 0.1);
  color: $goat-accent;
  font-size: 24rpx;
  line-height: 1.5;
}

.notice--danger {
  background-color: rgba(255, 90, 90, 0.12);
  color: $goat-danger;
}

.seller__stats {
  display: flex;
  gap: 16rpx;
}

.stat {
  flex: 1;
  padding: 24rpx 12rpx;
  border-radius: $goat-radius;
  background-color: $goat-surface;
  border: 1rpx solid $goat-border;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8rpx;
}

.stat__value {
  color: $goat-text;
  font-size: 30rpx;
  font-weight: 700;
}

.seller__menu {
  border-radius: $goat-radius;
  background-color: $goat-surface;
  border: 1rpx solid $goat-border;
  overflow: hidden;
}

.menu-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16rpx;
  padding: 30rpx 24rpx;
  border-bottom: 1rpx solid $goat-border;
}

.menu-row:last-child {
  border-bottom: none;
}

.menu-row__label {
  color: $goat-text;
  font-size: 28rpx;
}

.seller__tip {
  padding: 0 4rpx;
}

.intro {
  padding: 28rpx 24rpx;
  border-radius: $goat-radius;
  background: linear-gradient(160deg, rgba(216, 255, 62, 0.16), rgba(20, 20, 22, 0.9));
  border: 1rpx solid $goat-border;
}

.intro__title {
  display: block;
  color: $goat-text;
  font-size: 36rpx;
  font-weight: 800;
}

.intro__desc {
  display: block;
  margin-top: 12rpx;
  line-height: 1.6;
}

.intro__points {
  margin-top: 20rpx;
  display: flex;
  flex-direction: column;
  gap: 8rpx;
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

.field {
  display: flex;
  flex-direction: column;
  gap: 12rpx;
}

.field__label {
  color: $goat-text-muted;
  font-size: 24rpx;
}

.form__textarea {
  height: 180rpx;
  padding: 20rpx 28rpx;
  line-height: 1.5;
}

.agree {
  display: flex;
  align-items: center;
  gap: 16rpx;
}

.agree__box {
  width: 36rpx;
  height: 36rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 8rpx;
  border: 2rpx solid $goat-border;
  color: $goat-accent-ink;
  font-size: 20rpx;
  flex-shrink: 0;
}

.agree__box--on {
  background-color: $goat-accent;
  border-color: $goat-accent;
  font-weight: 700;
}

.form__hint {
  line-height: 1.6;
}
</style>

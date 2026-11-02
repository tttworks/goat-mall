<!--
  GOAT 潮玩交易平台 / GOAT 潮玩数据采集
  Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
  Author: Aloysius Luo
  本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
-->

<template>
  <view class="login">
    <view class="login__glow" />

    <view class="login__brand">
      <view class="login__logo">
        <text class="login__logo-text">GOAT</text>
        <text class="login__logo-dot" />
      </view>
      <text class="login__slogan">潮玩交易 · 图鑑先行</text>
    </view>

    <view class="login__tabs">
      <view
        class="login__tab"
        :class="{ 'login__tab--active': mode === 'login' }"
        @tap="switchMode('login')"
      >
        <text>登录</text>
      </view>
      <view
        class="login__tab"
        :class="{ 'login__tab--active': mode === 'register' }"
        @tap="switchMode('register')"
      >
        <text>注册</text>
      </view>
    </view>

    <view class="login__form">
      <input
        v-model="form.login"
        class="g-input"
        :placeholder="mode === 'login' ? '用户名 / 邮箱 / 手机号' : '用户名（4-32 位）'"
        placeholder-class="g-placeholder"
        :maxlength="64"
        @confirm="submit"
      />
      <input
        v-if="mode === 'register'"
        v-model="form.nickname"
        class="g-input"
        placeholder="昵称（选填）"
        placeholder-class="g-placeholder"
        :maxlength="32"
      />
      <input
        v-model="form.password"
        class="g-input"
        password
        placeholder="密码"
        placeholder-class="g-placeholder"
        :maxlength="64"
        @confirm="submit"
      />
      <input
        v-if="mode === 'register'"
        v-model="form.passwordConfirmation"
        class="g-input"
        password
        placeholder="确认密码"
        placeholder-class="g-placeholder"
        :maxlength="64"
        @confirm="submit"
      />

      <view
        class="g-btn g-btn--primary login__submit"
        :class="{ 'g-btn--disabled': submitting }"
        @tap="submit"
      >
        <text>{{ submitting ? '处理中…' : mode === 'login' ? '进入 GOAT' : '注册并登录' }}</text>
      </view>

      <text class="login__hint">
        {{ mode === 'login' ? '演示环境：注册后用同一账号登录即可下单' : '注册即视为同意平台交易与担保规则' }}
      </text>
    </view>
  </view>
</template>

<script setup>
import { reactive, ref } from 'vue'
import { login as loginApi, register as registerApi } from '../../api/auth'
import { signIn } from '../../store/session'

const mode = ref('login')
const submitting = ref(false)

const form = reactive({
  login: '',
  nickname: '',
  password: '',
  passwordConfirmation: ''
})

function switchMode(next) {
  mode.value = next
}

function validate() {
  if (!form.login.trim()) return '请填写用户名'
  if (!form.password) return '请填写密码'
  if (mode.value === 'register' && form.password !== form.passwordConfirmation) {
    return '两次输入的密码不一致'
  }
  return ''
}

async function submit() {
  if (submitting.value) return

  const message = validate()
  if (message) {
    uni.showToast({ title: message, icon: 'none' })
    return
  }

  submitting.value = true
  try {
    const payload =
      mode.value === 'login'
        ? await loginApi({ login: form.login.trim(), password: form.password })
        : await registerApi({
            username: form.login.trim(),
            nickname: form.nickname.trim() || undefined,
            password: form.password,
            password_confirmation: form.passwordConfirmation
          })

    // 后端把 token 与用户资料放在同一层，注册同样直接返回可用的 token
    signIn(payload.data || payload)

    uni.showToast({ title: mode.value === 'login' ? '已登录' : '注册成功', icon: 'none' })
    setTimeout(() => uni.reLaunch({ url: '/pages/index/index' }), 320)
  } catch (e) {
    // 错误提示已在请求层统一 Toast，这里只收尾
  } finally {
    submitting.value = false
  }
}
</script>

<style lang="scss" scoped>
.login {
  position: relative;
  min-height: 100vh;
  padding: calc(var(--status-bar-height) + 96rpx) 48rpx 64rpx;
  overflow: hidden;
}

/* 顶部一团酸绿光晕：暗底上唯一的「品牌感」来源，避免整页死黑 */
.login__glow {
  position: absolute;
  top: -240rpx;
  right: -160rpx;
  width: 620rpx;
  height: 620rpx;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(216, 255, 62, 0.28) 0%, rgba(216, 255, 62, 0) 68%);
}

.login__brand {
  position: relative;
  margin-bottom: 72rpx;
}

.login__logo {
  display: flex;
  align-items: flex-end;
}

.login__logo-text {
  font-size: 104rpx;
  font-weight: 800;
  letter-spacing: 4rpx;
  color: $goat-text;
  line-height: 1;
}

.login__logo-dot {
  width: 18rpx;
  height: 18rpx;
  margin: 0 0 14rpx 8rpx;
  border-radius: 50%;
  background-color: $goat-accent;
}

.login__slogan {
  display: block;
  margin-top: 20rpx;
  color: $goat-text-muted;
  font-size: 26rpx;
  letter-spacing: 2rpx;
}

.login__tabs {
  display: flex;
  gap: 40rpx;
  margin-bottom: 40rpx;
}

.login__tab {
  position: relative;
  padding-bottom: 16rpx;
  color: $goat-text-dim;
  font-size: 30rpx;
}

.login__tab--active {
  color: $goat-text;
  font-weight: 600;
}

.login__tab--active::after {
  content: '';
  position: absolute;
  left: 0;
  bottom: 0;
  width: 100%;
  height: 6rpx;
  border-radius: 999rpx;
  background-color: $goat-accent;
}

.login__form {
  display: flex;
  flex-direction: column;
  gap: 24rpx;
}

.login__submit {
  margin-top: 16rpx;
}

.login__hint {
  margin-top: 8rpx;
  color: $goat-text-dim;
  font-size: 22rpx;
  line-height: 1.6;
}
</style>

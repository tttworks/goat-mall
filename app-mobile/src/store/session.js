/*
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

import { reactive } from 'vue'
import { fetchMe } from '../api/auth'
import { closeRealtime } from '../realtime/client'
import { clearAuthCache, getCachedUser, getToken, setCachedUser, setToken } from '../utils/storage'

/**
 * 登录态（不引入 pinia：我需要的只是「一份可响应的用户 + 几个动作」，
 * 多装一个状态库会让小程序包体积与心智成本都不划算）。
 *
 * 冷启动先用本地缓存渲染，再后台静默刷新 /me——弱网下不至于空白，也能及时感知被禁用/登出。
 */
const state = reactive({
  user: getCachedUser(),
  refreshing: false
})

export const session = state

export function isLoggedIn() {
  return Boolean(getToken())
}

export function setUser(user) {
  state.user = user || null
  setCachedUser(state.user)
}

export function signIn(payload) {
  setToken(payload.token)
  setUser(payload.user)
}

export function signOut() {
  state.user = null
  clearAuthCache()
  // 必须断开 WS：否则换号登录后仍会收到上一个账号的私信与通知
  closeRealtime()
}

/** 请求层捕获 401 后会广播，这里清掉内存态，避免界面还显示着旧用户 */
uni.$on('goat:unauthorized', () => {
  state.user = null
})

export async function refreshUser() {
  if (!isLoggedIn()) {
    signOut()
    return null
  }

  state.refreshing = true
  try {
    const payload = await fetchMe({ silent: true })
    setUser(payload.user)
    return payload.user
  } catch (e) {
    return state.user
  } finally {
    state.refreshing = false
  }
}

export function bootstrapSession() {
  if (!isLoggedIn()) {
    safeLogoutState()
    return
  }

  refreshUser()
}

function safeLogoutState() {
  if (state.user !== null) {
    signOut()
  }
}

/**
 * 需要登录的动作调用它：未登录时直接带去登录页并返回 false，
 * 页面就不用每处都写 if (isLoggedIn()) 分支。
 */
export function requireLogin() {
  if (isLoggedIn()) return true

  uni.navigateTo({ url: '/pages/login/index' })
  return false
}

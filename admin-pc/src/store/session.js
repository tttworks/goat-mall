/*
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

import { computed, reactive } from 'vue'
import { login as loginApi, logout as logoutApi, me } from '../api/auth'
import { getToken, setToken } from '../api/request'

const USER_KEY = 'goat:admin:user'

function readCachedUser() {
  try {
    return JSON.parse(window.localStorage.getItem(USER_KEY) || 'null')
  } catch (e) {
    return null
  }
}

const state = reactive({
  user: readCachedUser(),
  loading: false
})

export const session = state

export const isLoggedIn = computed(() => Boolean(state.user) && Boolean(getToken()))
export const isAdmin = computed(() => state.user?.role === 'admin')

function persistUser(user) {
  state.user = user
  if (user) {
    window.localStorage.setItem(USER_KEY, JSON.stringify(user))
  } else {
    window.localStorage.removeItem(USER_KEY)
  }
}

/**
 * 登录。后台只认 admin：非管理员即便密码正确也拒绝——
 * 否则运营后台会变成「任何注册用户都能进来的空壳」，还白拿一个有效 Token。
 */
export async function signIn(login, password) {
  state.loading = true
  try {
    const payload = await loginApi(login, password)
    const user = payload?.user || payload?.data?.user
    const token = payload?.token || payload?.data?.token

    if (!user || user.role !== 'admin') {
      throw new Error('该账号不是管理员，无法进入运营后台')
    }

    setToken(token)
    persistUser(user)
    return user
  } finally {
    state.loading = false
  }
}

export async function refreshUser() {
  if (!getToken()) {
    persistUser(null)
    return null
  }
  try {
    const payload = await me()
    const user = payload?.user || payload?.data || payload
    if (user?.role !== 'admin') {
      await signOut()
      return null
    }
    persistUser(user)
    return user
  } catch (e) {
    return null
  }
}

export async function signOut() {
  try {
    if (getToken()) await logoutApi()
  } catch (e) {
    // 服务端撤销失败也要清本地，避免后台卡在「登不出去」的状态
  }
  setToken('')
  persistUser(null)
}

// 任何请求收到 401 都立刻清本地态：后续路由守卫会把用户带回登录页
window.addEventListener('goat:admin:unauthorized', () => {
  setToken('')
  persistUser(null)
})

/*
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

/**
 * 本地缓存读写（token + 用户资料）。
 *
 * 单独抽出来是为了避免「request 层要读 token、session 层要写 token」互相 import 成环。
 * 键名统一带 goat: 前缀，方便清缓存时一把捞干净。
 */
const TOKEN_KEY = 'goat:token'
const USER_KEY = 'goat:user'

export function getToken() {
  return uni.getStorageSync(TOKEN_KEY) || ''
}

export function setToken(token) {
  if (token) {
    uni.setStorageSync(TOKEN_KEY, token)
  } else {
    uni.removeStorageSync(TOKEN_KEY)
  }
}

export function getCachedUser() {
  const raw = uni.getStorageSync(USER_KEY)
  if (!raw) return null
  // 早期版本可能存过字符串，这里容错解析，避免升级用户白屏
  try {
    return typeof raw === 'string' ? JSON.parse(raw) : raw
  } catch (e) {
    return null
  }
}

export function setCachedUser(user) {
  if (user) {
    uni.setStorageSync(USER_KEY, user)
  } else {
    uni.removeStorageSync(USER_KEY)
  }
}

export function clearAuthCache() {
  uni.removeStorageSync(TOKEN_KEY)
  uni.removeStorageSync(USER_KEY)
}

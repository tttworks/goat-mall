/*
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

import { clearAuthCache, getToken } from '../utils/storage'

/**
 * 统一请求层。
 *
 * 三个约定：
 * 1. 只在这里拼 baseURL 与 Authorization，页面不出现域名与 token；
 * 2. 业务错误统一 Toast（silent 可关），调用方只处理「成功数据」与「错误对象」；
 * 3. 401 统一清缓存 + 跳登录，避免每个页面各写一遍。
 */
const BASE_URL = import.meta.env.VITE_API_BASE || '/api/v1'

function pickMessage(payload, statusCode) {
  if (!payload) return `请求失败（${statusCode}）`
  if (typeof payload === 'string') return payload.slice(0, 120)
  if (payload.message) return payload.message
  if (payload.errors) {
    const first = Object.values(payload.errors)[0]
    if (Array.isArray(first) && first.length) return String(first[0])
  }
  return payload.code ? String(payload.code) : `请求失败（${statusCode}）`
}

function handleUnauthorized() {
  clearAuthCache()
  uni.$emit('goat:unauthorized')

  const pages = typeof getCurrentPages === 'function' ? getCurrentPages() : []
  const current = pages.length ? pages[pages.length - 1].route : ''
  if (current !== 'pages/login/index') {
    uni.reLaunch({ url: '/pages/login/index' })
  }
}

export function request(path, options = {}) {
  const { method = 'GET', data, auth = true, silent = false, header = {} } = options
  const token = getToken()
  const url = /^https?:\/\//.test(path) ? path : `${BASE_URL}${path}`

  return new Promise((resolve, reject) => {
    uni.request({
      url,
      method,
      data,
      timeout: 15000,
      header: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...(auth && token ? { Authorization: `Bearer ${token}` } : {}),
        ...header
      },
      success: (res) => {
        const { statusCode, data: payload } = res

        if (statusCode >= 200 && statusCode < 300) {
          resolve(payload)
          return
        }

        if (statusCode === 401) {
          handleUnauthorized()
        }

        const message = pickMessage(payload, statusCode)
        if (!silent) {
          uni.showToast({ title: message, icon: 'none', duration: 2200 })
        }
        reject(Object.assign(new Error(message), { statusCode, payload }))
      },
      fail: (err) => {
        const message = '网络异常，请检查网络后重试'
        if (!silent) {
          uni.showToast({ title: message, icon: 'none' })
        }
        reject(Object.assign(new Error(message), { cause: err }))
      }
    })
  })
}

export const http = {
  get: (path, options) => request(path, { ...options, method: 'GET' }),
  post: (path, data, options) => request(path, { ...options, method: 'POST', data }),
  put: (path, data, options) => request(path, { ...options, method: 'PUT', data }),
  patch: (path, data, options) => request(path, { ...options, method: 'PATCH', data }),
  del: (path, options) => request(path, { ...options, method: 'DELETE' })
}

/**
 * 文件上传（uni.uploadFile 不支持走 request 的 JSON 通道，必须单独封一层）。
 *
 * 与 request 保持同样的三条约定：域名只在 api 层出现、token 自动带、错误统一 Toast。
 */
export function upload(path, filePath, { name = 'file', formData = {}, silent = false } = {}) {
  const token = getToken()

  return new Promise((resolve, reject) => {
    uni.uploadFile({
      url: `${BASE_URL}${path}`,
      filePath,
      name,
      formData,
      header: token ? { Authorization: `Bearer ${token}` } : {},
      success: (res) => {
        let payload = res.data
        try {
          payload = typeof res.data === 'string' ? JSON.parse(res.data) : res.data
        } catch (e) {
          payload = null
        }

        if (res.statusCode >= 200 && res.statusCode < 300) {
          resolve(payload)
          return
        }

        if (res.statusCode === 401) handleUnauthorized()

        const message = pickMessage(payload, res.statusCode)
        if (!silent) uni.showToast({ title: message, icon: 'none', duration: 2200 })
        reject(Object.assign(new Error(message), { statusCode: res.statusCode, payload }))
      },
      fail: (err) => {
        const message = '图片上传失败，请重试'
        if (!silent) uni.showToast({ title: message, icon: 'none' })
        reject(Object.assign(new Error(message), { cause: err }))
      }
    })
  })
}

/**
 * 幂等键：下单/支付这类「点两下不能出两单」的接口必须带。
 * 不做 UUID 库依赖，时间戳 + 随机串足够（服务端还有数据库唯一约束兜底）。
 */
export function makeIdempotencyKey(prefix = 'goat') {
  const rand = Math.random().toString(36).slice(2, 10)
  return `${prefix}-${Date.now()}-${rand}`
}

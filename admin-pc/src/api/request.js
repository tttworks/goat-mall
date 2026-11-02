/*
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

/**
 * 后台请求层（零依赖，基于原生 fetch）。
 *
 * 与 C 端同一套约定：只在这里拼 baseURL / Token / 错误信息，页面不关心传输细节。
 * 401 用事件广播而不是直接跳转——请求层不该依赖 router，避免循环引用。
 */
const BASE_URL = import.meta.env.VITE_API_BASE || '/api/v1'
const TOKEN_KEY = 'goat:admin:token'

export function getToken() {
  return window.localStorage.getItem(TOKEN_KEY) || ''
}

export function setToken(token) {
  if (token) {
    window.localStorage.setItem(TOKEN_KEY, token)
  } else {
    window.localStorage.removeItem(TOKEN_KEY)
  }
}

function buildQuery(params) {
  if (!params) return ''
  const search = new URLSearchParams()
  Object.entries(params).forEach(([key, value]) => {
    if (value === undefined || value === null || value === '') return
    search.append(key, String(value))
  })
  const query = search.toString()
  return query ? `?${query}` : ''
}

function pickMessage(payload, status) {
  if (!payload) return `请求失败（${status}）`
  if (typeof payload === 'string') return payload.slice(0, 140)
  if (payload.message) return payload.message
  if (payload.errors) {
    const first = Object.values(payload.errors)[0]
    if (Array.isArray(first) && first.length) return String(first[0])
  }
  return payload.code ? String(payload.code) : `请求失败（${status}）`
}

export async function request(path, options = {}) {
  const { method = 'GET', data, params, auth = true } = options
  const headers = { Accept: 'application/json' }

  if (data !== undefined) headers['Content-Type'] = 'application/json'

  const token = getToken()
  if (auth && token) headers.Authorization = `Bearer ${token}`

  let response
  try {
    response = await fetch(`${BASE_URL}${path}${buildQuery(params)}`, {
      method,
      headers,
      body: data === undefined ? undefined : JSON.stringify(data)
    })
  } catch (e) {
    throw Object.assign(new Error('网络异常，请确认后端服务已启动'), { status: 0 })
  }

  if (response.status === 204) return null

  const text = await response.text()
  let payload = null
  if (text) {
    try {
      payload = JSON.parse(text)
    } catch (e) {
      payload = text
    }
  }

  if (!response.ok) {
    if (response.status === 401) {
      setToken('')
      window.dispatchEvent(new CustomEvent('goat:admin:unauthorized'))
    }
    throw Object.assign(new Error(pickMessage(payload, response.status)), {
      status: response.status,
      payload
    })
  }

  return payload
}

export const http = {
  get: (path, params) => request(path, { method: 'GET', params }),
  post: (path, data) => request(path, { method: 'POST', data }),
  put: (path, data) => request(path, { method: 'PUT', data }),
  patch: (path, data) => request(path, { method: 'PATCH', data }),
  del: (path) => request(path, { method: 'DELETE' })
}

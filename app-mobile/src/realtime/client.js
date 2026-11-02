/*
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

import { authChannel, fetchRealtimeConfig } from '../api/realtime'
import { getToken } from '../utils/storage'

/**
 * 极简 Pusher 协议客户端（对接 Laravel Reverb）。
 *
 * 为什么不引 pusher-js / laravel-echo：
 * 1. 只用到协议里 5 个帧（connection_established / subscribe / 
 *    subscription_succeeded / ping / 自定义事件），自己写 100 行足够；
 * 2. uni-app 的 WebSocket 必须走 uni.connectSocket（小程序没有全局 WebSocket），
 *    pusher-js 在小程序端要额外打补丁，反而更脆；
 * 3. 少一个依赖，包体积与升级风险都小。
 *
 * 可用性优先：WS 连不上就静默退回「HTTP 拉取 + 下拉刷新」，不弹错、不阻塞页面。
 */

const state = {
  config: null,
  configLoading: null,
  task: null,
  socketId: '',
  connected: false,
  retry: 0,
  timer: null,
  stopped: false,
  // channel -> Set<handler>
  handlers: new Map(),
  // 已发起订阅的频道（断线重连后据此重放）
  subscribed: new Set()
}

function socketUrl(config) {
  const secure = config.scheme === 'https'
  const port = config.port && config.port !== (secure ? 443 : 80) ? `:${config.port}` : ''
  const query = 'protocol=7&client=goat-mobile&version=1.0&flash=false'
  return `${secure ? 'wss' : 'ws'}://${config.host}${port}/app/${config.key}?${query}`
}

function send(frame) {
  if (!state.task) return
  try {
    state.task.send({ data: JSON.stringify(frame) })
  } catch (e) {
    // 发送失败交给 onClose 的重连逻辑处理，这里不重复报错
  }
}

function dispatch(channel, event, data) {
  const set = state.handlers.get(channel)
  if (!set) return
  set.forEach((handler) => {
    try {
      handler(event, data)
    } catch (e) {
      // 单个处理器的异常不能影响其他订阅者
    }
  })
}

async function subscribe(channel) {
  if (!state.connected || !state.socketId) {
    state.subscribed.add(channel)
    return
  }

  try {
    const payload = await authChannel(state.socketId, channel)
    // 鉴权失败（如 Token 过期）时静默放弃：页面照旧靠 HTTP 拉取
    send({ event: 'pusher:subscribe', data: { auth: payload?.auth, channel } })
    state.subscribed.add(channel)
  } catch (e) {
    state.subscribed.add(channel)
  }
}

function scheduleReconnect() {
  if (state.stopped || state.timer) return
  // 退避上限 15s：既不让服务端被重连风暴打，也不让用户等太久
  const delay = Math.min(1000 * 2 ** state.retry, 15000)
  state.retry += 1
  state.timer = setTimeout(() => {
    state.timer = null
    connect()
  }, delay)
}

function handleFrame(raw) {
  let frame
  try {
    frame = typeof raw === 'string' ? JSON.parse(raw) : raw
  } catch (e) {
    return
  }
  if (!frame || !frame.event) return

  if (frame.event === 'pusher:connection_established') {
    const data = typeof frame.data === 'string' ? JSON.parse(frame.data) : frame.data
    state.socketId = data?.socket_id || ''
    state.connected = true
    state.retry = 0
    // 重连后补齐订阅：链路恢复必须「自动接上」，不能要用户手动刷新
    state.subscribed.forEach((channel) => subscribe(channel))
    return
  }

  if (frame.event === 'pusher:ping') {
    send({ event: 'pusher:pong', data: {} })
    return
  }

  if (frame.event === 'pusher:error') {
    return
  }

  if (frame.channel) {
    dispatch(frame.channel, frame.event, frame.data)
  }
}

function connect() {
  if (state.stopped || state.task || !state.config || !getToken()) return

  const task = uni.connectSocket({
    url: socketUrl(state.config),
    complete: () => {}
  })
  state.task = task

  task.onOpen(() => {})

  task.onMessage((message) => handleFrame(message?.data))

  task.onClose(() => {
    state.connected = false
    state.socketId = ''
    state.task = null
    scheduleReconnect()
  })

  task.onError(() => {
    state.connected = false
    state.socketId = ''
    state.task = null
    scheduleReconnect()
  })
}

/** 读取（并缓存）服务端下发的端点配置 */
async function loadConfig() {
  if (state.config) return state.config
  if (!state.configLoading) {
    state.configLoading = fetchRealtimeConfig()
      .then((payload) => payload?.data || { enabled: false })
      .catch(() => ({ enabled: false }))
      .finally(() => {
        state.configLoading = null
      })
  }

  state.config = await state.configLoading
  return state.config
}

/**
 * 建立连接（幂等）。返回是否真的启用——关闭状态时调用方无需做任何降级处理。
 */
export async function ensureRealtime() {
  if (!getToken()) return false

  const config = await loadConfig()
  if (!config?.enabled || !config.key || !config.host) return false

  state.stopped = false
  connect()
  return true
}

/**
 * 订阅当前用户的私有频道。返回取消函数（页面卸载必须调用）。
 *
 * @param {number} userId
 * @param {(event: string, data: any) => void} handler
 */
export function onUserEvent(userId, handler) {
  const channel = `private-goat.user.${userId}`
  if (!state.handlers.has(channel)) state.handlers.set(channel, new Set())
  state.handlers.get(channel).add(handler)

  if (state.connected) {
    subscribe(channel)
  } else {
    state.subscribed.add(channel)
    ensureRealtime()
  }

  return () => {
    const set = state.handlers.get(channel)
    if (!set) return
    set.delete(handler)
    if (!set.size) {
      state.handlers.delete(channel)
      state.subscribed.delete(channel)
    }
  }
}

/** 登出/切换账号时必须断开：否则下个账号会收到上一个账号的推送 */
export function closeRealtime() {
  state.stopped = true
  state.connected = false
  state.socketId = ''
  state.retry = 0
  state.subscribed.clear()
  state.handlers.clear()

  if (state.timer) {
    clearTimeout(state.timer)
    state.timer = null
  }

  if (state.task) {
    try {
      state.task.close({})
    } catch (e) {
      // 已断开则忽略
    }
    state.task = null
  }
}

/*
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

import { http } from './request'

/**
 * 实时推送端点（T6）。
 *
 * 配置由服务端下发而不是打包进前端：域名与 app key 属部署属性，
 * 写进产物等于把一套环境焊死。开关关闭时前端完全退回 HTTP 拉取，不报错。
 */
export function fetchRealtimeConfig() {
  return http.get('/realtime/config', { auth: false, silent: true })
}

/** 私有频道鉴权：与普通接口同一套 Bearer Token，不需要 WS 专用的会话 */
export function authChannel(socketId, channel) {
  return http.post(
    '/broadcasting/auth',
    { socket_id: socketId, channel_name: channel },
    { silent: true }
  )
}

/*
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

import { http } from './request'

/**
 * 认证域。device_name 固定带上平台号，后台「登录设备」列表才分得清是谁在登。
 *
 * 路径必须与 routes/api.php 的 auth 分组一致（/auth/login 等）：
 * 少写一层前缀会 404，登录页就变成「点了没反应」的空白页。
 */
function deviceName() {
  let platform = 'h5'
  // #ifdef APP-PLUS
  platform = 'app'
  // #endif
  // #ifdef MP-WEIXIN
  platform = 'mp-weixin'
  // #endif

  return `goat-${platform}`
}

export function login({ login, password }) {
  return http.post('/auth/login', { login, password, device_name: deviceName() }, { auth: false })
}

export function register({ username, password, password_confirmation, nickname }) {
  return http.post(
    '/auth/register',
    { username, password, password_confirmation, nickname, locale: 'zh_CN' },
    { auth: false }
  )
}

export function fetchMe(options = {}) {
  return http.get('/auth/me', options)
}

export function logout() {
  return http.post('/auth/logout', {}, { silent: true })
}

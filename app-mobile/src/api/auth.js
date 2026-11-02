/*
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

import { http } from './request'

/**
 * 认证域。device_name 固定带上平台号，后台「登录设备」列表才分得清是谁在登。
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
  return http.post('/login', { login, password, device_name: deviceName() }, { auth: false })
}

export function register({ username, password, password_confirmation, nickname }) {
  return http.post(
    '/register',
    { username, password, password_confirmation, nickname, locale: 'zh_CN' },
    { auth: false }
  )
}

export function fetchMe(options = {}) {
  return http.get('/me', options)
}

export function logout() {
  return http.post('/logout', {}, { silent: true })
}

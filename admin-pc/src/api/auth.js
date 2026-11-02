/*
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

import { http } from './request'

/** 后台登录：复用 C 端 /auth/login，多传 device_name 便于在用户后台撤销某个后台会话 */
export function login(login, password) {
  return http.post('/auth/login', { login, password, device_name: 'goat-admin-pc' })
}

export function me() {
  return http.get('/me')
}

export function logout() {
  return http.post('/auth/logout')
}

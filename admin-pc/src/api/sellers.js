/*
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

import { http } from './request'

export function listSellers(params) {
  return http.get('/admin/sellers', params)
}

/** decision: approve | reject | suspend；reject/suspend 必带 reason */
export function reviewSeller(sellerId, decision, reason) {
  return http.post(`/admin/sellers/${sellerId}/review`, { decision, reason })
}

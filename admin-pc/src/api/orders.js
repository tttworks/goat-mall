/*
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

import { http } from './request'

export function listOrders(params) {
  return http.get('/admin/orders', params)
}

export function getOrder(orderId) {
  return http.get(`/admin/orders/${orderId}`)
}

/** 运营备注是后台唯一允许写订单的字段：金额与状态都不给改 */
export function remarkOrder(orderId, remark) {
  return http.patch(`/admin/orders/${orderId}/remark`, { remark })
}

export function listRefunds(params) {
  return http.get('/admin/refunds', params)
}

/** approve=true 会立刻调渠道退款；approve=false 必须给 remark（买家会看到） */
export function reviewRefund(refundId, approve, remark) {
  return http.post(`/admin/refunds/${refundId}/review`, { approve, remark })
}

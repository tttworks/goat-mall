/*
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

import { http } from './request'

/** scope: global | category | seller；percent_bip 为万分比（150 = 1.5%），0 表示免费期 */
export function listFeeRules(params) {
  return http.get('/admin/fee-rules', params)
}

export function createFeeRule(data) {
  return http.post('/admin/fee-rules', data)
}

export function updateFeeRule(feeRuleId, data) {
  return http.put(`/admin/fee-rules/${feeRuleId}`, data)
}

/** 被历史订单引用过的规则会被后端改为停用（返回 message 说明），不会真的删掉 */
export function deleteFeeRule(feeRuleId) {
  return http.del(`/admin/fee-rules/${feeRuleId}`)
}

/** 试算：改完规则立刻看「这笔收多少」，不经过下单。
 *  goods_amount 传字符串，避免前端浮点误差 */
export function previewFee(params) {
  return http.get('/admin/fee-quote', params)
}

/*
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

import { http } from './request'

export function listAuctions(params) {
  return http.get('/admin/auctions', params)
}

export function getAuction(auctionId) {
  return http.get(`/admin/auctions/${auctionId}`)
}

/** 只有无人出价的场次能撤：有出价就有保证金在飞，后端会拒绝并给出原因 */
export function cancelAuction(auctionId, reason) {
  return http.post(`/admin/auctions/${auctionId}/cancel`, { reason })
}

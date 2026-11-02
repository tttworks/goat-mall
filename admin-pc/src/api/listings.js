/*
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

import { http } from './request'

export function listListings(params) {
  return http.get('/admin/listings', params)
}

/** action: approve | reject；reject 必带 reason（会原样回传给卖家） */
export function reviewListing(listingId, action, reason) {
  return http.post(`/admin/listings/${listingId}/review`, { action, reason })
}

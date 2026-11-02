/*
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

import { http } from './request'

/**
 * 收货地址簿。
 *
 * 下单必须带 address_id，服务端会按该地址把收件人快照进订单——
 * 买家之后改地址、删地址，都不影响已下的单。
 */
export function listAddresses() {
  return http.get('/me/addresses')
}

export function createAddress(payload) {
  return http.post('/me/addresses', payload)
}

export function updateAddress(addressId, payload) {
  return http.put(`/me/addresses/${addressId}`, payload)
}

export function removeAddress(addressId) {
  return http.del(`/me/addresses/${addressId}`)
}

export function setDefaultAddress(addressId) {
  return http.post(`/me/addresses/${addressId}/default`)
}

/**
 * 下单页 → 地址簿页 → 回下单页的交接。
 *
 * uni-app 的跨页回传（eventChannel）在 tabBar 页与 H5 刷新后不可靠，
 * 这里用一次性本地缓存：地址页选中即写入，下单页 onShow 取走并立刻清掉，
 * 不会出现「下次下单还是上次选的那条」。
 */
const SELECTED_KEY = 'goat:selected_address'

export function stashSelectedAddress(address) {
  uni.setStorageSync(SELECTED_KEY, address)
}

export function takeSelectedAddress() {
  const value = uni.getStorageSync(SELECTED_KEY)
  if (value) uni.removeStorageSync(SELECTED_KEY)
  return value || null
}

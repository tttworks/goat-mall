/*
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

import { http } from './request'

/**
 * 图鑑与商品（只读域）。
 *
 * 列表接口出参统一是 { data, meta }，meta 里带分页与筛选项，
 * 所以前端不必硬编码品类中文名（后台加品类，C 端自动出现）。
 */
export function listFigures(params = {}) {
  return http.get('/figures', { data: params, silent: true })
}

export function getFigure(id) {
  return http.get(`/figures/${id}`)
}

export function listListings(params = {}) {
  return http.get('/listings', { data: params, silent: true })
}

export function getListing(id) {
  return http.get(`/listings/${id}`)
}

export function getSeller(id) {
  return http.get(`/sellers/${id}`, { silent: true })
}

export function listSellerReviews(id, params = {}) {
  return http.get(`/sellers/${id}/reviews`, { data: params, silent: true })
}

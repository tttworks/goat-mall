/*
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

import { http, makeIdempotencyKey } from './request'

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

/**
 * 行情（多源概览 + 按天 K 线）。
 *
 * 两个接口都可能返回空数据（新图鑑还没成交），silent 让页面自己决定显示「暂无行情」
 * 而不是弹一层 Toast——空态是正常状态，不是错误。
 */
export function getFigureQuotes(id, params = {}) {
  return http.get(`/figures/${id}/quotes`, { data: params, silent: true })
}

export function getFigureKline(id, params = {}) {
  return http.get(`/figures/${id}/kline`, { data: params, silent: true })
}

/* ---------- 竞拍 ---------- */
export function listAuctions(params = {}) {
  return http.get('/auctions', { data: params, silent: true })
}

export function getAuction(id) {
  return http.get(`/auctions/${id}`)
}

export function placeBid(auctionId, amount) {
  const key = makeIdempotencyKey('bid')

  return http.post(
    `/auctions/${auctionId}/bids`,
    { amount: String(amount), idempotency_key: key },
    { header: { 'Idempotency-Key': key } }
  )
}

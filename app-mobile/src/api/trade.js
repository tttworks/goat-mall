/*
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

import { http, makeIdempotencyKey } from './request'

/**
 * 订单与支付。
 *
 * 「下单」「发起支付」都必须带 Idempotency-Key：用户连点两下不能出两单、两笔支付。
 * 键在这里生成而不是页面里，保证任何入口调同一个方法都天然幂等。
 */
export function createOrder({ listing_id, remark }) {
  return http.post(
    '/orders',
    { listing_id, remark },
    { header: { 'Idempotency-Key': makeIdempotencyKey('order') } }
  )
}

export function listOrders(params = {}) {
  return http.get('/orders', { data: params, silent: true })
}

export function getOrder(orderId) {
  return http.get(`/orders/${orderId}`)
}

export function cancelOrder(orderId, reason) {
  return http.post(`/orders/${orderId}/cancel`, { reason })
}

export function shipOrder(orderId, { shipping_company, tracking_no }) {
  return http.post(`/orders/${orderId}/ship`, { shipping_company, tracking_no })
}

export function confirmOrder(orderId) {
  return http.post(`/orders/${orderId}/confirm`)
}

export function payOrder(orderId) {
  // 服务端同时校验 body 里的 idempotency_key 与请求头，两处保持同源
  const key = makeIdempotencyKey('pay')

  return http.post(
    `/orders/${orderId}/payments`,
    { idempotency_key: key },
    { header: { 'Idempotency-Key': key } }
  )
}

export function getPayment(paymentId) {
  return http.get(`/payments/${paymentId}`)
}

/** 仅沙箱通道可用：模拟渠道回调成功，跑的是真实验签链路 */
export function simulatePayment(paymentId) {
  return http.post(`/payments/${paymentId}/sandbox-pay`)
}

export function reviewOrder(orderId, { rating, content }) {
  return http.post(`/orders/${orderId}/reviews`, { rating, content })
}

/* ---------- 售后 ---------- */

/** 申请退款：只落申请单，钱由后台审核通过才退（买家侧不存在「点一下钱就没了」） */
export function applyRefund(orderId, reason) {
  return http.post(`/orders/${orderId}/refunds`, { reason })
}

export function listRefunds(params = {}) {
  return http.get('/me/refunds', { data: params, silent: true })
}

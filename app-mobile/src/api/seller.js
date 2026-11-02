/*
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

import { http, upload } from './request'

/**
 * 卖家经营域：店铺档案、实名、我的商品、图片上传。
 *
 * 注意两点后端口径：
 * 1. 开店与实名是一次提交（/seller/apply 同时收 shop_* 与 real_name/id_number），
 *    所以「开店表单」不能拆成两步，否则会出现「开了店没实名」的空档；
 * 2. 上架状态流转是独立动作（submit/delist/reopen），编辑接口永远不改状态。
 */

/* ---------- 店铺 ---------- */
export function getSellerProfile() {
  return http.get('/seller', { silent: true })
}

export function applySeller(payload) {
  return http.post('/seller/apply', payload)
}

/* ---------- 实名 ---------- */
export function getIdentity() {
  return http.get('/identity', { silent: true })
}

export function submitIdentity({ real_name, id_number }) {
  return http.post('/identity', { real_name, id_number })
}

/* ---------- 我的商品 ---------- */
export function listMyListings(params = {}) {
  return http.get('/seller/listings', { data: params, silent: true })
}

export function createListing(payload) {
  return http.post('/seller/listings', payload)
}

export function updateListing(listingId, payload) {
  return http.put(`/seller/listings/${listingId}`, payload)
}

/** 提交审核：草稿/被驳回/已下架都能走，服务层按状态机逐步校验 */
export function submitListing(listingId) {
  return http.post(`/seller/listings/${listingId}/submit`)
}

export function delistListing(listingId) {
  return http.post(`/seller/listings/${listingId}/delist`)
}

export function reopenListing(listingId) {
  return http.post(`/seller/listings/${listingId}/reopen`)
}

/**
 * 商品图上传（multipart）。
 * 返回 { key, url }：key 存库，url 只用于当场预览。
 */
export function uploadListingImage(filePath) {
  return upload('/seller/media', filePath, { name: 'file' })
}

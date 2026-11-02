/*
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

import { http, upload } from './request'

/* ---------- 收藏 ---------- */
export function listFavorites(params = {}) {
  return http.get('/me/favorites', { data: params, silent: true })
}

export function addFavorite(targetType, targetId) {
  return http.post('/me/favorites', { target_type: targetType, target_id: targetId })
}

export function removeFavorite(targetType, targetId) {
  return http.del(`/me/favorites/${targetType}/${targetId}`)
}

/* ---------- 关注 ---------- */
export function followSeller(sellerId) {
  return http.post(`/sellers/${sellerId}/follow`)
}

export function unfollowSeller(sellerId) {
  return http.del(`/sellers/${sellerId}/follow`)
}

/* ---------- 通知 ---------- */
export function listNotifications(params = {}) {
  return http.get('/notifications', { data: params, silent: true })
}

export function readNotification(notificationId) {
  return http.post(`/notifications/${notificationId}/read`)
}

export function readAllNotifications() {
  return http.post('/notifications/read-all')
}

/* ---------- 社群帖 ---------- */
export function listPosts(params = {}) {
  return http.get('/posts', { data: params, silent: true })
}

export function createPost({ content, images }) {
  return http.post('/posts', { content, images })
}

export function listMyPosts(params = {}) {
  return http.get('/me/posts', { data: params, silent: true })
}

export function withdrawPost(postId) {
  return http.del(`/posts/${postId}`)
}

/** 发帖图片上传（multipart）：返回 { key, url }，key 存库、url 只用于预览 */
export function uploadPostImage(filePath) {
  return upload('/posts/media', filePath, { name: 'file' })
}

/* ---------- 点赞（幂等：POST 点赞 / DELETE 取消） ---------- */
export function likePost(postId) {
  return http.post(`/posts/${postId}/like`)
}

export function unlikePost(postId) {
  return http.del(`/posts/${postId}/like`)
}

/* ---------- 评论 ---------- */
export function getPost(postId) {
  return http.get(`/posts/${postId}`, { silent: true })
}

export function listComments(postId, params = {}) {
  return http.get(`/posts/${postId}/comments`, { data: params, silent: true })
}

export function createComment(postId, content) {
  return http.post(`/posts/${postId}/comments`, { content })
}

export function deleteComment(postId, commentId) {
  return http.del(`/posts/${postId}/comments/${commentId}`)
}

/* ---------- 举报 ---------- */
export function submitReport({ targetType, targetId, reason, detail = '' }) {
  return http.post('/reports', {
    target_type: targetType,
    target_id: targetId,
    reason,
    detail
  })
}

/* ---------- 私信 ---------- */
export function listConversations(params = {}) {
  return http.get('/conversations', { data: params, silent: true })
}

export function openConversation(peerId) {
  return http.post('/conversations', { peer_id: peerId })
}

export function listMessages(conversationId, params = {}) {
  return http.get(`/conversations/${conversationId}/messages`, { data: params, silent: true })
}

export function sendMessage(conversationId, body) {
  return http.post(`/conversations/${conversationId}/messages`, { body })
}

/* ---------- 评价回复 ---------- */
export function replyReview(reviewId, content) {
  return http.post(`/reviews/${reviewId}/reply`, { content })
}

/* ---------- 购物车 ---------- */
/** 列表里每行自带 purchasable：商品被别人买走后行仍保留，靠它置灰而不是直接消失 */
export function listCart() {
  return http.get('/me/cart', { silent: true })
}

export function addToCart(listingId) {
  return http.post('/me/cart', { listing_id: listingId })
}

export function removeFromCart(listingId) {
  return http.del(`/me/cart/${listingId}`)
}

export function clearCart() {
  return http.del('/me/cart')
}

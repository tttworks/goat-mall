/*
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

import { http } from './request'

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

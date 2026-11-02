/*
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

import { http } from './request'

export function listFigures(params) {
  return http.get('/admin/figures', params)
}

export function getFigure(id) {
  return http.get(`/admin/figures/${id}`)
}

export function createFigure(data) {
  return http.post('/admin/figures', data)
}

export function updateFigure(id, data) {
  return http.put(`/admin/figures/${id}`, data)
}

export function changeFigureStatus(id, status) {
  return http.patch(`/admin/figures/${id}/status`, { status })
}

export function deleteFigure(id) {
  return http.del(`/admin/figures/${id}`)
}

/*
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

import { http } from './request'

/** status 省略时后端默认只给 pending */
export function listReports(params) {
  return http.get('/admin/reports', params)
}

/** result: handled（判定成立）| dismissed（判定不成立） */
export function handleReport(reportId, result, note) {
  return http.post(`/admin/reports/${reportId}/handle`, { result, note })
}

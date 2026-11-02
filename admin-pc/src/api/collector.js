/*
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

import { http } from './request'

/**
 * 采集触发（契约 §4）。站点只转发，不做解析：
 * 抓取规则属于采集仓，写在这里就等于把两套权属混在一起。
 */
export function crawlFigure(payload) {
  return http.post('/admin/collector/crawl-figure', payload)
}

export function crawlPrice(payload) {
  return http.post('/admin/collector/crawl-price', payload)
}

export function getJob(jobId) {
  return http.get(`/admin/collector/job/${jobId}`)
}

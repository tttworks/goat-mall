/*
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

import { http } from './request'

/**
 * 行情源登记表。返回 `data`（源列表）+ `meta`（总数/启用数/累计点数/渠道字典）。
 * 首次打开会自动补齐 4 个出厂源，所以空库也能直接用。
 */
export function listMarketSources() {
  return http.get('/admin/market-sources')
}

/**
 * 局部更新。只传要改的字段：`source` / `channel` / `collector_key` 后端不接受修改
 * —— 改名等于换数据来源，应该新增一条而不是改旧的。
 * `enabled=false` 对站内成交源会返回 422（详情页主价签只认它）。
 */
export function updateMarketSource(marketSourceId, data) {
  return http.patch(`/admin/market-sources/${marketSourceId}`, data)
}

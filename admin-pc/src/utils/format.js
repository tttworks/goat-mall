/*
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

/** 状态 → 文案 / 标签色。集中一处，避免每个页面各写一套 if */
export const FIGURE_STATUS = {
  draft: { label: '草稿', type: 'info' },
  published: { label: '已发布', type: 'success' },
  archived: { label: '已归档', type: 'warning' }
}

export const LISTING_STATUS = {
  draft: { label: '草稿', type: 'info' },
  pending_review: { label: '待审核', type: 'warning' },
  active: { label: '在售', type: 'success' },
  reserved: { label: '已锁定', type: 'primary' },
  sold: { label: '已售出', type: 'success' },
  delisted: { label: '已下架', type: 'info' },
  rejected: { label: '已驳回', type: 'danger' }
}

export const SELLER_STATUS = {
  draft: { label: '草稿', type: 'info' },
  pending: { label: '待审核', type: 'warning' },
  active: { label: '正常营业', type: 'success' },
  suspended: { label: '已封禁', type: 'danger' }
}

export const POST_STATUS = {
  pending: { label: '待审核', type: 'warning' },
  approved: { label: '已通过', type: 'success' },
  rejected: { label: '已驳回', type: 'danger' },
  withdrawn: { label: '作者撤回', type: 'info' }
}

export const REPORT_STATUS = {
  pending: { label: '待处理', type: 'warning' },
  handled: { label: '已处理', type: 'success' },
  dismissed: { label: '不成立', type: 'info' }
}

export const CONDITION_LABELS = {
  new: '全新',
  like_new: '几乎全新',
  good: '轻微使用',
  fair: '明显使用',
  poor: '瑕疵品'
}

export function statusOf(map, value) {
  return map[value] || { label: value || '-', type: 'info' }
}

/** 金额只做展示：后端给字符串小数，不参与任何计算 */
export function money(value) {
  const text = String(value ?? '').trim()
  if (!text) return '-'
  const [integer, decimal = ''] = text.split('.')
  const trimmed = decimal.replace(/0+$/, '')
  const grouped = integer.replace(/\B(?=(\d{3})+(?!\d))/g, ',')
  return `¥${grouped}${trimmed ? `.${trimmed}` : ''}`
}

export function dateTime(value) {
  if (!value) return '-'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return String(value)
  const pad = (n) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`
}

/** 后端分页 meta 字段不统一（current_page / page），这里一次抹平 */
export function pagerOf(meta, fallbackPerPage = 20) {
  return {
    total: Number(meta?.total ?? 0),
    page: Number(meta?.current_page ?? meta?.page ?? 1),
    perPage: Number(meta?.per_page ?? fallbackPerPage)
  }
}

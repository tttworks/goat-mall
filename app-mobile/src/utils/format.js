/*
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

/**
 * 展示层格式化。
 *
 * 金额在后端一律是「字符串小数」（如 "1280.00"），前端只做千分位与去尾零，
 * 不做任何浮点运算——精度问题必须留在 Money 里解决，不能漏到展示层。
 */
export function money(value) {
  if (value === null || value === undefined || value === '') return '—'

  const raw = String(value)
  const [intPart, decimalPart] = raw.split('.')
  const grouped = (intPart || '0').replace(/\B(?=(\d{3})+(?!\d))/g, ',')
  const decimal = (decimalPart || '').replace(/0+$/, '')

  return decimal ? `${grouped}.${decimal}` : grouped
}

export function dateTime(value) {
  if (!value) return ''

  const text = String(value).replace('T', ' ').replace(/-/g, '/')
  return text.slice(0, 16)
}

export function relativeTime(value) {
  if (!value) return ''

  const target = new Date(String(value).replace(/-/g, '/').replace('T', ' ')).getTime()
  if (Number.isNaN(target)) return dateTime(value)

  const diff = Date.now() - target
  const minute = 60 * 1000
  const hour = 60 * minute
  const day = 24 * hour

  if (diff < minute) return '刚刚'
  if (diff < hour) return `${Math.floor(diff / minute)} 分钟前`
  if (diff < day) return `${Math.floor(diff / hour)} 小时前`
  if (diff < 7 * day) return `${Math.floor(diff / day)} 天前`

  return dateTime(value).slice(0, 10)
}

/** 成色：与后端 ListingCondition 枚举保持一致 */
export const CONDITION_LABELS = {
  new: '全新未拆',
  like_new: '仅拆检视',
  used: '有使用痕迹',
  flawed: '有瑕疵'
}

export function conditionLabel(value) {
  return CONDITION_LABELS[value] || '成色未标注'
}

/** 订单状态：文案与配色都集中在这里，页面不再各写一套 if */
export const ORDER_STATUS = {
  pending_payment: { label: '待付款', tone: 'accent', action: 'pay' },
  paid: { label: '待发货', tone: 'accent', action: 'ship' },
  seller_shipped: { label: '待收货', tone: 'accent', action: 'confirm' },
  buyer_confirmed: { label: '待评价', tone: 'normal', action: 'review' },
  completed: { label: '已完成', tone: 'normal', action: 'none' },
  cancelled: { label: '已取消', tone: 'dim', action: 'none' },
  refunding: { label: '退款中', tone: 'danger', action: 'none' },
  refunded: { label: '已退款', tone: 'dim', action: 'none' }
}

export function orderStatus(value) {
  return ORDER_STATUS[value] || { label: value || '未知', tone: 'dim', action: 'none' }
}

export const POST_STATUS = {
  pending: '审核中',
  approved: '已发布',
  rejected: '未通过',
  withdrawn: '已撤回'
}

export function postStatus(value) {
  return POST_STATUS[value] || value || ''
}

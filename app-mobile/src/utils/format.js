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

/**
 * 成色：与后端入参白名单一致（ListingStoreRequest / ListingUpdateRequest）。
 *
 * 早期曾写成 used / flawed 这类自造值，导致卖家详情页显示「成色未标注」，
 * 搜索筛选也永远筛不到——文案映射必须与校验白名单同源。
 */
export const CONDITION_LABELS = {
  new: '全新未拆',
  like_new: '仅拆检视',
  used_good: '有轻微痕迹',
  used_fair: '使用痕迹明显',
  defective: '有瑕疵',
  for_parts: '仅作配件',
  // 兼容历史数据
  used: '有使用痕迹',
  flawed: '有瑕疵'
}

/** 成色选项（卖家表单用），顺序即推荐顺序 */
export const CONDITION_OPTIONS = [
  { value: 'new', label: CONDITION_LABELS.new },
  { value: 'like_new', label: CONDITION_LABELS.like_new },
  { value: 'used_good', label: CONDITION_LABELS.used_good },
  { value: 'used_fair', label: CONDITION_LABELS.used_fair },
  { value: 'defective', label: CONDITION_LABELS.defective },
  { value: 'for_parts', label: CONDITION_LABELS.for_parts }
]

export function conditionLabel(value) {
  return CONDITION_LABELS[value] || '成色未标注'
}

/**
 * 订单状态：文案与配色都集中在这里，页面不再各写一套 if。
 *
 * action 是「买家侧」动作；卖家侧由页面按角色再映射一次（同状态下买卖双方能做的事不同）。
 * completed 才是可评价状态（后端 ReviewService 只接受 completed），
 * 若把它映射成 none，确认收货后「评价卖家」按钮就永远不出现。
 */
export const ORDER_STATUS = {
  pending_payment: { label: '待付款', tone: 'accent', action: 'pay' },
  paid: { label: '待发货', tone: 'accent', action: 'ship' },
  seller_shipped: { label: '待收货', tone: 'accent', action: 'confirm' },
  buyer_confirmed: { label: '已确认收货', tone: 'normal', action: 'none' },
  completed: { label: '已完成', tone: 'normal', action: 'review' },
  cancelled: { label: '已取消', tone: 'dim', action: 'none' },
  refunding: { label: '退款中', tone: 'danger', action: 'none' },
  refunded: { label: '已退款', tone: 'dim', action: 'none' },
  closed: { label: '已关闭', tone: 'dim', action: 'none' }
}

export function orderStatus(value) {
  return ORDER_STATUS[value] || { label: value || '未知', tone: 'dim', action: 'none' }
}

/** 商品状态（卖家后台筛选用）：与 ListingStatus 一一对应 */
export const LISTING_STATUS = {
  draft: { label: '草稿', tone: 'dim' },
  pending_review: { label: '待审', tone: 'accent' },
  rejected: { label: '已驳回', tone: 'danger' },
  active: { label: '在售', tone: 'success' },
  reserved: { label: '已锁定', tone: 'accent' },
  sold: { label: '已售出', tone: 'dim' },
  delisted: { label: '已下架', tone: 'dim' }
}

export function listingStatus(value) {
  return LISTING_STATUS[value] || { label: value || '未知', tone: 'dim' }
}

/** 卖家可点的动作：与后端状态机一一对应，前端只决定「按钮显不显示」 */
export function listingActions(listing) {
  const actions = []

  if (listing.status === 'draft' || listing.status === 'rejected' || listing.status === 'delisted') {
    // rejected/delisted 提交时服务端会先回 draft 再转待审，前端不必分两步
    actions.push({ key: 'submit', label: '提交审核' })
  }
  if (listing.status === 'active') {
    actions.push({ key: 'delist', label: '下架' })
  }
  if (listing.status === 'delisted') {
    actions.push({ key: 'reopen', label: '退回草稿' })
  }
  if (['draft', 'rejected', 'active'].includes(listing.status)) {
    actions.push({ key: 'edit', label: '编辑' })
  }

  return actions
}

/** 退款单状态：与 RefundStatus 一致 */
export const REFUND_STATUS = {
  pending: { label: '待审核', tone: 'accent' },
  refunded: { label: '已退款', tone: 'success' },
  rejected: { label: '已驳回', tone: 'danger' },
  failed: { label: '退款失败', tone: 'danger' }
}

export function refundStatus(value) {
  return REFUND_STATUS[value] || { label: value || '未知', tone: 'dim' }
}

/** 实名状态：与 VerificationStatus 一致 */
export const VERIFICATION_STATUS = {
  none: '未实名',
  pending: '核身中',
  approved: '已实名',
  rejected: '核身失败'
}

export function verificationLabel(value) {
  return VERIFICATION_STATUS[value] || '未实名'
}

/** 竞拍状态：与 AuctionStatus 一致 */
export const AUCTION_STATUS = {
  draft: '草稿',
  scheduled: '未开始',
  active: '竞拍中',
  ended: '已结束',
  settled: '已成交',
  failed: '流拍',
  cancelled: '已撤销'
}

export function auctionStatusLabel(value) {
  return AUCTION_STATUS[value] || value || ''
}

/**
 * 剩余时间（竞拍倒计时）。
 * 输入 ISO 时间，输出「1 天 02:03:04」这种紧凑文案；已结束返回「已结束」。
 */
export function countdown(value) {
  if (!value) return '—'

  const target = new Date(String(value).replace(/-/g, '/').replace('T', ' ')).getTime()
  if (Number.isNaN(target)) return '—'

  let diff = Math.floor((target - Date.now()) / 1000)
  if (diff <= 0) return '已结束'

  const day = Math.floor(diff / 86400)
  diff -= day * 86400
  const hour = String(Math.floor(diff / 3600)).padStart(2, '0')
  diff -= Math.floor(diff / 3600) * 3600
  const minute = String(Math.floor(diff / 60)).padStart(2, '0')
  const second = String(diff % 60).padStart(2, '0')

  return day > 0 ? `${day} 天 ${hour}:${minute}:${second}` : `${hour}:${minute}:${second}`
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

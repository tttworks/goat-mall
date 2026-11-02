/*
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

/**
 * 交互小工具。
 *
 * 把 uni.showModal 的回调式 API 包成 Promise，业务里就能写 await confirmModal(...)，
 * 不必到处嵌套 success 回调（这类嵌套是页面上「点了没反应」的常见来源）。
 */
export function confirmModal({ title = '确认操作', content = '', confirmText = '确定', cancelText = '取消' } = {}) {
  return new Promise((resolve) => {
    uni.showModal({
      title,
      content,
      confirmText,
      cancelText,
      confirmColor: '#0B0B0C',
      success: (res) => resolve(Boolean(res.confirm)),
      fail: () => resolve(false)
    })
  })
}

export function toast(title, icon = 'none') {
  uni.showToast({ title, icon, duration: 2000 })
}

export function back(delta = 1) {
  const pages = typeof getCurrentPages === 'function' ? getCurrentPages() : []

  if (pages.length > delta) {
    uni.navigateBack({ delta })
    return
  }

  // 直接从分享链接进来时没有上一页，回首页而不是卡在空白页
  uni.switchTab({ url: '/pages/index/index' })
}

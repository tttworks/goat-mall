/*
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

import { createSSRApp } from 'vue'
import App from './App.vue'

/**
 * uni-app 四端统一入口：createSSRApp 由运行时注入，
 * 不要改成 createApp，否则小程序端首屏会白屏。
 */
export function createApp() {
  const app = createSSRApp(App)

  return { app }
}

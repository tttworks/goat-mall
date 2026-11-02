/*
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

import { defineConfig } from 'vite'
import uni from '@dcloudio/vite-plugin-uni'

/**
 * 开发期用代理直连本机 Laravel（php artisan serve :8000）。
 * 好处：H5 侧不写死域名、不触发跨域预检，真机 H5 也能连同一台机器。
 * 生产 H5 由 nginx 同域反代，因此 .env.production 里改成相对路径即可。
 */
export default defineConfig({
  plugins: [uni()],
  server: {
    port: 5173,
    host: '0.0.0.0',
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true
      }
    }
  }
})

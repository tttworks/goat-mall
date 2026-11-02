/*
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

/**
 * base 走环境变量：本地开发与独立域名部署仍是 '/'，
 * 挂到站点子路径（临时站 /admin/）时用 `VITE_APP_BASE=/admin/ npm run build`，
 * 与 router 的 createWebHistory(import.meta.env.BASE_URL) 自动对齐。
 */
export default defineConfig({
  plugins: [vue()],
  base: process.env.VITE_APP_BASE || '/',
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url))
    }
  },
  server: {
    port: 5174,
    // 开发期直连本机 Laravel：不配 CORS，也不在前端写死域名
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true
      }
    }
  },
  build: {
    outDir: 'dist',
    chunkSizeWarningLimit: 1200,
    rollupOptions: {
      output: {
        // 组件库与框架各自成块：业务代码改动不会让 1MB 的 vendor 缓存失效
        manualChunks: {
          vue: ['vue', 'vue-router'],
          element: ['element-plus', '@element-plus/icons-vue']
        }
      }
    }
  }
})

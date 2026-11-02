/*
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

import { createRouter, createWebHistory } from 'vue-router'
import { getToken } from '../api/request'
import { session } from '../store/session'

const routes = [
  {
    path: '/login',
    name: 'login',
    component: () => import('../views/LoginView.vue'),
    meta: { public: true, title: '登录' }
  },
  {
    path: '/',
    component: () => import('../layouts/AdminLayout.vue'),
    redirect: '/figures',
    children: [
      {
        path: 'figures',
        name: 'figures',
        component: () => import('../views/FigureListView.vue'),
        meta: { title: '图鑑管理' }
      },
      {
        path: 'sellers',
        name: 'sellers',
        component: () => import('../views/SellerReviewView.vue'),
        meta: { title: '卖家审核' }
      },
      {
        path: 'listings',
        name: 'listings',
        component: () => import('../views/ListingReviewView.vue'),
        meta: { title: '商品审核' }
      },
      {
        path: 'posts',
        name: 'posts',
        component: () => import('../views/PostReviewView.vue'),
        meta: { title: '帖子审核' }
      },
      {
        path: 'reports',
        name: 'reports',
        component: () => import('../views/ReportView.vue'),
        meta: { title: '举报处理' }
      },
      {
        path: 'orders',
        name: 'orders',
        component: () => import('../views/OrderView.vue'),
        meta: { title: '订单管理' }
      },
      {
        path: 'refunds',
        name: 'refunds',
        component: () => import('../views/RefundView.vue'),
        meta: { title: '退款审核' }
      },
      {
        path: 'auctions',
        name: 'auctions',
        component: () => import('../views/AuctionView.vue'),
        meta: { title: '竞价管理' }
      },
      {
        path: 'fees',
        name: 'fees',
        component: () => import('../views/FeeRuleView.vue'),
        meta: { title: '费率配置' }
      },
      {
        path: 'market-sources',
        name: 'market-sources',
        component: () => import('../views/MarketSourceView.vue'),
        meta: { title: '行情源配置' }
      },
      {
        path: 'collector',
        name: 'collector',
        component: () => import('../views/CollectorView.vue'),
        meta: { title: '采集触发' }
      }
    ]
  },
  { path: '/:pathMatch(.*)*', redirect: '/figures' }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

router.beforeEach((to) => {
  const logged = Boolean(getToken()) && Boolean(session.user)

  if (!to.meta.public && !logged) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  if (to.name === 'login' && logged) {
    return { name: 'figures' }
  }

  return true
})

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} · GOAT 运营后台` : 'GOAT 潮玩 · 运营后台'
})

export default router

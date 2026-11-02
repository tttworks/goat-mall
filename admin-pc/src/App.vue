<!--
  GOAT 潮玩交易平台 / GOAT 潮玩数据采集
  Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
  Author: Aloysius Luo
  本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
-->
<template>
  <router-view />
</template>

<script setup>
import { onMounted } from 'vue'
import { refreshUser } from './store/session'

onMounted(() => {
  // 刷新页面后用 Token 回填用户信息：localStorage 只是缓存，角色以服务端为准
  refreshUser()
})
</script>

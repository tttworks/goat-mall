<!--
  GOAT 潮玩交易平台 / GOAT 潮玩数据采集
  Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
  Author: Aloysius Luo
  本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
-->
<template>
  <el-container class="shell">
    <el-aside width="228px" class="aside">
      <div class="brand">
        <span class="brand-mark">GOAT</span>
        <span class="brand-sub">运营后台</span>
      </div>

      <el-menu router :default-active="activePath" class="menu" :collapse="false">
        <el-menu-item index="/figures">
          <el-icon><Collection /></el-icon>
          <span>图鑑管理</span>
        </el-menu-item>
        <el-menu-item index="/sellers">
          <el-icon><Shop /></el-icon>
          <span>卖家审核</span>
        </el-menu-item>
        <el-menu-item index="/listings">
          <el-icon><Goods /></el-icon>
          <span>商品审核</span>
        </el-menu-item>
        <el-menu-item index="/posts">
          <el-icon><ChatDotSquare /></el-icon>
          <span>帖子审核</span>
        </el-menu-item>
        <el-menu-item index="/orders">
          <el-icon><List /></el-icon>
          <span>订单管理</span>
        </el-menu-item>
        <el-menu-item index="/refunds">
          <el-icon><Wallet /></el-icon>
          <span>退款审核</span>
        </el-menu-item>
        <el-menu-item index="/auctions">
          <el-icon><Timer /></el-icon>
          <span>竞价管理</span>
        </el-menu-item>
        <el-menu-item index="/fees">
          <el-icon><Coin /></el-icon>
          <span>费率配置</span>
        </el-menu-item>
        <el-menu-item index="/collector">
          <el-icon><Download /></el-icon>
          <span>采集触发</span>
        </el-menu-item>
      </el-menu>

      <div class="aside-foot">
        <p class="version">V1.0.0</p>
        <p class="copyright">火山文化科技</p>
      </div>
    </el-aside>

    <el-container>
      <el-header class="header">
        <div class="header-left">
          <span class="header-title">{{ pageTitle }}</span>
          <el-tag size="small" type="success" effect="plain">生产</el-tag>
        </div>

        <el-dropdown trigger="click" @command="onCommand">
          <span class="user">
            <el-avatar :size="28" class="avatar">{{ avatarText }}</el-avatar>
            <span class="user-name">{{ session.user?.nickname || session.user?.username || '未登录' }}</span>
            <el-icon><ArrowDown /></el-icon>
          </span>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item command="profile" disabled>{{ session.user?.username }}</el-dropdown-item>
              <el-dropdown-item command="logout" divided>退出登录</el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </el-header>

      <el-main class="main">
        <router-view />
      </el-main>
    </el-container>
  </el-container>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { session, signOut } from '../store/session'

const route = useRoute()
const router = useRouter()

const activePath = computed(() => route.path)
const pageTitle = computed(() => route.meta.title || '运营后台')
const avatarText = computed(() => {
  const name = session.user?.nickname || session.user?.username || 'G'
  return String(name).slice(0, 1).toUpperCase()
})

async function onCommand(command) {
  if (command !== 'logout') return

  try {
    await ElMessageBox.confirm('退出后需要重新登录才能继续审核。', '确认退出', {
      confirmButtonText: '退出',
      cancelButtonText: '取消',
      type: 'warning'
    })
  } catch (e) {
    return
  }

  await signOut()
  ElMessage.success('已退出登录')
  router.replace({ name: 'login' })
}
</script>

<style scoped lang="scss">
.shell {
  height: 100vh;
}

.aside {
  display: flex;
  flex-direction: column;
  background: var(--goat-ink);
  color: #fff;
}

.brand {
  display: flex;
  align-items: baseline;
  gap: 8px;
  padding: 22px 20px 18px;
}

.brand-mark {
  font-size: 20px;
  font-weight: 800;
  letter-spacing: 1px;
  color: var(--goat-accent);
}

.brand-sub {
  font-size: 12px;
  color: rgba(255, 255, 255, 0.55);
}

.menu {
  flex: 1;
  border: 0;
  background: transparent;

  :deep(.el-menu-item) {
    color: rgba(255, 255, 255, 0.68);
    height: 44px;
    margin: 2px 10px;
    border-radius: 8px;
  }

  :deep(.el-menu-item:hover) {
    background: rgba(255, 255, 255, 0.06);
    color: #fff;
  }

  :deep(.el-menu-item.is-active) {
    background: var(--goat-accent);
    color: var(--goat-ink);
    font-weight: 600;
  }
}

.aside-foot {
  padding: 16px 20px 20px;
  border-top: 1px solid rgba(255, 255, 255, 0.08);
}

.version,
.copyright {
  margin: 0;
  font-size: 11px;
  color: rgba(255, 255, 255, 0.4);
  line-height: 1.8;
}

.header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #fff;
  border-bottom: 1px solid var(--goat-line);
  height: 60px;
}

.header-left {
  display: flex;
  align-items: center;
  gap: 10px;
}

.header-title {
  font-size: 15px;
  font-weight: 600;
}

.user {
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  font-size: 13px;
  outline: none;
}

.avatar {
  background: var(--goat-ink);
  color: var(--goat-accent);
  font-weight: 700;
}

.user-name {
  max-width: 140px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.main {
  padding: 20px 22px 32px;
  background: #f5f5f6;
  overflow-y: auto;
}
</style>

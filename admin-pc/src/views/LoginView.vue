<!--
  GOAT 潮玩交易平台 / GOAT 潮玩数据采集
  Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
  Author: Aloysius Luo
  本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
-->
<template>
  <div class="login">
    <section class="panel">
      <div class="brand">
        <span class="mark">GOAT</span>
        <span class="tagline">潮玩交易平台 · 运营后台</span>
      </div>
      <h1 class="slogan">把每一只潮玩<br />管到可追溯</h1>
      <ul class="points">
        <li>图鑑条目发布与归档</li>
        <li>卖家实名与店铺审核</li>
        <li>商品与社区内容人工复核</li>
      </ul>
      <p class="copyright">火山文化科技 · V1.0.0</p>
    </section>

    <section class="form-side">
      <el-card class="card" shadow="never">
        <h2 class="card-title">登录运营后台</h2>
        <p class="card-desc">仅限管理员账号。普通用户请使用 C 端小程序 / H5。</p>

        <el-form ref="formRef" :model="form" :rules="rules" label-position="top" @submit.prevent>
          <el-form-item label="账号 / 邮箱" prop="login">
            <el-input v-model="form.login" size="large" placeholder="admin" autocomplete="username" />
          </el-form-item>
          <el-form-item label="密码" prop="password">
            <el-input
              v-model="form.password"
              type="password"
              size="large"
              show-password
              placeholder="••••••••"
              autocomplete="current-password"
              @keyup.enter="submit"
            />
          </el-form-item>

          <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon class="alert" />

          <el-button type="primary" size="large" class="submit" :loading="loading" @click="submit">
            登录
          </el-button>
        </el-form>

        <p class="hint">
          首次部署请执行 <code>php artisan db:seed --class=AdminUserSeeder</code> 创建管理员；
          口令由 <code>GOAT_ADMIN_PASSWORD</code> 指定，留空则随机生成并在命令行打印一次。
        </p>
      </el-card>
    </section>
  </div>
</template>

<script setup>
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { signIn } from '../store/session'

const route = useRoute()
const router = useRouter()

const formRef = ref(null)
const loading = ref(false)
const error = ref('')

const form = reactive({ login: '', password: '' })

const rules = {
  login: [{ required: true, message: '请输入账号', trigger: 'blur' }],
  password: [{ required: true, message: '请输入密码', trigger: 'blur' }]
}

async function submit() {
  if (!formRef.value) return

  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return

  loading.value = true
  error.value = ''

  try {
    await signIn(form.login.trim(), form.password)
    ElMessage.success('登录成功')
    // 只允许内部路径回跳，避免开放重定向
    const redirect = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/')
      ? route.query.redirect
      : '/figures'
    router.replace(redirect)
  } catch (e) {
    error.value = e.message || '登录失败'
  } finally {
    loading.value = false
  }
}
</script>

<style scoped lang="scss">
.login {
  display: grid;
  grid-template-columns: 1.1fr 0.9fr;
  min-height: 100vh;
}

.panel {
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 24px;
  padding: 64px clamp(40px, 7vw, 96px);
  background: radial-gradient(circle at 18% 20%, #21212a 0%, var(--goat-ink) 55%);
  color: #fff;
}

.brand {
  display: flex;
  align-items: baseline;
  gap: 10px;
}

.mark {
  font-size: 30px;
  font-weight: 800;
  letter-spacing: 1px;
  color: var(--goat-accent);
}

.tagline {
  font-size: 12px;
  color: rgba(255, 255, 255, 0.5);
}

.slogan {
  margin: 0;
  font-size: clamp(30px, 3.4vw, 44px);
  line-height: 1.25;
  letter-spacing: -0.5px;
}

.points {
  margin: 0;
  padding-left: 18px;
  color: rgba(255, 255, 255, 0.66);
  font-size: 14px;
  line-height: 2;
}

.copyright {
  margin: 0;
  font-size: 11px;
  color: rgba(255, 255, 255, 0.32);
}

.form-side {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 40px;
  background: #f5f5f6;
}

.card {
  width: 100%;
  max-width: 400px;
  border-radius: 16px;
  border: 1px solid var(--goat-line);
}

.card-title {
  margin: 4px 0 6px;
  font-size: 20px;
}

.card-desc {
  margin: 0 0 22px;
  font-size: 13px;
  color: var(--goat-text-dim);
  line-height: 1.6;
}

.submit {
  width: 100%;
  margin-top: 4px;
}

.alert {
  margin-bottom: 14px;
}

.hint {
  margin: 18px 0 0;
  font-size: 12px;
  color: var(--goat-text-dim);
  line-height: 1.7;

  code {
    padding: 1px 5px;
    background: #f0f0f2;
    border-radius: 4px;
    font-size: 11px;
  }
}
</style>

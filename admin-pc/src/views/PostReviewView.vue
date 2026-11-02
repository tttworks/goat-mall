<!--
  GOAT 潮玩交易平台 / GOAT 潮玩数据采集
  Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
  Author: Aloysius Luo
  本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
-->
<template>
  <div class="page">
    <div class="page-head">
      <div>
        <h2 class="page-title">帖子审核</h2>
        <p class="page-desc">
          机审「存疑」的内容落到这里人工复核。机审失败一律转人工，不会 fail-open 直接放出。
        </p>
      </div>
      <el-button @click="reload(1)">
        <el-icon><Refresh /></el-icon>
        刷新
      </el-button>
    </div>

    <div class="toolbar">
      <el-radio-group v-model="status" @change="reload(1)">
        <el-radio-button value="pending">待审核</el-radio-button>
        <el-radio-button value="approved">已通过</el-radio-button>
        <el-radio-button value="rejected">已驳回</el-radio-button>
      </el-radio-group>
      <span class="cell-sub">共 {{ pager.total }} 条</span>
    </div>

    <div v-loading="loading" class="post-list">
      <el-empty v-if="!rows.length" description="当前队列为空" />

      <el-card v-for="row in rows" :key="row.post_id" class="post-card" shadow="never">
        <div class="post-head">
          <div class="author">
            <el-avatar :size="32" :src="row.author?.avatar_url || undefined">
              {{ (row.author?.nickname || 'G').slice(0, 1) }}
            </el-avatar>
            <div>
              <div class="cell-title">{{ row.author?.nickname || row.author?.username }}</div>
              <div class="cell-sub mono">UID {{ row.author?.user_id }} · {{ dateTime(row.created_at) }}</div>
            </div>
          </div>

          <div class="post-head-right">
            <el-tag :type="statusOf(POST_STATUS, row.status).type" size="small">
              {{ row.status_label || statusOf(POST_STATUS, row.status).label }}
            </el-tag>
            <el-tag v-if="row.moderation?.provider" type="info" size="small" effect="plain">
              机审：{{ row.moderation.provider }} / {{ row.moderation.result || '-' }}
            </el-tag>
          </div>
        </div>

        <p class="post-body">{{ row.content }}</p>

        <div v-if="row.images?.length" class="post-images">
          <el-image
            v-for="(url, index) in row.images"
            :key="index"
            :src="url"
            :preview-src-list="row.images"
            :initial-index="index"
            fit="cover"
            class="post-image"
          />
        </div>

        <el-alert
          v-if="row.reject_reason"
          :title="`驳回原因：${row.reject_reason}`"
          type="error"
          :closable="false"
          class="post-reason"
        />

        <div v-if="row.status === 'pending'" class="post-actions">
          <el-button type="primary" size="small" @click="review(row, 'approve')">通过并发布</el-button>
          <el-button type="danger" size="small" plain @click="review(row, 'reject')">驳回</el-button>
        </div>
      </el-card>
    </div>

    <div class="table-foot">
      <el-pagination
        layout="total, prev, pager, next"
        :total="pager.total"
        :current-page="pager.page"
        :page-size="pager.perPage"
        @current-change="reload"
      />
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { listPosts, reviewPost } from '../api/posts'
import { POST_STATUS, dateTime, pagerOf, statusOf } from '../utils/format'

const loading = ref(false)
const rows = ref([])
const status = ref('pending')
const pager = reactive({ total: 0, page: 1, perPage: 10 })

async function reload(page = pager.page) {
  loading.value = true
  try {
    const payload = await listPosts({ status: status.value, page })
    rows.value = payload?.data || []
    const meta = pagerOf(payload?.meta, pager.perPage)
    pager.total = meta.total
    pager.perPage = meta.perPage
    pager.page = Number(page) || meta.page
  } catch (e) {
    ElMessage.error(e.message)
  } finally {
    loading.value = false
  }
}

async function review(row, action) {
  let reason = ''

  if (action === 'approve') {
    try {
      await ElMessageBox.confirm('通过后帖子立刻进入社群 feed，确认？', '通过审核', {
        confirmButtonText: '通过',
        cancelButtonText: '取消'
      })
    } catch (e) {
      return
    }
  } else {
    try {
      const result = await ElMessageBox.prompt('驳回原因（必填，作者可见）', '驳回帖子', {
        confirmButtonText: '驳回',
        cancelButtonText: '取消',
        inputType: 'textarea',
        inputValidator: (value) => (value && value.trim().length >= 2 ? true : '请填写至少 2 个字')
      })
      reason = result.value.trim()
    } catch (e) {
      return
    }
  }

  try {
    await reviewPost(row.post_id, action, reason || undefined)
    ElMessage.success('已处理')
    reload()
  } catch (e) {
    ElMessage.error(e.message)
  }
}

onMounted(() => reload(1))
</script>

<style scoped lang="scss">
.post-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.post-card {
  border-radius: 12px;
  border: 1px solid var(--goat-line);
}

.post-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.author {
  display: flex;
  align-items: center;
  gap: 10px;
}

.post-head-right {
  display: flex;
  align-items: center;
  gap: 8px;
}

.post-body {
  margin: 14px 0 0;
  font-size: 14px;
  line-height: 1.75;
  white-space: pre-wrap;
  word-break: break-word;
}

.post-images {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 12px;
}

.post-image {
  width: 96px;
  height: 96px;
  border-radius: 8px;
  background: #f0f0f2;
}

.post-reason {
  margin-top: 12px;
}

.post-actions {
  display: flex;
  gap: 10px;
  margin-top: 14px;
}
</style>

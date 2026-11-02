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
        <h2 class="page-title">举报处理</h2>
        <p class="page-desc">
          用户对商品 / 帖子 / 评论 / 用户的举报队列，默认先到先判。
          这里只做「是否违规」的判定；下架商品、撤回帖子仍走各自的管理入口，责任链留痕。
        </p>
      </div>
      <el-button @click="reload(1)">
        <el-icon><Refresh /></el-icon>
        刷新
      </el-button>
    </div>

    <div class="toolbar">
      <el-radio-group v-model="status" @change="reload(1)">
        <el-radio-button value="pending">待处理</el-radio-button>
        <el-radio-button value="handled">已处理</el-radio-button>
        <el-radio-button value="dismissed">不成立</el-radio-button>
      </el-radio-group>
      <span class="cell-sub">共 {{ pager.total }} 条</span>
    </div>

    <el-table v-loading="loading" :data="rows" border stripe>
      <el-table-column label="举报对象" min-width="260">
        <template #default="{ row }">
          <div class="target">
            <el-tag size="small" effect="plain">{{ row.target_type_label }}</el-tag>
            <span class="target-title">{{ row.target_title }}</span>
          </div>
          <div class="cell-sub mono">#{{ row.target_id }} · 归属 UID {{ row.target_owner_id || '-' }}</div>
        </template>
      </el-table-column>

      <el-table-column label="理由 / 说明" min-width="260">
        <template #default="{ row }">
          <div class="cell-title">{{ row.reason_label }}</div>
          <div class="cell-sub">{{ row.detail || '（未填写补充说明）' }}</div>
        </template>
      </el-table-column>

      <el-table-column label="举报人" width="150">
        <template #default="{ row }">
          <div>{{ row.reporter?.nickname || row.reporter?.username || '（已注销）' }}</div>
          <div class="cell-sub mono">UID {{ row.reporter?.user_id || '-' }}</div>
        </template>
      </el-table-column>

      <el-table-column label="提交时间" width="170">
        <template #default="{ row }">
          <span class="cell-sub mono">{{ dateTime(row.created_at) }}</span>
        </template>
      </el-table-column>

      <el-table-column label="状态" width="120">
        <template #default="{ row }">
          <el-tag :type="statusOf(REPORT_STATUS, row.status).type" size="small">
            {{ row.status_label }}
          </el-tag>
        </template>
      </el-table-column>

      <el-table-column label="处置" width="200" fixed="right">
        <template #default="{ row }">
          <div v-if="row.status === 'pending'" class="row-actions">
            <el-button type="danger" size="small" plain @click="openHandle(row, 'handled')">成立</el-button>
            <el-button size="small" @click="openHandle(row, 'dismissed')">不成立</el-button>
          </div>
          <div v-else>
            <div class="cell-sub">{{ row.handler?.nickname || row.handler?.username || '系统' }}</div>
            <div class="cell-sub">{{ dateTime(row.handled_at) }}</div>
            <div class="cell-sub note">{{ row.handle_note || '—' }}</div>
          </div>
        </template>
      </el-table-column>
    </el-table>

    <div class="table-foot">
      <el-pagination
        layout="total, prev, pager, next"
        :total="pager.total"
        :current-page="pager.page"
        :page-size="pager.perPage"
        @current-change="reload"
      />
    </div>

    <el-dialog v-model="dialog.visible" :title="dialog.result === 'handled' ? '判定成立' : '判定不成立'" width="480px">
      <p class="dialog-tip">
        {{ dialog.result === 'handled'
          ? '判定成立后会给举报人发送通知。请确认你已按平台规则处置（下架/撤回/沟通），备注会展示给举报人。'
          : '判定不成立时，举报人可补充证据后再次举报（复用同一条工单）。' }}
      </p>
      <el-input
        v-model="dialog.note"
        type="textarea"
        :rows="3"
        maxlength="255"
        show-word-limit
        placeholder="处置备注（选填，举报人可见）"
      />
      <template #footer>
        <el-button @click="dialog.visible = false">取消</el-button>
        <el-button type="primary" :loading="dialog.submitting" @click="submitHandle">提交结论</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { handleReport, listReports } from '../api/reports'
import { REPORT_STATUS, dateTime, pagerOf, statusOf } from '../utils/format'

const loading = ref(false)
const rows = ref([])
const status = ref('pending')
const pager = reactive({ total: 0, page: 1, perPage: 20 })

const dialog = reactive({ visible: false, reportId: 0, result: 'handled', note: '', submitting: false })

async function reload(page = pager.page) {
  loading.value = true
  try {
    const payload = await listReports({ status: status.value, page })
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

function openHandle(row, result) {
  dialog.reportId = row.report_id
  dialog.result = result
  dialog.note = ''
  dialog.visible = true
}

async function submitHandle() {
  dialog.submitting = true
  try {
    await handleReport(dialog.reportId, dialog.result, dialog.note.trim() || undefined)
    ElMessage.success('已记录处置结论')
    dialog.visible = false
    reload()
  } catch (e) {
    ElMessage.error(e.message)
  } finally {
    dialog.submitting = false
  }
}

onMounted(() => reload(1))
</script>

<style scoped lang="scss">
.target {
  display: flex;
  align-items: center;
  gap: 8px;
}

.target-title {
  font-size: 14px;
  font-weight: 600;
  word-break: break-all;
}

.note {
  white-space: normal;
}

.row-actions {
  display: flex;
  gap: 8px;
}

.dialog-tip {
  margin: 0 0 12px;
  font-size: 13px;
  line-height: 1.7;
  color: var(--el-text-color-secondary);
}
</style>

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
        <h2 class="page-title">采集触发</h2>
        <p class="page-desc">
          站点只负责「按需触发 + 查进度」，抓什么、怎么抓由采集系统决定（契约 §4）。
          返回 202 表示任务已入队，用 job_id 查进度。
        </p>
      </div>
    </div>

    <el-alert
      v-if="configIssue"
      type="warning"
      :closable="false"
      show-icon
      title="采集系统未配置"
      description="COLLECTOR_BASE_URL / COLLECTOR_HMAC_KEY 为空。这两个值只放服务器环境变量，不要写进代码或前端。"
    />

    <div class="grid">
      <el-card shadow="never" class="card">
        <template #header>
          <div class="card-head">
            <span class="card-title">抓取行情</span>
            <span class="cell-sub">三源：站内 / Mercari JP / eBay</span>
          </div>
        </template>

        <el-form label-position="top">
          <el-form-item label="图鑑 ID">
            <el-input-number v-model="priceForm.figure_id" :min="1" :controls="false" placeholder="1" style="width: 100%" />
          </el-form-item>
          <el-form-item label="来源">
            <el-checkbox-group v-model="priceForm.sources">
              <el-checkbox value="site">站内</el-checkbox>
              <el-checkbox value="mercari_jp">Mercari JP</el-checkbox>
              <el-checkbox value="ebay">eBay</el-checkbox>
            </el-checkbox-group>
          </el-form-item>
          <el-button type="primary" :loading="priceLoading" @click="triggerPrice">触发抓取</el-button>
        </el-form>
      </el-card>

      <el-card shadow="never" class="card">
        <template #header>
          <div class="card-head">
            <span class="card-title">抓取图鑑数据</span>
            <span class="cell-sub">按图鑑 ID 或外部 ID</span>
          </div>
        </template>

        <el-form label-position="top">
          <el-form-item label="图鑑 ID（与外部 ID 二选一）">
            <el-input-number v-model="figureForm.figure_id" :min="1" :controls="false" placeholder="1" style="width: 100%" />
          </el-form-item>
          <el-form-item label="外部 ID（每行一个）">
            <el-input v-model="figureForm.external_text" type="textarea" :rows="3" placeholder="mercari:xxx&#10;ebay:yyy" />
          </el-form-item>
          <el-button type="primary" :loading="figureLoading" @click="triggerFigure">触发抓取</el-button>
        </el-form>
      </el-card>
    </div>

    <el-card shadow="never" class="card">
      <template #header>
        <div class="card-head">
          <span class="card-title">任务结果</span>
          <div class="job-query">
            <el-input v-model="jobId" size="small" placeholder="job_id" style="width: 220px" />
            <el-button size="small" :loading="jobLoading" @click="queryJob">查询进度</el-button>
          </div>
        </div>
      </template>

      <el-empty v-if="!result" description="触发任务或查询 job 后显示原始返回" />
      <pre v-else class="result mono">{{ result }}</pre>
    </el-card>
  </div>
</template>

<script setup>
import { reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { crawlFigure, crawlPrice, getJob } from '../api/collector'

const priceForm = reactive({ figure_id: null, sources: ['site'] })
const figureForm = reactive({ figure_id: null, external_text: '' })

const priceLoading = ref(false)
const figureLoading = ref(false)
const jobLoading = ref(false)
const jobId = ref('')
const result = ref('')
const configIssue = ref(false)

function show(payload) {
  result.value = JSON.stringify(payload, null, 2)
  if (payload?.data?.job_id) jobId.value = String(payload.data.job_id)
}

function handleError(e) {
  configIssue.value = e.payload?.code === 'COLLECTOR_NOT_CONFIGURED'
  result.value = JSON.stringify({ message: e.message, code: e.payload?.code }, null, 2)
  ElMessage.error(e.message)
}

async function triggerPrice() {
  if (!priceForm.figure_id) {
    ElMessage.warning('请填写图鑑 ID')
    return
  }
  priceLoading.value = true
  try {
    show(await crawlPrice({ figure_id: priceForm.figure_id, sources: priceForm.sources }))
    ElMessage.success('已入队')
  } catch (e) {
    handleError(e)
  } finally {
    priceLoading.value = false
  }
}

async function triggerFigure() {
  const externalIds = figureForm.external_text
    .split('\n')
    .map((item) => item.trim())
    .filter(Boolean)

  if (!figureForm.figure_id && !externalIds.length) {
    ElMessage.warning('请填写图鑑 ID 或外部 ID')
    return
  }

  figureLoading.value = true
  try {
    show(
      await crawlFigure({
        figure_id: figureForm.figure_id || undefined,
        external_ids: externalIds.length ? externalIds : undefined
      })
    )
    ElMessage.success('已入队')
  } catch (e) {
    handleError(e)
  } finally {
    figureLoading.value = false
  }
}

async function queryJob() {
  if (!jobId.value.trim()) {
    ElMessage.warning('请填写 job_id')
    return
  }
  jobLoading.value = true
  try {
    show(await getJob(jobId.value.trim()))
  } catch (e) {
    handleError(e)
  } finally {
    jobLoading.value = false
  }
}
</script>

<style scoped lang="scss">
.grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
}

.card {
  border-radius: 12px;
  border: 1px solid var(--goat-line);
}

.card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.card-title {
  font-weight: 600;
}

.job-query {
  display: flex;
  gap: 8px;
}

.result {
  margin: 0;
  padding: 14px;
  background: #0b0b0c;
  color: #d8ff3e;
  border-radius: 10px;
  max-height: 320px;
  overflow: auto;
  line-height: 1.6;
  white-space: pre-wrap;
  word-break: break-word;
}
</style>

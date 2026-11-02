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
        <h2 class="page-title">行情源配置</h2>
        <p class="page-desc">
          这里只说「源」：叫什么、开不开、多久采一次、最近成没成。价格点本身仍由采集系统与站内成交回写写入。
          停用只影响展示与新采集，历史行情一律保留——运营停源的当天，客服仍要能查历史成交价。
        </p>
      </div>
      <el-button :loading="loading" @click="load">
        <el-icon><Refresh /></el-icon>
        刷新
      </el-button>
    </div>

    <el-alert
      v-if="staleRows.length"
      class="alert"
      type="warning"
      :closable="false"
      show-icon
      :title="`${staleRows.length} 个采集源已超期未同步`"
      :description="`${staleRows.map((row) => row.label).join('、')}：超过两个采集周期没成功。先看下面的最近一次错误信息，再去采集系统查任务。`"
    />

    <div class="stat-grid">
      <div class="stat">
        <span class="stat-value">{{ meta.enabled }}<span class="stat-sub">/{{ meta.total }}</span></span>
        <span class="stat-label">启用中的源</span>
      </div>
      <div class="stat">
        <span class="stat-value">{{ meta.points_total }}</span>
        <span class="stat-label">累计行情点</span>
      </div>
      <div class="stat">
        <span class="stat-value">{{ recentTotal }}</span>
        <span class="stat-label">近 30 天入库</span>
      </div>
      <div class="stat">
        <span class="stat-value">{{ meta.figures_total }}</span>
        <span class="stat-label">有行情的图鑑</span>
      </div>
    </div>

    <div class="table-card">
      <el-table v-loading="loading" :data="rows" row-key="market_source_id" style="width: 100%">
        <el-table-column label="行情源" min-width="180">
          <template #default="{ row }">
            <div class="cell-main">
              <strong>{{ row.label }}</strong>
              <el-tag v-if="row.locked" size="small" type="info" effect="plain">锁定</el-tag>
            </div>
            <div class="cell-sub mono">{{ row.source }}</div>
          </template>
        </el-table-column>

        <el-table-column label="渠道" width="130">
          <template #default="{ row }">
            <el-tag size="small" effect="plain" :type="channelType(row.channel)">
              {{ row.channel_label }}
            </el-tag>
            <div class="cell-sub mono">{{ row.collector_key || '不来自采集' }}</div>
          </template>
        </el-table-column>

        <el-table-column label="近 30 天" width="100" align="right">
          <template #default="{ row }">{{ row.stats.recent }}</template>
        </el-table-column>

        <el-table-column label="累计点数" width="100" align="right">
          <template #default="{ row }">{{ row.stats.total }}</template>
        </el-table-column>

        <el-table-column label="覆盖图鑑" width="100" align="right">
          <template #default="{ row }">{{ row.stats.figures }}</template>
        </el-table-column>

        <el-table-column label="采集间隔" width="120">
          <template #default="{ row }">
            <span class="dim">{{ intervalLabel(row.interval_minutes) }}</span>
          </template>
        </el-table-column>

        <el-table-column label="最近同步" min-width="170">
          <template #default="{ row }">
            <el-tag size="small" :type="syncType(row)" effect="plain">{{ syncLabel(row) }}</el-tag>
            <div class="cell-sub">
              {{ row.last_success_at ? dateTime(row.last_success_at) : '还没有成功记录' }}
            </div>
            <el-tooltip v-if="row.last_message" :content="row.last_message" placement="top">
              <div class="cell-sub cell-error">{{ row.last_message }}</div>
            </el-tooltip>
          </template>
        </el-table-column>

        <el-table-column label="最近入库" width="170">
          <template #default="{ row }">
            <span class="dim">{{ row.stats.last_ingested_at ? dateTime(row.stats.last_ingested_at) : '-' }}</span>
          </template>
        </el-table-column>

        <el-table-column label="启用" width="90">
          <template #default="{ row }">
            <el-tooltip :disabled="!row.locked" content="站内成交是详情页主价签的唯一来源，不可停用" placement="top">
              <el-switch
                :model-value="row.enabled"
                :disabled="row.locked || toggling === row.market_source_id"
                @change="(value) => toggle(row, value)"
              />
            </el-tooltip>
          </template>
        </el-table-column>

        <el-table-column label="操作" width="90" fixed="right">
          <template #default="{ row }">
            <el-button link @click="openEdit(row)">编辑</el-button>
          </template>
        </el-table-column>
      </el-table>
    </div>

    <el-card shadow="never" class="hint-card">
      <template #header>
        <span class="card-title">几个口径</span>
      </template>
      <ul class="hint-list">
        <li>
          <strong>停用 ≠ 删除。</strong>
          停用后该源不再出现在买家的多源对比与图鑑标签里，`?source=` 仍可查历史 K 线。
        </li>
        <li>
          <strong>站内成交锁定。</strong>
          <span class="mono">internal</span> 是买家确认收货时回写的成交价，详情页主价签只认它；关掉等于全站显示「暂无成交」。
        </li>
        <li>
          <strong>采集标识只读。</strong>
          <span class="mono">collector_key</span> 是站点与采集系统的连线（如 <span class="mono">mercari_jp</span>），
          改错会打错源，所以不开放编辑。
        </li>
      </ul>
    </el-card>

    <el-dialog v-model="dialogVisible" title="编辑行情源" width="520px" destroy-on-close>
      <el-form :model="form" label-width="96px">
        <el-form-item label="源标识">
          <el-input :model-value="editing?.source" disabled />
        </el-form-item>

        <el-form-item label="显示名称">
          <el-input v-model="form.label" maxlength="32" show-word-limit placeholder="买家看到的源名称" />
        </el-form-item>

        <div class="form-grid">
          <el-form-item label="计价币种">
            <el-input v-model="form.currency" maxlength="3" placeholder="CNY" />
          </el-form-item>

          <el-form-item label="排序权重">
            <el-input-number v-model="form.priority" :min="0" :max="9999" controls-position="right" style="width: 100%" />
          </el-form-item>
        </div>

        <el-form-item label="采集间隔">
          <el-input-number
            v-model="form.interval_minutes"
            :min="5"
            :max="10080"
            :step="30"
            controls-position="right"
            placeholder="留空表示不定时"
            style="width: 100%"
          />
          <div class="form-hint">单位分钟，5 ~ 10080（7 天）。人工补录类源留空即可。</div>
        </el-form-item>

        <el-form-item label="备注">
          <el-input v-model="form.note" type="textarea" :rows="2" maxlength="255" show-word-limit />
        </el-form-item>
      </el-form>

      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submit">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { listMarketSources, updateMarketSource } from '../api/market'
import { dateTime } from '../utils/format'

const loading = ref(false)
const saving = ref(false)
const toggling = ref(0)
const rows = ref([])
const meta = reactive({ total: 0, enabled: 0, points_total: 0, figures_total: 0 })

const dialogVisible = ref(false)
const editing = ref(null)
const form = reactive(emptyForm())

const staleRows = computed(() => rows.value.filter((row) => row.stale))

const recentTotal = computed(() => rows.value.reduce((sum, row) => sum + Number(row.stats?.recent || 0), 0))

function emptyForm() {
  return { label: '', currency: 'CNY', interval_minutes: null, priority: 100, note: '' }
}

function channelType(channel) {
  if (channel === 'internal') return 'success'
  if (channel === 'manual') return 'info'

  return 'primary'
}

function intervalLabel(minutes) {
  if (!minutes) return '不定时'
  if (minutes % 1440 === 0) return `每 ${minutes / 1440} 天`

  return minutes % 60 === 0 ? `每 ${minutes / 60} 小时` : `每 ${minutes} 分钟`
}

function syncLabel(row) {
  if (row.stale) return '超期未同步'
  if (row.last_status === 'error') return '上次失败'
  if (row.last_status === 'ok') return '正常'

  return '未同步'
}

function syncType(row) {
  if (row.stale) return 'warning'
  if (row.last_status === 'error') return 'danger'
  if (row.last_status === 'ok') return 'success'

  return 'info'
}

async function load() {
  loading.value = true
  try {
    const payload = await listMarketSources()
    rows.value = payload?.data || []
    Object.assign(meta, payload?.meta || {})
  } catch (e) {
    ElMessage.error(e.message)
  } finally {
    loading.value = false
  }
}

async function toggle(row, value) {
  toggling.value = row.market_source_id
  try {
    const payload = await updateMarketSource(row.market_source_id, { enabled: value })
    const next = payload?.data
    if (next) Object.assign(row, next)
    meta.enabled = rows.value.filter((item) => item.enabled).length
    ElMessage.success(value ? `${row.label} 已启用` : `${row.label} 已停用`)
  } catch (e) {
    // 失败不改行：开关必须回弹到真实状态，不能让运营以为已经停用
    ElMessage.error(e.message)
  } finally {
    toggling.value = 0
  }
}

function openEdit(row) {
  editing.value = row
  Object.assign(form, {
    label: row.label,
    currency: row.currency,
    interval_minutes: row.interval_minutes,
    priority: row.priority,
    note: row.note || ''
  })
  dialogVisible.value = true
}

async function submit() {
  if (!editing.value) return

  if (!form.label.trim()) {
    ElMessage.warning('显示名称不能为空')
    return
  }

  saving.value = true
  try {
    const payload = await updateMarketSource(editing.value.market_source_id, {
      label: form.label.trim(),
      currency: form.currency.trim().toUpperCase() || 'CNY',
      interval_minutes: form.interval_minutes ?? null,
      priority: form.priority,
      note: form.note.trim() || null
    })
    if (payload?.data) Object.assign(editing.value, payload.data)
    dialogVisible.value = false
    ElMessage.success('已保存')
  } catch (e) {
    ElMessage.error(e.message)
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<style scoped lang="scss">
.alert {
  margin-bottom: 14px;
  border-radius: 10px;
}

.stat-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 14px;
}

.stat {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 16px 18px;
  background: #fff;
  border: 1px solid var(--goat-line);
  border-radius: 12px;
}

.stat-value {
  font-size: 24px;
  font-weight: 700;
  line-height: 1.1;
}

.stat-sub {
  font-size: 13px;
  font-weight: 500;
  color: #9a9a9f;
}

.stat-label {
  font-size: 12px;
  color: #6b6b70;
}

.table-card {
  padding: 4px 4px 8px;
  background: #fff;
  border: 1px solid var(--goat-line);
  border-radius: 12px;
}

.cell-main {
  display: flex;
  align-items: center;
  gap: 8px;
}

.cell-sub {
  margin-top: 2px;
  font-size: 12px;
  color: #8b8b90;
  line-height: 1.5;
  word-break: break-all;
}

.cell-error {
  max-width: 240px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: #c45656;
}

.mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}

.dim {
  color: #8b8b90;
}

.hint-card {
  margin-top: 14px;
  border-radius: 12px;
  border: 1px solid var(--goat-line);
}

.card-title {
  font-weight: 600;
}

.hint-list {
  margin: 0;
  padding-left: 18px;
  color: #55555a;
  font-size: 13px;
  line-height: 1.9;
}

.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}

.form-hint {
  margin-top: 4px;
  font-size: 12px;
  color: #8b8b90;
  line-height: 1.5;
}
</style>

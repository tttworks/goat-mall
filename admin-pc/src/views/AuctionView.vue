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
        <h2 class="page-title">竞价管理</h2>
        <p class="page-desc">
          进行中的场次排在前面。只有无人出价的场次可以撤销——一旦有人出价就挂着保证金与出价人预期，
          强制撤销要连带解冻与通知，请走线下处理。
        </p>
      </div>
    </div>

    <div class="toolbar">
      <el-input
        v-model="filters.keyword"
        placeholder="商品标题"
        clearable
        style="width: 220px"
        @keyup.enter="reload(1)"
        @clear="reload(1)"
      />
      <el-select v-model="filters.status" placeholder="全部状态" clearable style="width: 150px" @change="reload(1)">
        <el-option v-for="item in statusOptions" :key="item.value" :label="item.label" :value="item.value" />
      </el-select>
      <el-button @click="reload(1)">
        <el-icon><Search /></el-icon>
        查询
      </el-button>
    </div>

    <div class="table-card">
      <el-table v-loading="loading" :data="rows" row-key="auction_id" style="width: 100%">
        <el-table-column label="商品" min-width="280">
          <template #default="{ row }">
            <div class="item-cell">
              <el-image v-if="row.cover_url" :src="row.cover_url" fit="cover" class="thumb" />
              <div v-else class="thumb placeholder">无图</div>
              <div>
                <div class="cell-title">{{ row.title || '-' }}</div>
                <div class="cell-sub">{{ row.figure_name || `图鑑 #${row.figure_id || '-'}` }}</div>
              </div>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="起拍价" width="110" align="right">
          <template #default="{ row }">{{ money(row.start_price) }}</template>
        </el-table-column>

        <el-table-column label="当前价" width="110" align="right">
          <template #default="{ row }">
            <strong>{{ money(row.current_price) }}</strong>
          </template>
        </el-table-column>

        <el-table-column label="出价次数" width="100" align="right">
          <template #default="{ row }">{{ row.bid_count }}</template>
        </el-table-column>

        <el-table-column label="状态" width="110">
          <template #default="{ row }">
            <el-tag :type="statusType(row.status)" size="small" effect="light">{{ row.status_label }}</el-tag>
          </template>
        </el-table-column>

        <el-table-column label="结束时间" width="160">
          <template #default="{ row }">{{ dateTime(row.end_at) }}</template>
        </el-table-column>

        <el-table-column label="操作" width="170" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openBids(row)">出价明细</el-button>
            <el-button
              v-if="canCancel(row)"
              link
              type="danger"
              @click="cancel(row)"
            >
              撤销场次
            </el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="table-foot">
        <el-pagination
          layout="total, prev, pager, next, sizes"
          :total="pager.total"
          :current-page="pager.page"
          :page-size="pager.perPage"
          :page-sizes="[20, 50, 100]"
          @current-change="reload"
          @size-change="onSizeChange"
        />
      </div>
    </div>

    <el-drawer v-model="drawerVisible" title="出价明细" size="520px">
      <div v-if="detail">
        <el-descriptions :column="2" border>
          <el-descriptions-item label="场次 ID">{{ detail.auction_id }}</el-descriptions-item>
          <el-descriptions-item label="状态">{{ detail.status_label }}</el-descriptions-item>
          <el-descriptions-item label="起拍价">{{ money(detail.start_price) }}</el-descriptions-item>
          <el-descriptions-item label="最小加价">{{ money(detail.min_increment) }}</el-descriptions-item>
          <el-descriptions-item label="当前价">{{ money(detail.current_price) }}</el-descriptions-item>
          <el-descriptions-item label="出价次数">{{ detail.bid_count }}</el-descriptions-item>
          <el-descriptions-item label="开始">{{ dateTime(detail.start_at) }}</el-descriptions-item>
          <el-descriptions-item label="结束">{{ dateTime(detail.end_at) }}</el-descriptions-item>
        </el-descriptions>

        <h4 class="section-title">出价记录（最新在上）</h4>
        <el-table :data="detail.bids" size="small" empty-text="暂无出价">
          <el-table-column label="出价人" width="120" prop="bidder" />
          <el-table-column label="出价" width="120" align="right">
            <template #default="{ row }">{{ money(row.amount) }}</template>
          </el-table-column>
          <el-table-column label="时间" prop="created_at">
            <template #default="{ row }">{{ dateTime(row.created_at) }}</template>
          </el-table-column>
        </el-table>
      </div>
    </el-drawer>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { cancelAuction, getAuction, listAuctions } from '../api/auctions'
import { dateTime, money, pagerOf } from '../utils/format'

const loading = ref(false)
const rows = ref([])
const pager = reactive({ total: 0, page: 1, perPage: 20 })
const statusOptions = ref([])
const filters = reactive({ keyword: '', status: '' })

const drawerVisible = ref(false)
const detail = ref(null)

const STATUS_TYPES = {
  scheduled: 'info',
  active: 'success',
  ended: 'warning',
  settled: 'primary',
  cancelled: 'info',
  failed: 'danger'
}

function statusType(status) {
  return STATUS_TYPES[status] || 'info'
}

/** 只有在「未开始/进行中」且无人出价时才给按钮，避免运营点了才被拒 */
function canCancel(row) {
  return row.bid_count === 0 && (row.status === 'scheduled' || row.status === 'active')
}

async function reload(page = pager.page) {
  loading.value = true
  try {
    const payload = await listAuctions({
      keyword: filters.keyword || undefined,
      status: filters.status || undefined,
      page,
      per_page: pager.perPage
    })

    rows.value = payload?.data || []
    Object.assign(pager, pagerOf(payload?.meta, pager.perPage))
    if (page) pager.page = Number(page)
    if (payload?.meta?.statuses) statusOptions.value = payload.meta.statuses
  } catch (e) {
    ElMessage.error(e.message)
  } finally {
    loading.value = false
  }
}

function onSizeChange(size) {
  pager.perPage = size
  reload(1)
}

async function openBids(row) {
  drawerVisible.value = true
  detail.value = null
  try {
    const payload = await getAuction(row.auction_id)
    detail.value = payload?.data || null
  } catch (e) {
    ElMessage.error(e.message)
  }
}

async function cancel(row) {
  try {
    const result = await ElMessageBox.prompt(
      `撤销「${row.title || '该场次'}」后该场次不再接受出价，商品仍可一口价出售。请填写原因（写入审计日志）。`,
      '撤销场次',
      {
        confirmButtonText: '确认撤销',
        cancelButtonText: '取消',
        inputPlaceholder: '如：卖家申请取消',
        inputValidator: (value) => (String(value || '').trim().length >= 2 ? true : '请填写原因'),
        type: 'warning'
      }
    )

    await cancelAuction(row.auction_id, String(result.value).trim())
    ElMessage.success('已撤销')
    reload()
  } catch (e) {
    if (e?.message) ElMessage.error(e.message)
  }
}

onMounted(() => reload(1))
</script>

<style scoped lang="scss">
.item-cell {
  display: flex;
  align-items: center;
  gap: 10px;
}

.thumb {
  width: 40px;
  height: 40px;
  border-radius: 6px;
  flex: none;
}

.placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 10px;
  color: var(--goat-text-dim);
  background: #f2f2f3;
}

.cell-title {
  font-weight: 600;
  font-size: 13px;
}

.cell-sub {
  margin-top: 2px;
  font-size: 12px;
  color: var(--goat-text-dim);
}

.section-title {
  margin: 22px 0 10px;
  font-size: 13px;
  font-weight: 600;
}
</style>

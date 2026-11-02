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
        <h2 class="page-title">
          退款审核
          <el-badge v-if="pendingTotal" :value="pendingTotal" class="badge" />
        </h2>
        <p class="page-desc">
          钱在我方冻结，所以退款是审核制：同意才真正调渠道退回，驳回必须写理由（买家会看到这句话）。
          已确认收货的订单走售后协商，不在这里直接退。
        </p>
      </div>
    </div>

    <div class="toolbar">
      <el-radio-group v-model="filters.status" @change="reload(1)">
        <el-radio-button label="pending">待审核</el-radio-button>
        <el-radio-button label="all">全部</el-radio-button>
        <el-radio-button label="refunded">已退款</el-radio-button>
        <el-radio-button label="rejected">已驳回</el-radio-button>
      </el-radio-group>
      <el-input
        v-model="filters.keyword"
        placeholder="退款单号 / 订单 ID"
        clearable
        style="width: 200px"
        @keyup.enter="reload(1)"
        @clear="reload(1)"
      />
      <el-button @click="reload(1)">
        <el-icon><Search /></el-icon>
        查询
      </el-button>
    </div>

    <div class="table-card">
      <el-table v-loading="loading" :data="rows" row-key="refund_id" style="width: 100%">
        <el-table-column label="退款单 / 订单" min-width="240">
          <template #default="{ row }">
            <div class="cell-title">{{ row.refund_no }}</div>
            <div class="cell-sub">订单 {{ row.order?.order_no || row.order_id }}</div>
          </template>
        </el-table-column>

        <el-table-column label="买家" width="130">
          <template #default="{ row }">{{ row.buyer?.nickname || '-' }}</template>
        </el-table-column>

        <el-table-column label="退款金额" width="120" align="right">
          <template #default="{ row }">
            <strong>{{ money(row.amount) }}</strong>
          </template>
        </el-table-column>

        <el-table-column label="申请原因" min-width="200">
          <template #default="{ row }">{{ row.reason }}</template>
        </el-table-column>

        <el-table-column label="状态" width="110">
          <template #default="{ row }">
            <el-tag :type="statusType(row.status)" size="small" effect="light">{{ row.status_label }}</el-tag>
          </template>
        </el-table-column>

        <el-table-column label="审核意见" min-width="180">
          <template #default="{ row }">{{ row.remark || '-' }}</template>
        </el-table-column>

        <el-table-column label="申请时间" width="150">
          <template #default="{ row }">{{ dateTime(row.created_at) }}</template>
        </el-table-column>

        <el-table-column label="操作" width="150" fixed="right">
          <template #default="{ row }">
            <template v-if="row.status === 'pending' || row.status === 'failed'">
              <el-button link type="primary" @click="review(row, true)">同意退款</el-button>
              <el-button link type="danger" @click="review(row, false)">驳回</el-button>
            </template>
            <span v-else class="cell-sub">已处理</span>
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
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { listRefunds, reviewRefund } from '../api/orders'
import { dateTime, money, pagerOf } from '../utils/format'

const loading = ref(false)
const rows = ref([])
const pager = reactive({ total: 0, page: 1, perPage: 20 })
const pendingTotal = ref(0)
const filters = reactive({ status: 'pending', keyword: '' })

const STATUS_TYPES = { pending: 'warning', refunded: 'success', rejected: 'info', failed: 'danger' }

function statusType(status) {
  return STATUS_TYPES[status] || 'info'
}

async function reload(page = pager.page) {
  loading.value = true
  try {
    const payload = await listRefunds({
      status: filters.status || undefined,
      keyword: filters.keyword || undefined,
      page,
      per_page: pager.perPage
    })

    rows.value = payload?.data || []
    Object.assign(pager, pagerOf(payload?.meta, pager.perPage))
    if (page) pager.page = Number(page)
    pendingTotal.value = Number(payload?.meta?.pending_total ?? 0)
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

async function review(row, approve) {
  let remark = ''
  try {
    const result = await ElMessageBox.prompt(
      approve
        ? `确认将 ${money(row.amount)} 原路退回买家？此操作会立即调用支付渠道。`
        : '驳回原因（买家可见，建议写清后续处理方式）',
      approve ? '同意退款' : '驳回退款',
      {
        confirmButtonText: approve ? '确认退款' : '确认驳回',
        cancelButtonText: '取消',
        inputPlaceholder: approve ? '可填写处理说明（选填）' : '如：已与买家沟通，商品已发货',
        inputValidator: (value) => (approve || String(value || '').trim().length >= 2 ? true : '请填写驳回原因'),
        type: approve ? 'warning' : 'error'
      }
    )
    remark = String(result.value || '').trim()
  } catch (e) {
    return
  }

  try {
    const payload = await reviewRefund(row.refund_id, approve, remark || undefined)
    ElMessage.success(payload?.data?.status_label ? `已处理：${payload.data.status_label}` : '已处理')
    reload()
  } catch (e) {
    ElMessage.error(e.message)
  }
}

onMounted(() => reload(1))
</script>

<style scoped lang="scss">
.badge {
  margin-left: 6px;
  vertical-align: 2px;
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
</style>

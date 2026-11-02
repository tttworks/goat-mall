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
        <h2 class="page-title">订单管理</h2>
        <p class="page-desc">
          全平台订单与资金流水查看。金额来自下单快照与支付流水，后台不可改价、不可改状态——
          需要人工介入时只写运营备注，留痕比方便更重要。
        </p>
      </div>
    </div>

    <div class="toolbar">
      <el-input
        v-model="filters.keyword"
        placeholder="订单号"
        clearable
        style="width: 200px"
        @keyup.enter="reload(1)"
        @clear="reload(1)"
      />
      <el-select v-model="filters.status" placeholder="全部状态" clearable style="width: 150px" @change="reload(1)">
        <el-option v-for="item in statusOptions" :key="item.value" :label="item.label" :value="item.value" />
      </el-select>
      <el-date-picker
        v-model="filters.range"
        type="daterange"
        value-format="YYYY-MM-DD"
        start-placeholder="开始日期"
        end-placeholder="结束日期"
        style="width: 260px"
        @change="reload(1)"
      />
      <el-button @click="reload(1)">
        <el-icon><Search /></el-icon>
        查询
      </el-button>
      <el-button text @click="resetFilters">重置</el-button>
    </div>

    <div class="table-card">
      <el-table v-loading="loading" :data="rows" row-key="order_id" style="width: 100%">
        <el-table-column label="订单" min-width="220">
          <template #default="{ row }">
            <div class="cell-title">{{ row.order_no }}</div>
            <div class="cell-sub">{{ row.title || '-' }}{{ row.item_count > 1 ? ` 等 ${row.item_count} 件` : '' }}</div>
          </template>
        </el-table-column>

        <el-table-column label="买家" width="130">
          <template #default="{ row }">{{ row.buyer?.nickname || '-' }}</template>
        </el-table-column>

        <el-table-column label="卖家" width="130">
          <template #default="{ row }">{{ row.seller?.nickname || '-' }}</template>
        </el-table-column>

        <el-table-column label="应付" width="120" align="right">
          <template #default="{ row }">
            <strong>{{ money(row.payable_amount) }}</strong>
          </template>
        </el-table-column>

        <el-table-column label="手续费" width="110" align="right">
          <template #default="{ row }">{{ money(row.fee_amount) }}</template>
        </el-table-column>

        <el-table-column label="状态" width="120">
          <template #default="{ row }">
            <el-tag :type="statusType(row.status)" size="small" effect="light">{{ row.status_label }}</el-tag>
          </template>
        </el-table-column>

        <el-table-column label="支付时间" width="150">
          <template #default="{ row }">{{ dateTime(row.paid_at) }}</template>
        </el-table-column>

        <el-table-column label="创建时间" width="150">
          <template #default="{ row }">{{ dateTime(row.created_at) }}</template>
        </el-table-column>

        <el-table-column label="操作" width="100" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openDetail(row)">详情</el-button>
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

    <el-drawer v-model="drawerVisible" title="订单详情" size="640px">
      <div v-if="detail" class="detail">
        <el-descriptions :column="2" border>
          <el-descriptions-item label="订单号" :span="2">{{ detail.order_no }}</el-descriptions-item>
          <el-descriptions-item label="状态">
            <el-tag :type="statusType(detail.status)" size="small">{{ detail.status_label }}</el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="资金状态">{{ detail.escrow_status || '-' }}</el-descriptions-item>
          <el-descriptions-item label="买家">{{ detail.buyer?.nickname || '-' }}</el-descriptions-item>
          <el-descriptions-item label="卖家">{{ detail.seller?.nickname || '-' }}</el-descriptions-item>
          <el-descriptions-item label="商品金额">{{ money(detail.goods_amount) }}</el-descriptions-item>
          <el-descriptions-item label="手续费">{{ money(detail.fee_amount) }}</el-descriptions-item>
          <el-descriptions-item label="运费">{{ money(detail.shipping_amount) }}</el-descriptions-item>
          <el-descriptions-item label="应付">{{ money(detail.payable_amount) }}</el-descriptions-item>
          <el-descriptions-item label="下单">{{ dateTime(detail.created_at) }}</el-descriptions-item>
          <el-descriptions-item label="支付">{{ dateTime(detail.paid_at) }}</el-descriptions-item>
          <el-descriptions-item label="发货">{{ dateTime(detail.shipped_at) }}</el-descriptions-item>
          <el-descriptions-item label="确认收货">{{ dateTime(detail.confirmed_at) }}</el-descriptions-item>
        </el-descriptions>

        <h4 class="section-title">商品明细</h4>
        <el-table :data="detail.items" size="small">
          <el-table-column label="商品" min-width="220">
            <template #default="{ row }">
              <div class="item-cell">
                <el-image v-if="row.cover_url" :src="row.cover_url" fit="cover" class="thumb" />
                <div v-else class="thumb placeholder">无图</div>
                <span>{{ row.title }}</span>
              </div>
            </template>
          </el-table-column>
          <el-table-column label="单价" width="110" align="right">
            <template #default="{ row }">{{ money(row.unit_price) }}</template>
          </el-table-column>
          <el-table-column label="数量" width="70" align="right" prop="quantity" />
          <el-table-column label="小计" width="110" align="right">
            <template #default="{ row }">{{ money(row.subtotal) }}</template>
          </el-table-column>
        </el-table>

        <h4 class="section-title">支付流水</h4>
        <el-table :data="detail.payments" size="small" empty-text="暂无支付记录">
          <el-table-column label="支付单号" min-width="200" prop="payment_no" />
          <el-table-column label="渠道" width="100" prop="channel" />
          <el-table-column label="金额" width="110" align="right">
            <template #default="{ row }">{{ money(row.amount) }}</template>
          </el-table-column>
          <el-table-column label="状态" width="100">
            <template #default="{ row }">
              <el-tag size="small" effect="plain">{{ row.status_label }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="支付时间" width="150">
            <template #default="{ row }">{{ dateTime(row.paid_at) }}</template>
          </el-table-column>
        </el-table>

        <h4 class="section-title">退款记录</h4>
        <el-table :data="detail.refunds" size="small" empty-text="无退款">
          <el-table-column label="退款单号" min-width="200" prop="refund_no" />
          <el-table-column label="原因" min-width="160" prop="reason" />
          <el-table-column label="金额" width="110" align="right">
            <template #default="{ row }">{{ money(row.amount) }}</template>
          </el-table-column>
          <el-table-column label="状态" width="100">
            <template #default="{ row }">
              <el-tag size="small" effect="plain">{{ row.status_label }}</el-tag>
            </template>
          </el-table-column>
        </el-table>

        <h4 class="section-title">费率快照</h4>
        <pre class="snapshot">{{ JSON.stringify(detail.fee_snapshot, null, 2) }}</pre>

        <h4 class="section-title">运营备注</h4>
        <el-input v-model="remarkText" type="textarea" :rows="3" placeholder="记录人工介入的原因与结论" />
        <div class="remark-actions">
          <el-button type="primary" :loading="savingRemark" @click="saveRemark">保存备注</el-button>
        </div>
      </div>
    </el-drawer>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getOrder, listOrders, remarkOrder } from '../api/orders'
import { dateTime, money, pagerOf } from '../utils/format'

const loading = ref(false)
const rows = ref([])
const pager = reactive({ total: 0, page: 1, perPage: 20 })
const statusOptions = ref([])

const filters = reactive({ keyword: '', status: '', range: [] })

const drawerVisible = ref(false)
const detail = ref(null)
const remarkText = ref('')
const savingRemark = ref(false)

const STATUS_TYPES = {
  pending_payment: 'warning',
  paid: 'success',
  seller_shipped: 'primary',
  buyer_confirmed: 'success',
  completed: 'success',
  cancelled: 'info',
  closed: 'info',
  refunding: 'warning',
  refunded: 'danger'
}

function statusType(status) {
  return STATUS_TYPES[status] || 'info'
}

async function reload(page = pager.page) {
  loading.value = true
  try {
    const payload = await listOrders({
      keyword: filters.keyword || undefined,
      status: filters.status || undefined,
      date_from: filters.range?.[0] || undefined,
      date_to: filters.range?.[1] || undefined,
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

function resetFilters() {
  filters.keyword = ''
  filters.status = ''
  filters.range = []
  reload(1)
}

async function openDetail(row) {
  drawerVisible.value = true
  detail.value = null
  try {
    const payload = await getOrder(row.order_id)
    detail.value = payload?.data || null
    remarkText.value = detail.value?.remark || ''
  } catch (e) {
    ElMessage.error(e.message)
  }
}

async function saveRemark() {
  if (!detail.value) return
  savingRemark.value = true
  try {
    await remarkOrder(detail.value.order_id, remarkText.value.trim())
    ElMessage.success('备注已保存')
  } catch (e) {
    ElMessage.error(e.message)
  } finally {
    savingRemark.value = false
  }
}

onMounted(() => reload(1))
</script>

<style scoped lang="scss">
.cell-title {
  font-weight: 600;
  font-size: 13px;
}

.cell-sub {
  margin-top: 2px;
  font-size: 12px;
  color: var(--goat-text-dim);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.detail {
  padding-bottom: 24px;
}

.section-title {
  margin: 22px 0 10px;
  font-size: 13px;
  font-weight: 600;
}

.item-cell {
  display: flex;
  align-items: center;
  gap: 10px;
}

.thumb {
  width: 36px;
  height: 36px;
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

.snapshot {
  margin: 0;
  padding: 12px;
  font-size: 12px;
  line-height: 1.6;
  background: #f7f7f8;
  border-radius: 8px;
  overflow-x: auto;
}

.remark-actions {
  margin-top: 10px;
  text-align: right;
}
</style>

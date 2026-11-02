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
        <h2 class="page-title">卖家审核</h2>
        <p class="page-desc">
          默认只列待审核店铺：运营打开即干活。驳回必须写原因，原因会原样回传给申请人。
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
        <el-radio-button value="active">正常营业</el-radio-button>
        <el-radio-button value="suspended">已封禁</el-radio-button>
      </el-radio-group>
      <span class="cell-sub">共 {{ pager.total }} 家</span>
    </div>

    <div class="table-card">
      <el-table v-loading="loading" :data="rows" row-key="seller_id" style="width: 100%">
        <el-table-column label="店铺" min-width="220">
          <template #default="{ row }">
            <div class="cell-title">{{ row.shop_name }}</div>
            <div class="cell-sub">{{ row.shop_intro || '未填写简介' }}</div>
          </template>
        </el-table-column>

        <el-table-column label="店主" width="160">
          <template #default="{ row }">
            <div>{{ row.owner?.nickname || '-' }}</div>
            <div class="cell-sub mono">UID {{ row.owner?.user_id }}</div>
          </template>
        </el-table-column>

        <el-table-column label="状态" width="110">
          <template #default="{ row }">
            <el-tag :type="statusOf(SELLER_STATUS, row.status).type" size="small">
              {{ row.status_label || statusOf(SELLER_STATUS, row.status).label }}
            </el-tag>
          </template>
        </el-table-column>

        <el-table-column label="实名" width="110">
          <template #default="{ row }">
            <span class="mono">{{ row.identity_status }}</span>
          </template>
        </el-table-column>

        <el-table-column label="成交" width="80" align="right">
          <template #default="{ row }">{{ row.sold_count }}</template>
        </el-table-column>

        <el-table-column label="评分" width="90" align="right">
          <template #default="{ row }">{{ (row.rating_bp / 100).toFixed(1) }}</template>
        </el-table-column>

        <el-table-column label="申请时间" width="150">
          <template #default="{ row }">{{ dateTime(row.applied_at) }}</template>
        </el-table-column>

        <el-table-column label="驳回原因" min-width="160">
          <template #default="{ row }">
            <span class="cell-sub">{{ row.reject_reason || '-' }}</span>
          </template>
        </el-table-column>

        <el-table-column label="操作" width="150" fixed="right">
          <template #default="{ row }">
            <template v-if="row.status === 'pending'">
              <el-button link type="primary" @click="review(row, 'approve')">通过</el-button>
              <el-button link type="danger" @click="review(row, 'reject')">驳回</el-button>
            </template>
            <el-button v-else-if="row.status === 'active'" link type="danger" @click="review(row, 'suspend')">
              封禁
            </el-button>
            <span v-else class="cell-sub">已封禁</span>
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
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { listSellers, reviewSeller } from '../api/sellers'
import { SELLER_STATUS, dateTime, pagerOf, statusOf } from '../utils/format'

const loading = ref(false)
const rows = ref([])
const status = ref('pending')
const pager = reactive({ total: 0, page: 1, perPage: 20 })

async function reload(page = pager.page) {
  loading.value = true
  try {
    const payload = await listSellers({ status: status.value, page })
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

async function review(row, decision) {
  const titles = { approve: '通过开店申请', reject: '驳回开店申请', suspend: '封禁店铺' }
  let reason = ''

  if (decision === 'approve') {
    try {
      await ElMessageBox.confirm(`通过后「${row.shop_name}」即可上架商品，确认通过？`, titles[decision], {
        confirmButtonText: '通过',
        cancelButtonText: '取消'
      })
    } catch (e) {
      return
    }
  } else {
    try {
      const result = await ElMessageBox.prompt(
        decision === 'reject' ? '驳回原因（必填，会回传给申请人）' : '封禁原因（必填，会通知店主）',
        titles[decision],
        {
          confirmButtonText: '确认',
          cancelButtonText: '取消',
          inputValidator: (value) => (value && value.trim().length >= 2 ? true : '请填写至少 2 个字的原因'),
          inputType: 'textarea'
        }
      )
      reason = result.value.trim()
    } catch (e) {
      return
    }
  }

  try {
    await reviewSeller(row.seller_id, decision, reason || undefined)
    ElMessage.success('已处理')
    reload()
  } catch (e) {
    ElMessage.error(e.message)
  }
}

onMounted(() => reload(1))
</script>

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
        <h2 class="page-title">商品审核</h2>
        <p class="page-desc">
          卖家提交后进入待审核队列；驳回原因写进商品 meta，卖家在「我的商品」里能看到，不用客服转述。
        </p>
      </div>
      <el-button @click="reload(1)">
        <el-icon><Refresh /></el-icon>
        刷新
      </el-button>
    </div>

    <div class="toolbar">
      <el-radio-group v-model="status" @change="reload(1)">
        <el-radio-button value="pending_review">待审核</el-radio-button>
        <el-radio-button value="active">在售</el-radio-button>
        <el-radio-button value="rejected">已驳回</el-radio-button>
        <el-radio-button value="sold">已售出</el-radio-button>
      </el-radio-group>
      <span class="cell-sub">共 {{ pager.total }} 件</span>
    </div>

    <div class="table-card">
      <el-table v-loading="loading" :data="rows" row-key="listing_id" style="width: 100%">
        <el-table-column label="商品" min-width="280">
          <template #default="{ row }">
            <div class="listing-cell">
              <el-image v-if="row.cover_url" :src="row.cover_url" fit="cover" class="cell-thumb" />
              <div v-else class="cell-thumb placeholder">无图</div>
              <div>
                <div class="cell-title">{{ row.title }}</div>
                <div class="cell-sub mono">#{{ row.listing_id }} · 图鑑 {{ row.figure_id ?? '-' }}</div>
              </div>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="价格" width="120" align="right">
          <template #default="{ row }">{{ money(row.price) }}</template>
        </el-table-column>

        <el-table-column label="成色" width="110">
          <template #default="{ row }">{{ CONDITION_LABELS[row.condition] || row.condition }}</template>
        </el-table-column>

        <el-table-column label="卖家" width="100" align="center">
          <template #default="{ row }">
            <span class="mono">S{{ row.seller_id }}</span>
          </template>
        </el-table-column>

        <el-table-column label="状态" width="110">
          <template #default="{ row }">
            <el-tag :type="statusOf(LISTING_STATUS, row.status).type" size="small">
              {{ row.status_label || statusOf(LISTING_STATUS, row.status).label }}
            </el-tag>
          </template>
        </el-table-column>

        <el-table-column label="提交时间" width="150">
          <template #default="{ row }">{{ dateTime(row.published_at) }}</template>
        </el-table-column>

        <el-table-column label="操作" width="150" fixed="right">
          <template #default="{ row }">
            <template v-if="row.status === 'pending_review'">
              <el-button link type="primary" @click="review(row, 'approve')">通过</el-button>
              <el-button link type="danger" @click="review(row, 'reject')">驳回</el-button>
            </template>
            <span v-else class="cell-sub">已处理</span>
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
import { listListings, reviewListing } from '../api/listings'
import { CONDITION_LABELS, LISTING_STATUS, dateTime, money, pagerOf, statusOf } from '../utils/format'

const loading = ref(false)
const rows = ref([])
const status = ref('pending_review')
const pager = reactive({ total: 0, page: 1, perPage: 20 })

async function reload(page = pager.page) {
  loading.value = true
  try {
    const payload = await listListings({ status: status.value, page })
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
      await ElMessageBox.confirm(`通过后「${row.title}」立即在 C 端上架，确认？`, '通过审核', {
        confirmButtonText: '通过',
        cancelButtonText: '取消'
      })
    } catch (e) {
      return
    }
  } else {
    try {
      const result = await ElMessageBox.prompt('驳回原因（必填，卖家可见）', '驳回商品', {
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
    await reviewListing(row.listing_id, action, reason || undefined)
    ElMessage.success('已处理')
    reload()
  } catch (e) {
    ElMessage.error(e.message)
  }
}

onMounted(() => reload(1))
</script>

<style scoped lang="scss">
.listing-cell {
  display: flex;
  align-items: center;
  gap: 12px;
}

.placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  color: var(--goat-text-dim);
}
</style>

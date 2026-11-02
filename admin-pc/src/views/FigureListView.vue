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
        <h2 class="page-title">图鑑管理</h2>
        <p class="page-desc">
          草稿只在后台可见；发布后进入 C 端图鑑与检索索引，归档后不再对外展示但历史行情保留。
        </p>
      </div>
      <el-button type="primary" @click="openCreate">
        <el-icon><Plus /></el-icon>
        新建图鑑
      </el-button>
    </div>

    <div class="toolbar">
      <el-input
        v-model="filters.keyword"
        placeholder="名称 / 别名 / 厂牌"
        clearable
        style="width: 220px"
        @keyup.enter="reload(1)"
        @clear="reload(1)"
      />
      <el-select v-model="filters.status" placeholder="全部状态" clearable style="width: 140px" @change="reload(1)">
        <el-option v-for="item in statusOptions" :key="item.value" :label="item.label" :value="item.value" />
      </el-select>
      <el-select v-model="filters.category" placeholder="全部品类" clearable style="width: 150px" @change="reload(1)">
        <el-option v-for="item in categoryOptions" :key="item.value" :label="item.label" :value="item.value" />
      </el-select>
      <el-button @click="reload(1)">
        <el-icon><Search /></el-icon>
        查询
      </el-button>
      <el-button text @click="resetFilters">重置</el-button>
    </div>

    <div class="table-card">
      <el-table v-loading="loading" :data="rows" row-key="figure_id" style="width: 100%">
        <el-table-column label="图鑑" min-width="260">
          <template #default="{ row }">
            <div class="figure-cell">
              <el-image v-if="row.cover_url" :src="row.cover_url" fit="cover" class="cell-thumb" />
              <div v-else class="cell-thumb placeholder">无图</div>
              <div>
                <div class="cell-title">{{ row.name }}</div>
                <div class="cell-sub">{{ row.name_ja || row.name_en || row.slug }}</div>
              </div>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="厂牌 / 系列" min-width="160">
          <template #default="{ row }">
            <div>{{ row.maker || '-' }}</div>
            <div class="cell-sub">{{ row.series || '-' }}</div>
          </template>
        </el-table-column>

        <el-table-column label="品类" width="120">
          <template #default="{ row }">{{ row.category_label }}</template>
        </el-table-column>

        <el-table-column label="比例" width="90">
          <template #default="{ row }">{{ row.scale || '-' }}</template>
        </el-table-column>

        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <el-tag :type="statusOf(FIGURE_STATUS, row.status).type" effect="light" size="small">
              {{ statusOf(FIGURE_STATUS, row.status).label }}
            </el-tag>
          </template>
        </el-table-column>

        <el-table-column label="在售" width="80" align="right">
          <template #default="{ row }">{{ row.listings_count }}</template>
        </el-table-column>

        <el-table-column label="浏览" width="80" align="right">
          <template #default="{ row }">{{ row.views }}</template>
        </el-table-column>

        <el-table-column label="发布时间" width="150">
          <template #default="{ row }">{{ dateTime(row.published_at) }}</template>
        </el-table-column>

        <el-table-column label="操作" width="230" fixed="right">
          <template #default="{ row }">
            <el-button
              v-if="row.status !== 'published'"
              link
              type="primary"
              @click="changeStatus(row, 'published')"
            >
              发布
            </el-button>
            <el-button
              v-else
              link
              type="warning"
              @click="changeStatus(row, 'archived')"
            >
              归档
            </el-button>
            <el-button link @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" @click="remove(row)">删除</el-button>
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

    <el-dialog v-model="dialogVisible" :title="editingId ? '编辑图鑑' : '新建图鑑'" width="640px" top="6vh">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="92px">
        <el-form-item label="名称" prop="name">
          <el-input v-model="form.name" placeholder="如：初音未来 交响乐 1/7" />
        </el-form-item>
        <el-form-item label="日文名">
          <el-input v-model="form.name_ja" placeholder="任意门" />
        </el-form-item>
        <el-form-item label="英文名">
          <el-input v-model="form.name_en" />
        </el-form-item>

        <div class="form-grid">
          <el-form-item label="厂牌">
            <el-input v-model="form.maker" placeholder="Good Smile Company" />
          </el-form-item>
          <el-form-item label="系列">
            <el-input v-model="form.series" />
          </el-form-item>
          <el-form-item label="比例">
            <el-input v-model="form.scale" placeholder="1/7" />
          </el-form-item>
          <el-form-item label="品类">
            <el-select v-model="form.category" placeholder="请选择" style="width: 100%">
              <el-option v-for="item in categoryOptions" :key="item.value" :label="item.label" :value="item.value" />
            </el-select>
          </el-form-item>
          <el-form-item label="发售日期">
            <el-date-picker
              v-model="form.release_date"
              type="date"
              value-format="YYYY-MM-DD"
              placeholder="2026-01-01"
              style="width: 100%"
            />
          </el-form-item>
          <el-form-item label="参考低价">
            <el-input v-model="form.price_low" placeholder="899.00" />
          </el-form-item>
        </div>

        <el-form-item label="封面 URL">
          <el-input v-model="form.cover_url" placeholder="https://media.example.com/figures/xxx.jpg 或对象 key" />
        </el-form-item>

        <el-form-item label="画廊">
          <el-input
            v-model="form.gallery_text"
            type="textarea"
            :rows="3"
            placeholder="每行一个 URL"
          />
        </el-form-item>

        <el-form-item label="别名">
          <el-input v-model="form.aliases_text" placeholder="多个用英文逗号分隔" />
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
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  changeFigureStatus,
  createFigure,
  deleteFigure,
  getFigure,
  listFigures,
  updateFigure
} from '../api/figures'
import { FIGURE_STATUS, dateTime, pagerOf, statusOf } from '../utils/format'

const loading = ref(false)
const saving = ref(false)
const rows = ref([])
const pager = reactive({ total: 0, page: 1, perPage: 20 })
const categoryOptions = ref([])
const statusOptions = ref([])

const filters = reactive({ keyword: '', status: '', category: '' })

const dialogVisible = ref(false)
const editingId = ref(0)
const formRef = ref(null)
const form = reactive(emptyForm())

const rules = {
  name: [{ required: true, message: '请填写图鑑名称', trigger: 'blur' }]
}

function emptyForm() {
  return {
    name: '',
    name_ja: '',
    name_en: '',
    maker: '',
    series: '',
    scale: '',
    category: '',
    release_date: '',
    price_low: '',
    cover_url: '',
    gallery_text: '',
    aliases_text: ''
  }
}

function resetForm() {
  Object.assign(form, emptyForm())
  formRef.value?.clearValidate()
}

async function reload(page = pager.page) {
  loading.value = true
  try {
    const payload = await listFigures({
      keyword: filters.keyword || undefined,
      status: filters.status || undefined,
      category: filters.category || undefined,
      page,
      per_page: pager.perPage
    })

    rows.value = payload?.data || []
    const meta = pagerOf(payload?.meta, pager.perPage)
    Object.assign(pager, meta)
    if (page) pager.page = Number(page)

    // 品类/状态选项由后端下发：前端不维护中文枚举副本
    if (payload?.meta?.categories) categoryOptions.value = payload.meta.categories
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
  filters.category = ''
  reload(1)
}

function openCreate() {
  editingId.value = 0
  resetForm()
  dialogVisible.value = true
}

async function openEdit(row) {
  editingId.value = row.figure_id
  resetForm()
  dialogVisible.value = true

  try {
    // 列表不带画廊/别名细节，编辑必须回查详情，否则保存会把这些字段清空
    const payload = await getFigure(row.figure_id)
    const detail = payload?.data || payload || {}
    Object.assign(form, {
      name: detail.name || '',
      name_ja: detail.name_ja || '',
      name_en: detail.name_en || '',
      maker: detail.maker || '',
      series: detail.series || '',
      scale: detail.scale || '',
      category: detail.category || '',
      release_date: detail.release_date || '',
      price_low: detail.price_low ?? '',
      cover_url: detail.cover_url || '',
      gallery_text: (detail.gallery_urls || []).join('\n'),
      aliases_text: (detail.aliases || []).join(',')
    })
  } catch (e) {
    ElMessage.error(e.message)
  }
}

function payloadOf() {
  const gallery = form.gallery_text
    .split('\n')
    .map((item) => item.trim())
    .filter(Boolean)

  return {
    name: form.name.trim(),
    name_ja: form.name_ja.trim() || null,
    name_en: form.name_en.trim() || null,
    maker: form.maker.trim() || null,
    series: form.series.trim() || null,
    scale: form.scale.trim() || null,
    category: form.category || null,
    release_date: form.release_date || null,
    price_low: form.price_low === '' ? null : form.price_low,
    cover_url: form.cover_url.trim() || null,
    gallery_urls: gallery.length ? gallery : null,
    aliases: form.aliases_text
      .split(',')
      .map((item) => item.trim())
      .filter(Boolean)
  }
}

async function submit() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) return

  saving.value = true
  try {
    const data = payloadOf()
    if (editingId.value) {
      await updateFigure(editingId.value, data)
      ElMessage.success('已保存')
    } else {
      await createFigure(data)
      ElMessage.success('已创建草稿')
    }
    dialogVisible.value = false
    reload()
  } catch (e) {
    ElMessage.error(e.message)
  } finally {
    saving.value = false
  }
}

async function changeStatus(row, status) {
  const text = status === 'published' ? '发布后 C 端立即可见，确认发布？' : '归档后 C 端不再展示，确认归档？'
  try {
    await ElMessageBox.confirm(text, '状态变更', { confirmButtonText: '确认', cancelButtonText: '取消' })
  } catch (e) {
    return
  }

  try {
    await changeFigureStatus(row.figure_id, status)
    ElMessage.success('已更新')
    reload()
  } catch (e) {
    ElMessage.error(e.message)
  }
}

async function remove(row) {
  try {
    await ElMessageBox.confirm(`删除「${row.name}」后不可恢复，确认删除？`, '删除图鑑', {
      confirmButtonText: '删除',
      cancelButtonText: '取消',
      type: 'warning'
    })
  } catch (e) {
    return
  }

  try {
    await deleteFigure(row.figure_id)
    ElMessage.success('已删除')
    reload()
  } catch (e) {
    ElMessage.error(e.message)
  }
}

onMounted(() => reload(1))
</script>

<style scoped lang="scss">
.figure-cell {
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

.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0 18px;
}
</style>

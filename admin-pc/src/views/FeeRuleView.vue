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
        <h2 class="page-title">费率配置</h2>
        <p class="page-desc">
          优先级：卖家级 &gt; 类目级 &gt; 全局。改动只影响之后的新订单——下单时费率会快照进订单，
          历史单永远按下单时的口径结算，所以这里可以放心调。
        </p>
      </div>
      <el-button type="primary" @click="openCreate">
        <el-icon><Plus /></el-icon>
        新建规则
      </el-button>
    </div>

    <div class="toolbar">
      <el-select v-model="filters.scope" placeholder="全部层级" clearable style="width: 140px" @change="reload(1)">
        <el-option label="全局" value="global" />
        <el-option label="类目" value="category" />
        <el-option label="卖家" value="seller" />
      </el-select>
      <el-select v-model="filters.active" placeholder="全部状态" clearable style="width: 130px" @change="reload(1)">
        <el-option label="启用中" value="1" />
        <el-option label="已停用" value="0" />
      </el-select>
      <el-input
        v-model="filters.keyword"
        placeholder="类目 / 卖家 ID"
        clearable
        style="width: 180px"
        @keyup.enter="reload(1)"
        @clear="reload(1)"
      />
      <el-button @click="reload(1)">
        <el-icon><Search /></el-icon>
        查询
      </el-button>
    </div>

    <div class="table-card">
      <el-table v-loading="loading" :data="rows" row-key="fee_rule_id" style="width: 100%">
        <el-table-column label="层级" width="110">
          <template #default="{ row }">
            <el-tag size="small" effect="plain">{{ scopeLabel(row.scope) }}</el-tag>
          </template>
        </el-table-column>

        <el-table-column label="作用范围" min-width="150">
          <template #default="{ row }">
            <span v-if="row.scope === 'global'" class="dim">全平台</span>
            <span v-else>{{ row.scope === 'seller' ? `卖家 #${row.scope_value}` : row.scope_value }}</span>
          </template>
        </el-table-column>

        <el-table-column label="费率" width="110" align="right">
          <template #default="{ row }">
            <strong>{{ row.percent_label }}</strong>
          </template>
        </el-table-column>

        <el-table-column label="封顶" width="110" align="right">
          <template #default="{ row }">{{ row.cap_amount ? money(row.cap_amount) : '不封顶' }}</template>
        </el-table-column>

        <el-table-column label="承担方" width="100">
          <template #default="{ row }">{{ row.bearer_label }}</template>
        </el-table-column>

        <el-table-column label="优先级" width="90" align="right">
          <template #default="{ row }">{{ row.priority }}</template>
        </el-table-column>

        <el-table-column label="生效区间" width="200">
          <template #default="{ row }">
            <span class="dim">
              {{ row.effective_from ? dateTime(row.effective_from) : '立即' }}
              ~
              {{ row.effective_to ? dateTime(row.effective_to) : '长期' }}
            </span>
          </template>
        </el-table-column>

        <el-table-column label="备注" min-width="150">
          <template #default="{ row }">{{ row.remark || '-' }}</template>
        </el-table-column>

        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <el-switch
              :model-value="row.is_active"
              @change="(value) => toggleActive(row, value)"
            />
          </template>
        </el-table-column>

        <el-table-column label="操作" width="130" fixed="right">
          <template #default="{ row }">
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
          :page-sizes="[30, 50, 100]"
          @current-change="reload"
          @size-change="onSizeChange"
        />
      </div>
    </div>

    <div class="calc-card">
      <div class="calc-head">
        <h3>规则试算</h3>
        <span class="dim">改完不必真下单：按当前生效规则算一遍，确认无误再发布上线</span>
      </div>

      <div class="calc-form">
        <el-input v-model="calc.goods_amount" placeholder="商品金额，如 10000" style="width: 180px">
          <template #prepend>¥</template>
        </el-input>
        <el-input v-model="calc.category" placeholder="类目（可空）" style="width: 160px" />
        <el-input v-model="calc.seller_id" placeholder="卖家 ID（可空）" style="width: 150px" />
        <el-button type="primary" :loading="calculating" @click="runPreview">试算</el-button>
      </div>

      <el-descriptions v-if="quote" :column="4" border class="calc-result">
        <el-descriptions-item label="商品金额">{{ money(quote.goods_amount) }}</el-descriptions-item>
        <el-descriptions-item label="手续费">
          {{ money(quote.fee_amount) }}
          <span class="dim">（{{ quote.percent_bip / 100 }}%{{ quote.cap_amount ? ` 封顶 ${money(quote.cap_amount)}` : '' }}）</span>
        </el-descriptions-item>
        <el-descriptions-item label="买家实付">{{ money(quote.payable_amount) }}</el-descriptions-item>
        <el-descriptions-item label="卖家到手">{{ money(quote.seller_income) }}</el-descriptions-item>
        <el-descriptions-item label="命中规则" :span="4">
          <template v-if="quote.rule">
            {{ scopeLabel(quote.rule.scope) }} / {{ quote.rule.scope_value || '全平台' }} · {{ quote.rule.percent_label }} ·
            {{ quote.rule.bearer_label }}承担
          </template>
          <template v-else>
            <el-tag type="info" size="small">未命中任何规则，费率按 0 处理</el-tag>
          </template>
        </el-descriptions-item>
      </el-descriptions>
    </div>

    <el-dialog v-model="dialogVisible" :title="editingId ? '编辑费率规则' : '新建费率规则'" width="620px" top="6vh">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="100px">
        <el-form-item label="层级" prop="scope">
          <el-radio-group v-model="form.scope">
            <el-radio-button label="global">全局</el-radio-button>
            <el-radio-button label="category">类目</el-radio-button>
            <el-radio-button label="seller">卖家</el-radio-button>
          </el-radio-group>
        </el-form-item>

        <el-form-item v-if="form.scope !== 'global'" label="作用对象" prop="scope_value">
          <el-input
            v-model="form.scope_value"
            :placeholder="form.scope === 'seller' ? '卖家 user_id，如 42' : '类目 key，如 scale_figure'"
          />
        </el-form-item>

        <el-form-item label="费率(%)" prop="percent">
          <el-input v-model="form.percent" placeholder="0 表示免费期">
            <template #append>%</template>
          </el-input>
        </el-form-item>

        <div class="form-grid">
          <el-form-item label="封顶金额">
            <el-input v-model="form.cap_amount" placeholder="留空不封顶" />
          </el-form-item>
          <el-form-item label="承担方" prop="bearer">
            <el-select v-model="form.bearer" style="width: 100%">
              <el-option v-for="item in bearerOptions" :key="item.value" :label="item.label" :value="item.value" />
            </el-select>
          </el-form-item>
          <el-form-item label="优先级">
            <el-input-number v-model="form.priority" :min="0" :max="255" controls-position="right" style="width: 100%" />
          </el-form-item>
          <el-form-item label="启用">
            <el-switch v-model="form.is_active" />
          </el-form-item>
        </div>

        <el-form-item label="生效区间">
          <el-date-picker
            v-model="form.range"
            type="datetimerange"
            value-format="YYYY-MM-DD HH:mm:ss"
            start-placeholder="立即生效"
            end-placeholder="长期有效"
            style="width: 100%"
          />
        </el-form-item>

        <el-form-item label="备注">
          <el-input v-model="form.remark" placeholder="如：推广期 0.8% 封顶 50" />
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
  createFeeRule,
  deleteFeeRule,
  listFeeRules,
  previewFee,
  updateFeeRule
} from '../api/fees'
import { dateTime, money, pagerOf } from '../utils/format'

const loading = ref(false)
const saving = ref(false)
const calculating = ref(false)
const rows = ref([])
const pager = reactive({ total: 0, page: 1, perPage: 30 })
const bearerOptions = ref([])

const filters = reactive({ scope: '', active: '', keyword: '' })

const dialogVisible = ref(false)
const editingId = ref(0)
const formRef = ref(null)
const form = reactive(emptyForm())
const rules = {
  percent: [{ required: true, message: '请填写费率（0 表示免费）', trigger: 'blur' }]
}

const calc = reactive({ goods_amount: '10000', category: '', seller_id: '' })
const quote = ref(null)

const SCOPE_LABELS = { global: '全局', category: '类目', seller: '卖家' }

function scopeLabel(scope) {
  return SCOPE_LABELS[scope] || scope
}

function emptyForm() {
  return {
    scope: 'global',
    scope_value: '',
    percent: '0',
    cap_amount: '',
    bearer: 'seller',
    priority: 0,
    is_active: true,
    range: [],
    remark: ''
  }
}

async function reload(page = pager.page) {
  loading.value = true
  try {
    const payload = await listFeeRules({
      scope: filters.scope || undefined,
      active: filters.active || undefined,
      keyword: filters.keyword || undefined,
      page,
      per_page: pager.perPage
    })

    rows.value = payload?.data || []
    Object.assign(pager, pagerOf(payload?.meta, pager.perPage))
    if (page) pager.page = Number(page)

    if (payload?.meta?.bearers) {
      bearerOptions.value = payload.meta.bearers
      // 承担方的默认值也由后端下发，避免前后端各写一份枚举
      if (!editingId.value) form.bearer = bearerOptions.value[0]?.value || 'seller'
    }
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

function openCreate() {
  editingId.value = 0
  Object.assign(form, emptyForm())
  formRef.value?.clearValidate()
  dialogVisible.value = true
}

function openEdit(row) {
  editingId.value = row.fee_rule_id
  Object.assign(form, {
    scope: row.scope,
    scope_value: row.scope_value || '',
    // bip → 百分数只在展示层换算；提交时再还原成整数，避免 0.8% 变成 0.7999%
    percent: String(row.percent_bip / 100),
    cap_amount: row.cap_amount ?? '',
    bearer: row.bearer,
    priority: row.priority,
    is_active: row.is_active,
    range: row.effective_from
      ? [row.effective_from.slice(0, 19).replace('T', ' '), row.effective_to ? row.effective_to.slice(0, 19).replace('T', ' ') : null]
      : [],
    remark: row.remark || ''
  })
  formRef.value?.clearValidate()
  dialogVisible.value = true
}

function payloadOf() {
  return {
    scope: form.scope,
    scope_value: form.scope === 'global' ? null : String(form.scope_value || '').trim() || null,
    percent_bip: Math.round(Number(form.percent || 0) * 100),
    cap_amount: form.cap_amount === '' ? null : String(form.cap_amount).trim(),
    bearer: form.bearer,
    priority: Number(form.priority || 0),
    is_active: form.is_active,
    effective_from: form.range?.[0] || null,
    effective_to: form.range?.[1] || null,
    remark: form.remark?.trim() || null
  }
}

async function submit() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) return

  if (form.scope !== 'global' && !String(form.scope_value).trim()) {
    ElMessage.error('请填写具体的类目或卖家 ID')
    return
  }

  saving.value = true
  try {
    const data = payloadOf()
    if (editingId.value) {
      await updateFeeRule(editingId.value, data)
      ElMessage.success('已保存')
    } else {
      await createFeeRule(data)
      ElMessage.success('已创建')
    }
    dialogVisible.value = false
    reload(1)
  } catch (e) {
    ElMessage.error(e.message)
  } finally {
    saving.value = false
  }
}

async function toggleActive(row, value) {
  try {
    await updateFeeRule(row.fee_rule_id, { is_active: value })
    ElMessage.success(value ? '已启用' : '已停用')
  } catch (e) {
    ElMessage.error(e.message)
  } finally {
    reload()
  }
}

async function remove(row) {
  try {
    await ElMessageBox.confirm(
      `删除「${scopeLabel(row.scope)} / ${row.scope_value || '全平台'}」规则？若已被历史订单引用，将改为停用而不是删除。`,
      '删除规则',
      { confirmButtonText: '删除', cancelButtonText: '取消', type: 'warning' }
    )
  } catch (e) {
    return
  }

  try {
    const payload = await deleteFeeRule(row.fee_rule_id)
    ElMessage.success(payload?.message || '已删除')
    reload()
  } catch (e) {
    ElMessage.error(e.message)
  }
}

async function runPreview() {
  if (!String(calc.goods_amount).trim()) {
    ElMessage.error('请填写商品金额')
    return
  }

  calculating.value = true
  try {
    const payload = await previewFee({
      goods_amount: String(calc.goods_amount).trim(),
      category: calc.category || undefined,
      seller_id: calc.seller_id || undefined
    })
    quote.value = payload?.data || null
  } catch (e) {
    ElMessage.error(e.message)
  } finally {
    calculating.value = false
  }
}

onMounted(() => {
  reload(1)
  runPreview()
})
</script>

<style scoped lang="scss">
.dim {
  color: var(--goat-text-dim);
  font-size: 12px;
}

.calc-card {
  margin-top: 16px;
  padding: 18px 20px 20px;
  background: #fff;
  border: 1px solid var(--goat-line);
  border-radius: 10px;
}

.calc-head {
  display: flex;
  align-items: baseline;
  gap: 12px;
  margin-bottom: 14px;

  h3 {
    margin: 0;
    font-size: 14px;
  }
}

.calc-form {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 14px;
}

.calc-result {
  :deep(.el-descriptions__label) {
    width: 96px;
  }
}

.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0 18px;
}
</style>

<!--
  GOAT 潮玩交易平台 / GOAT 潮玩数据采集
  Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
  Author: Aloysius Luo
  本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
-->

<template>
  <view class="addr">
    <view v-if="rows.length" class="addr__list">
      <view
        v-for="item in rows"
        :key="item.address_id"
        class="g-card addr__item"
        :class="{ 'addr__item--pick': selectMode }"
        @tap="pick(item)"
      >
        <view class="addr__head">
          <text class="addr__name">{{ item.receiver_name }}</text>
          <text class="addr__phone">{{ item.receiver_phone }}</text>
          <text v-if="item.is_default" class="g-tag g-tag--accent">默认</text>
        </view>

        <text class="addr__text">{{ item.region }}{{ item.detail }}</text>

        <view class="addr__ops">
          <view
            v-if="!item.is_default"
            class="g-btn g-btn--sm g-btn--ghost"
            @tap.stop="makeDefault(item)"
          >
            <text>设为默认</text>
          </view>
          <view class="g-btn g-btn--sm g-btn--ghost" @tap.stop="openForm(item)"><text>编辑</text></view>
          <view class="g-btn g-btn--sm g-btn--ghost" @tap.stop="remove(item)"><text>删除</text></view>
        </view>
      </view>
    </view>

    <view v-else-if="!loading" class="g-empty"><text>还没有收货地址，先添加一条</text></view>
    <view v-if="loading" class="g-loading"><text>加载中…</text></view>

    <view class="addr__footer">
      <view class="g-btn" @tap="openForm(null)"><text>新增收货地址</text></view>
      <!-- 从下单页跳进来时才说明「点一下就能用」，直接进来自管自的不用这句 -->
      <text v-if="selectMode && rows.length" class="g-dim addr__hint">点选任意一条，作为本次下单的收货地址</text>
      <text v-else class="g-dim addr__hint">最多保存 {{ max }} 条；默认地址会在下单时自动带出</text>
    </view>

    <view v-if="formVisible" class="sheet">
      <view class="sheet__mask" @tap="closeForm" />
      <view class="sheet__body">
        <text class="sheet__title">{{ form.address_id ? '编辑地址' : '新增地址' }}</text>

        <view class="field">
          <text class="field__label">收货人</text>
          <input v-model="form.receiver_name" class="field__input" placeholder="真实姓名" maxlength="32" />
        </view>

        <view class="field">
          <text class="field__label">手机号</text>
          <input
            v-model="form.receiver_phone"
            class="field__input"
            type="number"
            placeholder="快递联系用"
            maxlength="11"
          />
        </view>

        <!-- region picker 直接给省市区分段值，避免手填拼串出错 -->
        <picker mode="region" :value="regionValue" @change="onRegionChange">
          <view class="field">
            <text class="field__label">所在地区</text>
            <text class="field__value" :class="{ 'field__value--empty': !regionText }">
              {{ regionText || '省 / 市 / 区' }}
            </text>
          </view>
        </picker>

        <view class="field field--area">
          <text class="field__label">详细地址</text>
          <textarea
            v-model="form.detail"
            class="field__area"
            placeholder="街道、门牌号、单元室"
            maxlength="120"
            :auto-height="true"
          />
        </view>

        <view class="field field--switch">
          <text class="field__label">设为默认</text>
          <switch :checked="form.is_default" color="#D8FF3E" @change="onDefaultChange" />
        </view>

        <view class="sheet__actions">
          <view class="g-btn g-btn--ghost" @tap="closeForm"><text>取消</text></view>
          <view class="g-btn" :class="{ 'g-btn--disabled': saving }" @tap="save">
            <text>{{ saving ? '保存中…' : '保存' }}</text>
          </view>
        </view>
      </view>
    </view>
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onLoad, onShow } from '@dcloudio/uni-app'
import {
  createAddress,
  listAddresses,
  removeAddress,
  setDefaultAddress,
  stashSelectedAddress,
  updateAddress
} from '../../api/address'
import { isLoggedIn } from '../../store/session'
import { toast } from '../../utils/ui'

const rows = ref([])
const loading = ref(false)
const max = ref(10)
const selectMode = ref(false)

const formVisible = ref(false)
const saving = ref(false)
const form = ref(emptyForm())

function emptyForm() {
  return {
    address_id: null,
    receiver_name: '',
    receiver_phone: '',
    province: '',
    city: '',
    district: '',
    detail: '',
    is_default: false
  }
}

const regionText = computed(() => {
  const { province, city, district } = form.value
  return [province, city, district].filter(Boolean).join(' / ')
})

/** region picker 的 value 是「省/市/区」三段，缺省给空串 */
const regionValue = computed(() => [form.value.province, form.value.city, form.value.district])

async function load() {
  if (!isLoggedIn()) {
    uni.navigateTo({ url: '/pages/login/index' })
    return
  }

  loading.value = true
  try {
    const payload = await listAddresses()
    rows.value = payload.data || []
    max.value = payload.meta?.max ?? 10
  } catch (e) {
    // 请求层已提示
  } finally {
    loading.value = false
  }
}

/** 选择模式：把选中的地址交给下单页，自身立即返回 */
function pick(item) {
  if (!selectMode.value) return

  stashSelectedAddress(item)
  uni.navigateBack()
}

async function makeDefault(item) {
  try {
    await setDefaultAddress(item.address_id)
    await load()
  } catch (e) {
    // 请求层已提示
  }
}

function remove(item) {
  uni.showModal({
    title: '删除地址',
    content: `确定删除「${item.receiver_name} ${item.region}」吗？已下单的订单不受影响。`,
    confirmColor: '#D8FF3E',
    success: async (res) => {
      if (!res.confirm) return

      try {
        await removeAddress(item.address_id)
        await load()
        toast('已删除')
      } catch (e) {
        // 请求层已提示
      }
    }
  })
}

function openForm(item) {
  if (!item && rows.value.length >= max.value) {
    toast(`最多保存 ${max.value} 条，请先删除不用的`)
    return
  }

  form.value = item
    ? {
        address_id: item.address_id,
        receiver_name: item.receiver_name,
        receiver_phone: item.receiver_phone,
        province: item.province,
        city: item.city,
        district: item.district || '',
        detail: item.detail,
        is_default: !!item.is_default
      }
    : emptyForm()

  formVisible.value = true
}

function closeForm() {
  formVisible.value = false
}

function onRegionChange(event) {
  const [province, city, district] = event.detail.value || []
  form.value.province = province || ''
  form.value.city = city || ''
  form.value.district = district || ''
}

function onDefaultChange(event) {
  form.value.is_default = !!event.detail.value
}

async function save() {
  if (saving.value) return

  const data = form.value
  if (!data.receiver_name.trim()) return toast('请填写收货人')
  if (!/^1[3-9]\d{9}$/.test(data.receiver_phone)) return toast('请填写正确的手机号')
  if (!data.province || !data.city) return toast('请选择所在地区')
  if (!data.detail.trim()) return toast('请填写详细地址')

  const payload = {
    receiver_name: data.receiver_name.trim(),
    receiver_phone: data.receiver_phone,
    province: data.province,
    city: data.city,
    district: data.district || null,
    detail: data.detail.trim(),
    is_default: !!data.is_default
  }

  saving.value = true
  try {
    if (data.address_id) {
      await updateAddress(data.address_id, payload)
    } else {
      await createAddress(payload)
    }

    formVisible.value = false
    await load()
    toast('已保存')
  } catch (e) {
    // 请求层已提示
  } finally {
    saving.value = false
  }
}

onLoad((options) => {
  selectMode.value = options?.select === '1'
  if (selectMode.value) uni.setNavigationBarTitle({ title: '选择收货地址' })
})

// 从表单/其他页返回都要重拉：默认地址可能在别处被改过
onShow(load)
</script>

<style lang="scss" scoped>
.addr {
  min-height: 100vh;
  padding: 24rpx 24rpx 200rpx;
}

.addr__list {
  display: flex;
  flex-direction: column;
  gap: 16rpx;
}

.addr__item--pick:active {
  opacity: 0.7;
}

.addr__head {
  display: flex;
  align-items: center;
  gap: 16rpx;
}

.addr__name {
  color: $goat-text;
  font-size: 30rpx;
  font-weight: 600;
}

.addr__phone {
  color: rgba(255, 255, 255, 0.56);
  font-size: 26rpx;
}

.addr__text {
  display: block;
  margin-top: 12rpx;
  color: rgba(255, 255, 255, 0.76);
  font-size: 26rpx;
  line-height: 1.55;
}

.addr__ops {
  display: flex;
  gap: 16rpx;
  margin-top: 20rpx;
}

.addr__footer {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  padding: 20rpx 24rpx calc(20rpx + env(safe-area-inset-bottom));
  background-color: #0b0b0c;
  border-top: 1px solid rgba(255, 255, 255, 0.08);
}

.addr__hint {
  display: block;
  margin-top: 12rpx;
  font-size: 22rpx;
  text-align: center;
}

/* ---------- 表单弹层 ---------- */
.sheet {
  position: fixed;
  inset: 0;
  z-index: 50;
}

.sheet__mask {
  position: absolute;
  inset: 0;
  background-color: rgba(0, 0, 0, 0.72);
}

.sheet__body {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  max-height: 88vh;
  overflow-y: auto;
  padding: 32rpx 32rpx calc(32rpx + env(safe-area-inset-bottom));
  background-color: #141416;
  border-radius: 24rpx 24rpx 0 0;
}

.sheet__title {
  color: $goat-text;
  font-size: 32rpx;
  font-weight: 700;
}

.field {
  display: flex;
  align-items: center;
  gap: 20rpx;
  min-height: 96rpx;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.field__label {
  width: 140rpx;
  color: rgba(255, 255, 255, 0.56);
  font-size: 26rpx;
  flex-shrink: 0;
}

.field__input {
  flex: 1;
  color: $goat-text;
  font-size: 28rpx;
}

.field__value {
  flex: 1;
  color: $goat-text;
  font-size: 28rpx;
}

.field__value--empty {
  color: rgba(255, 255, 255, 0.32);
}

.field--area {
  align-items: flex-start;
  padding: 24rpx 0;
}

.field__area {
  flex: 1;
  min-height: 96rpx;
  color: $goat-text;
  font-size: 28rpx;
  line-height: 1.5;
}

.field--switch {
  justify-content: space-between;
}

.sheet__actions {
  display: flex;
  gap: 20rpx;
  margin-top: 32rpx;
}

.sheet__actions .g-btn {
  flex: 1;
  text-align: center;
}
</style>

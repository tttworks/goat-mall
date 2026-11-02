<!--
  GOAT 潮玩交易平台 / GOAT 潮玩数据采集
  Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
  Author: Aloysius Luo
  本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
-->

<template>
  <view class="edit">
    <view v-if="loading" class="g-loading"><text>加载中…</text></view>

    <block v-else>
      <view class="edit__section">
        <text class="edit__label">商品封面（必填，最多 1 张主图）</text>
        <view class="cover">
          <image v-if="coverUrl" class="cover__image" :src="coverUrl" mode="aspectFill" @tap="pickCover" />
          <view v-else class="cover__empty" @tap="pickCover">
            <text class="cover__plus">＋</text>
            <text class="g-dim">上传封面</text>
          </view>
        </view>
        <text class="g-dim">建议 1:1 正方形，主体占画面 70% 以上；上传后可点图替换</text>
      </view>

      <view class="edit__section">
        <text class="edit__label">商品标题</text>
        <input v-model="form.title" class="g-input" maxlength="160" placeholder="品牌 + 角色 + 版本，例如：BANDAI 龙珠 超赛悟空 景品" placeholder-class="g-placeholder" />
      </view>

      <view class="edit__section">
        <text class="edit__label">绑定图鑑（选填，绑定后成交计入行情）</text>
        <view v-if="figure" class="picked">
          <view class="picked__body">
            <text class="picked__name">{{ figure.name }}</text>
            <text class="g-dim">{{ figure.maker || '厂牌未收录' }}</text>
          </view>
          <text class="picked__clear" @tap="clearFigure">移除</text>
        </view>
        <block v-else>
          <view class="search-box">
            <input
              v-model="figureKeyword"
              class="g-input search-box__input"
              placeholder="搜索图鑑名称"
              placeholder-class="g-placeholder"
              confirm-type="search"
              @confirm="searchFigures"
            />
            <view class="g-btn g-btn--sm search-box__btn" @tap="searchFigures"><text>搜索</text></view>
          </view>
          <view v-if="figureOptions.length" class="options">
            <view v-for="item in figureOptions" :key="item.figure_id" class="options__row" @tap="pickFigure(item)">
              <text class="options__name g-ellipsis">{{ item.name }}</text>
              <text class="g-dim">{{ item.maker || '—' }}</text>
            </view>
          </view>
          <text v-else-if="searched" class="g-dim">没有匹配的图鑑，可以不绑定直接发布</text>
        </block>
      </view>

      <view class="edit__section">
        <text class="edit__label">品相</text>
        <view class="chips">
          <view
            v-for="item in CONDITION_OPTIONS"
            :key="item.value"
            class="g-chip"
            :class="{ 'g-chip--active': form.condition === item.value }"
            @tap="form.condition = item.value"
          >
            <text>{{ item.label }}</text>
          </view>
        </view>
      </view>

      <view class="edit__row">
        <view class="edit__section edit__section--half">
          <text class="edit__label">售价（元）</text>
          <input v-model="form.price" class="g-input" type="digit" placeholder="0.00" placeholder-class="g-placeholder" />
        </view>
        <view class="edit__section edit__section--half">
          <text class="edit__label">库存</text>
          <input v-model="form.stock" class="g-input" type="number" placeholder="1" placeholder-class="g-placeholder" />
        </view>
      </view>

      <view class="edit__section">
        <text class="edit__label">商品描述</text>
        <textarea
          v-model="form.description"
          class="g-input edit__textarea"
          maxlength="5000"
          placeholder="来源、是否有盒、瑕疵位置、发货时间等，写清楚能少很多纠纷"
          placeholder-class="g-placeholder"
        />
      </view>

      <view class="edit__section">
        <text class="edit__label">更多实拍（选填，最多 5 张）</text>
        <view class="gallery">
          <view v-for="(url, index) in galleryUrls" :key="url + index" class="gallery__item">
            <image class="gallery__image" :src="url" mode="aspectFill" />
            <text class="gallery__remove" @tap="removeGallery(index)">×</text>
          </view>
          <view v-if="galleryUrls.length < 5" class="gallery__add" @tap="pickGallery">
            <text class="gallery__plus">＋</text>
          </view>
        </view>
      </view>

      <view v-if="form.figure_id || form.title" class="preview">
        <text class="g-dim preview__hint">买家看到的样子</text>
        <view class="preview__card">
          <image v-if="coverUrl" class="preview__cover" :src="coverUrl" mode="aspectFill" />
          <view v-else class="preview__cover preview__cover--empty"><text>GOAT</text></view>
          <view class="preview__body">
            <text class="preview__title g-clamp-2">{{ form.title || '未填写标题' }}</text>
            <text class="g-tag"><text>{{ conditionLabel(form.condition) }}</text></text>
            <text class="g-price preview__price"><text class="g-price__symbol">¥</text>{{ money(form.price || 0) }}</text>
          </view>
        </view>
      </view>

      <view class="edit__actions">
        <view class="g-btn g-btn--ghost" :class="{ 'g-btn--disabled': saving }" @tap="save(false)">
          <text>存草稿</text>
        </view>
        <view class="g-btn g-btn--primary" :class="{ 'g-btn--disabled': saving }" @tap="save(true)">
          <text>{{ saving ? '保存中…' : '保存并提交审核' }}</text>
        </view>
      </view>

      <text class="g-dim edit__hint">
        提交后由运营审核，通过即在图鑑与搜索结果中出现；价格与品相审核期间不可修改。
      </text>
    </block>
  </view>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { getListing, listFigures } from '../../api/catalog'
import {
  createListing,
  submitListing,
  updateListing,
  uploadListingImage
} from '../../api/seller'
import { CONDITION_OPTIONS, conditionLabel, money } from '../../utils/format'
import { toast } from '../../utils/ui'

const listingId = ref(0)
const loading = ref(false)
const saving = ref(false)
const listingStatus = ref('')
const figure = ref(null)
const figureKeyword = ref('')
const figureOptions = ref([])
const searched = ref(false)
const coverKey = ref('')
const coverPreview = ref('')
const galleryKeys = ref([])
const galleryPreviews = ref([])
/**
 * 编辑态下商品可能已有图，而我们只能拿到 url 拿不到 key（key 不外露给公开详情）。
 * 此时只要没重新上传，就不把 cover_key / gallery_keys 塞进请求——
 * 否则用空数组覆盖会把卖家原有图清掉。
 */
const galleryTouched = ref(false)

const form = reactive({
  title: '',
  description: '',
  figure_id: null,
  condition: 'like_new',
  price: '',
  stock: '1'
})

const isEdit = computed(() => listingId.value > 0)
const coverUrl = computed(() => coverPreview.value)
const galleryUrls = computed(() => galleryPreviews.value)

async function load() {
  if (!isEdit.value) return

  loading.value = true

  try {
    const payload = await getListing(listingId.value)
    const data = payload?.data || {}

    form.title = data.title || ''
    form.description = data.description || ''
    form.figure_id = data.figure_id || null
    form.condition = data.condition || 'like_new'
    form.price = String(data.price ?? '')
    form.stock = String(data.stock ?? 1)
    listingStatus.value = data.status || ''
    figure.value = data.figure || null
    coverKey.value = data.cover_key || ''
    coverPreview.value = data.cover_url || ''
    galleryKeys.value = Array.isArray(data.gallery_keys) ? data.gallery_keys : []
    galleryPreviews.value = Array.isArray(data.gallery_urls) ? data.gallery_urls : []
  } catch (e) {
    toast('商品加载失败')
  } finally {
    loading.value = false
  }
}

async function searchFigures() {
  const keyword = figureKeyword.value.trim()
  if (!keyword) {
    toast('输入图鑑名称再搜')
    return
  }

  const payload = await listFigures({ keyword, per_page: 10 }).catch(() => null)
  figureOptions.value = payload?.data || []
  searched.value = true
}

function pickFigure(item) {
  figure.value = item
  form.figure_id = item.figure_id
  figureOptions.value = []
}

function clearFigure() {
  figure.value = null
  form.figure_id = null
}

/** 统一的选图 + 上传：封面与附图只差目标字段，抽一个函数避免两套失败提示 */
function chooseAndUpload(count, onDone) {
  uni.chooseImage({
    count,
    sizeType: ['compressed'],
    sourceType: ['album', 'camera'],
    success: async (res) => {
      const paths = res.tempFilePaths || []
      if (!paths.length) return

      uni.showLoading({ title: '上传中', mask: true })

      try {
        const uploaded = []

        for (const path of paths) {
          const payload = await uploadListingImage(path)
          if (payload?.data?.key) uploaded.push(payload.data)
        }

        onDone(uploaded)
      } catch (e) {
        /* upload 层已提示 */
      } finally {
        uni.hideLoading()
      }
    }
  })
}

function pickCover() {
  chooseAndUpload(1, (uploaded) => {
    if (!uploaded.length) return
    coverKey.value = uploaded[0].key
    coverPreview.value = uploaded[0].url
  })
}

function pickGallery() {
  const remain = 5 - galleryPreviews.value.length
  if (remain <= 0) return

  chooseAndUpload(remain, (uploaded) => {
    galleryTouched.value = true
    uploaded.forEach((item) => {
      galleryKeys.value = [...galleryKeys.value, item.key]
      galleryPreviews.value = [...galleryPreviews.value, item.url]
    })
  })
}

function removeGallery(index) {
  galleryTouched.value = true
  galleryKeys.value = galleryKeys.value.filter((_, i) => i !== index)
  galleryPreviews.value = galleryPreviews.value.filter((_, i) => i !== index)
}

function validate(submitNow) {
  if (!form.title.trim() || form.title.trim().length < 4) {
    return '标题至少 4 个字，写清品牌与角色'
  }
  if (!/^\d{1,8}(\.\d{1,2})?$/.test(String(form.price).trim())) {
    return '售价格式不对（最多两位小数）'
  }
  if (Number(form.stock) < 1) {
    return '库存至少 1'
  }
  // 上架必须有封面：后端 submit 会拒绝无封面商品，前端先拦一层省一次往返。
  // 编辑已有商品时 coverPreview 是服务端返回的 url，同样算「有封面」。
  if (submitNow && !coverPreview.value) {
    return '上架前请先上传封面图'
  }

  return ''
}

async function save(submitNow) {
  if (saving.value) return

  const error = validate(submitNow)
  if (error) {
    toast(error)
    return
  }

  saving.value = true

  const payload = {
    title: form.title.trim(),
    description: form.description.trim(),
    figure_id: form.figure_id || undefined,
    condition: form.condition,
    price: String(form.price).trim(),
    stock: Number(form.stock) || 1,
    // 只有本次真的上传/删除过才回传，避免「没动图却把图清空」
    cover_key: coverKey.value || undefined,
    gallery_keys: galleryTouched.value ? galleryKeys.value : undefined
  }

  try {
    const saved = isEdit.value
      ? await updateListing(listingId.value, payload)
      : await createListing(payload)

    const id = saved?.data?.listing_id || listingId.value
    const canSubmit = ['', 'draft', 'rejected', 'delisted'].includes(listingStatus.value)

    if (submitNow) {
      if (!canSubmit) {
        toast('当前状态不可重新提交')
      } else {
        await submitListing(id)
        toast('已提交审核')
      }
    } else {
      toast('已保存草稿')
    }

    setTimeout(() => {
      uni.redirectTo({ url: '/pages/seller/listings' })
    }, 600)
  } catch (e) {
    /* request 层已提示具体字段错误 */
  } finally {
    saving.value = false
  }
}

onLoad((options) => {
  listingId.value = Number(options?.id || 0)
  load()
})
</script>

<style lang="scss" scoped>
.edit {
  min-height: 100vh;
  padding: 24rpx 24rpx 200rpx;
  display: flex;
  flex-direction: column;
  gap: 28rpx;
}

.edit__section {
  display: flex;
  flex-direction: column;
  gap: 14rpx;
}

.edit__row {
  display: flex;
  gap: 20rpx;
}

.edit__section--half {
  flex: 1;
}

.edit__label {
  color: $goat-text-muted;
  font-size: 24rpx;
}

.edit__textarea {
  height: 240rpx;
  padding: 20rpx 28rpx;
  line-height: 1.55;
}

.cover__image {
  width: 320rpx;
  height: 320rpx;
  border-radius: $goat-radius;
  background-color: $goat-surface-2;
}

.cover__empty {
  width: 320rpx;
  height: 320rpx;
  border-radius: $goat-radius;
  border: 1rpx dashed $goat-border;
  background-color: $goat-surface;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8rpx;
}

.cover__plus {
  color: $goat-text-dim;
  font-size: 48rpx;
}

.picked {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16rpx;
  padding: 20rpx 24rpx;
  border-radius: $goat-radius-sm;
  background-color: rgba(216, 255, 62, 0.1);
  border: 1rpx solid rgba(216, 255, 62, 0.3);
}

.picked__body {
  display: flex;
  flex-direction: column;
  gap: 6rpx;
  min-width: 0;
}

.picked__name {
  color: $goat-text;
  font-size: 26rpx;
}

.picked__clear {
  color: $goat-accent;
  font-size: 24rpx;
}

.search-box {
  display: flex;
  gap: 16rpx;
}

.search-box__input {
  flex: 1;
}

.search-box__btn {
  flex-shrink: 0;
  height: 96rpx;
}

.options {
  border-radius: $goat-radius-sm;
  border: 1rpx solid $goat-border;
  overflow: hidden;
}

.options__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16rpx;
  padding: 22rpx 24rpx;
  border-bottom: 1rpx solid $goat-border;
}

.options__row:last-child {
  border-bottom: none;
}

.options__name {
  flex: 1;
  color: $goat-text;
  font-size: 26rpx;
}

.chips {
  display: flex;
  flex-wrap: wrap;
  gap: 16rpx;
}

.gallery {
  display: flex;
  flex-wrap: wrap;
  gap: 16rpx;
}

.gallery__item {
  position: relative;
  width: 160rpx;
  height: 160rpx;
}

.gallery__image {
  width: 160rpx;
  height: 160rpx;
  border-radius: $goat-radius-sm;
  background-color: $goat-surface-2;
}

.gallery__remove {
  position: absolute;
  top: -8rpx;
  right: -8rpx;
  width: 40rpx;
  height: 40rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  background-color: rgba(11, 11, 12, 0.9);
  border: 1rpx solid $goat-border;
  color: $goat-text-muted;
  font-size: 26rpx;
}

.gallery__add {
  width: 160rpx;
  height: 160rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: $goat-radius-sm;
  border: 1rpx dashed $goat-border;
}

.gallery__plus {
  color: $goat-text-dim;
  font-size: 40rpx;
}

.preview {
  display: flex;
  flex-direction: column;
  gap: 12rpx;
}

.preview__card {
  display: flex;
  gap: 20rpx;
  padding: 20rpx;
  border-radius: $goat-radius;
  background-color: $goat-surface;
  border: 1rpx solid $goat-border;
}

.preview__cover {
  width: 160rpx;
  height: 160rpx;
  border-radius: $goat-radius-sm;
  background-color: $goat-surface-2;
  flex-shrink: 0;
}

.preview__cover--empty {
  display: flex;
  align-items: center;
  justify-content: center;
  color: rgba(255, 255, 255, 0.12);
  font-size: 20rpx;
}

.preview__body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 10rpx;
  align-items: flex-start;
}

.preview__title {
  color: $goat-text;
  font-size: 26rpx;
  line-height: 1.45;
}

.preview__price {
  font-size: 32rpx;
}

.edit__actions {
  display: flex;
  gap: 20rpx;
}

.edit__actions .g-btn {
  flex: 1;
}

.edit__hint {
  line-height: 1.6;
}
</style>

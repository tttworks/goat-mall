<!--
  GOAT 潮玩交易平台 / GOAT 潮玩数据采集
  Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
  Author: Aloysius Luo
  本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
-->

<template>
  <view class="search">
    <view class="search__bar">
      <view class="search__box">
        <text class="search__icon">⌕</text>
        <input
          v-model="keyword"
          class="search__input"
          placeholder="搜图鑑 / 商品 / 系列"
          placeholder-class="g-placeholder"
          confirm-type="search"
          :focus="true"
          @confirm="doSearch(keyword)"
        />
        <view v-if="keyword" class="search__clear" @tap="keyword = ''"><text>×</text></view>
      </view>
      <text class="search__submit" @tap="doSearch(keyword)">搜索</text>
    </view>

    <!-- 没搜过任何词：给历史与热门，避免一进来就是纯白页 -->
    <view v-if="!searched" class="search__panel">
      <view v-if="history.length" class="search__block">
        <view class="g-row--between search__block-head">
          <text class="g-title">最近搜索</text>
          <text class="g-dim" @tap="clearHistory">清空</text>
        </view>
        <view class="search__tags">
          <view v-for="word in history" :key="word" class="g-chip" @tap="doSearch(word)">
            <text>{{ word }}</text>
          </view>
        </view>
      </view>

      <view class="search__block">
        <text class="g-title search__block-head">热门方向</text>
        <view class="search__tags">
          <view v-for="word in HOT_WORDS" :key="word" class="g-chip" @tap="doSearch(word)">
            <text>{{ word }}</text>
          </view>
        </view>
      </view>

      <view class="search__block">
        <text class="g-title search__block-head">按品类逛</text>
        <view class="search__row" v-for="item in categories" :key="item.value" @tap="pickCategory(item.value)">
          <text class="search__row-label">{{ item.label }}</text>
          <text class="g-dim">›</text>
        </view>
        <view v-if="!categories.length" class="g-dim">品类由后台维护，稍后自动出现</view>
      </view>
    </view>

    <view v-else class="search__result">
      <view class="search__tabs">
        <view
          class="search__tab"
          :class="{ 'search__tab--active': tab === 'figure' }"
          @tap="switchTab('figure')"
        >
          <text>图鑑 {{ figureTotal }}</text>
        </view>
        <view
          class="search__tab"
          :class="{ 'search__tab--active': tab === 'listing' }"
          @tap="switchTab('listing')"
        >
          <text>在售 {{ listingTotal }}</text>
        </view>
      </view>

      <view v-if="tab === 'figure'">
        <view v-if="figures.length" class="search__grid">
          <view
            v-for="figure in figures"
            :key="figure.figure_id"
            class="card"
            @tap="openFigure(figure.figure_id)"
          >
            <view class="card__cover">
              <image v-if="figure.cover_url" class="card__image" :src="figure.cover_url" mode="aspectFill" />
              <view v-else class="card__placeholder"><text>GOAT</text></view>
            </view>
            <view class="card__body">
              <text class="card__name g-clamp-2">{{ figure.name }}</text>
              <text class="card__maker g-ellipsis">{{ figure.maker || '厂牌未收录' }}</text>
              <text v-if="figure.price_low" class="g-price card__price">
                <text class="g-price__symbol">¥</text>{{ money(figure.price_low) }}
              </text>
              <text v-else class="g-dim">暂无报价</text>
            </view>
          </view>
        </view>
        <view v-else-if="!loading" class="g-empty">
          <text>没有匹配的图鑑，换个说法试试（例如只搜系列名）</text>
        </view>
      </view>

      <view v-else>
        <view v-if="listings.length" class="search__list">
          <view
            v-for="item in listings"
            :key="item.listing_id"
            class="goods"
            @tap="openListing(item.listing_id)"
          >
            <image v-if="item.cover_url" class="goods__cover" :src="item.cover_url" mode="aspectFill" />
            <view v-else class="goods__cover goods__cover--empty"><text>GOAT</text></view>
            <view class="goods__body">
              <text class="goods__title g-clamp-2">{{ item.title }}</text>
              <view class="g-row--between">
                <text class="g-tag"><text>{{ conditionLabel(item.condition) }}</text></text>
                <text class="g-price goods__price">
                  <text class="g-price__symbol">¥</text>{{ money(item.price) }}
                </text>
              </view>
            </view>
          </view>
        </view>
        <view v-else-if="!loading" class="g-empty">
          <text>暂时没有在售的「{{ keyword }}」，可以收藏图鑑等出货通知</text>
        </view>
      </view>

      <view v-if="loading" class="g-loading"><text>搜索中…</text></view>
    </view>
  </view>
</template>

<script setup>
import { ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { listFigures, listListings } from '../../api/catalog'
import { conditionLabel, money } from '../../utils/format'
import { toast } from '../../utils/ui'

const HISTORY_KEY = 'goat:search:history'
const HOT_WORDS = ['龙珠', '高达', '灌篮高手', '假面骑士', '三丽鸥', 'BANDAI', '1/6 手办']

const keyword = ref('')
const tab = ref('figure')
const searched = ref(false)
const loading = ref(false)
const figures = ref([])
const listings = ref([])
const figureTotal = ref(0)
const listingTotal = ref(0)
const categories = ref([])
const history = ref(readHistory())

function readHistory() {
  try {
    const rows = uni.getStorageSync(HISTORY_KEY)
    return Array.isArray(rows) ? rows.slice(0, 10) : []
  } catch (e) {
    return []
  }
}

/** 历史只留最近 10 条、去重，且不保存单字符（噪音大于价值） */
function remember(word) {
  const value = word.trim()
  if (value.length < 2) return

  const next = [value, ...history.value.filter((item) => item !== value)].slice(0, 10)
  history.value = next
  uni.setStorageSync(HISTORY_KEY, next)
}

function clearHistory() {
  history.value = []
  uni.removeStorageSync(HISTORY_KEY)
}

async function runSearch(word) {
  const value = word.trim()
  if (!value) {
    toast('请输入关键词')
    return
  }

  keyword.value = value
  searched.value = true
  loading.value = true

  try {
    // 两条接口并行：任一侧失败也保留另一侧结果，不用 Promise.all 把整页拖垮
    const [figurePayload, listingPayload] = await Promise.all([
      listFigures({ keyword: value, per_page: 20 }).catch(() => null),
      listListings({ keyword: value, per_page: 20 }).catch(() => null)
    ])

    figures.value = figurePayload?.data || []
    figureTotal.value = figurePayload?.meta?.total ?? figures.value.length
    listings.value = listingPayload?.data || []
    listingTotal.value = listingPayload?.meta?.total ?? listings.value.length
    if (figurePayload?.meta?.categories) {
      categories.value = normalizeCategories(figurePayload.meta.categories)
    }

    // 图鑑没结果但商品有：自动切到「在售」，别让用户以为搜不到
    tab.value = figures.value.length === 0 && listings.value.length > 0 ? 'listing' : 'figure'
  } finally {
    loading.value = false
  }
}

function doSearch(word) {
  remember(String(word || ''))
  runSearch(String(word || ''))
}

function pickCategory(value) {
  // 品类检索等价于一次空关键词的图鑑筛选：这里直接跳回首页并带上品类更自然，
  // 但搜索页是栈内页面，用 navigateBack + 事件把选择传回去最省一次请求
  uni.$emit('goat:pick-category', value)
  uni.navigateBack()
}

function normalizeCategories(options) {
  return (options || []).map((item) =>
    typeof item === 'string' ? { value: item, label: item } : { value: item.value, label: item.label }
  )
}

function switchTab(next) {
  tab.value = next
}

function openFigure(id) {
  uni.navigateTo({ url: `/pages/figure/detail?id=${id}` })
}

function openListing(id) {
  uni.navigateTo({ url: `/pages/listing/detail?id=${id}` })
}

onLoad((options) => {
  if (options?.keyword) doSearch(decodeURIComponent(options.keyword))
})
</script>

<style lang="scss" scoped>
.search {
  min-height: 100vh;
  padding: 24rpx 24rpx 48rpx;
}

.search__bar {
  display: flex;
  align-items: center;
  gap: 16rpx;
}

.search__box {
  flex: 1;
  display: flex;
  align-items: center;
  height: 88rpx;
  padding: 0 24rpx;
  border-radius: 999rpx;
  background-color: $goat-surface;
  border: 1rpx solid $goat-border;
}

.search__icon {
  margin-right: 12rpx;
  color: $goat-text-dim;
  font-size: 32rpx;
}

.search__input {
  flex: 1;
  color: $goat-text;
  font-size: 28rpx;
}

.search__clear {
  padding: 0 8rpx;
  color: $goat-text-dim;
  font-size: 34rpx;
}

.search__submit {
  color: $goat-accent;
  font-size: 28rpx;
  font-weight: 600;
}

.search__panel {
  margin-top: 32rpx;
  display: flex;
  flex-direction: column;
  gap: 40rpx;
}

.search__block-head {
  display: block;
  margin-bottom: 20rpx;
}

.search__tags {
  display: flex;
  flex-wrap: wrap;
  gap: 16rpx;
}

.search__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  height: 96rpx;
  padding: 0 4rpx;
  border-bottom: 1rpx solid $goat-border;
}

.search__row-label {
  color: $goat-text;
  font-size: 28rpx;
}

.search__result {
  margin-top: 24rpx;
}

.search__tabs {
  display: flex;
  gap: 16rpx;
  margin-bottom: 24rpx;
}

.search__tab {
  flex: 1;
  height: 76rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 999rpx;
  background-color: $goat-surface;
  border: 1rpx solid $goat-border;
  color: $goat-text-muted;
  font-size: 26rpx;
}

.search__tab--active {
  background-color: $goat-accent;
  border-color: $goat-accent;
  color: $goat-accent-ink;
  font-weight: 600;
}

.search__grid {
  display: flex;
  flex-wrap: wrap;
  gap: 20rpx;
}

.card {
  width: calc((100% - 20rpx) / 2);
  border-radius: $goat-radius;
  background-color: $goat-surface;
  border: 1rpx solid $goat-border;
  overflow: hidden;
}

.card__cover {
  width: 100%;
  height: 320rpx;
  background-color: $goat-surface-2;
}

.card__image {
  width: 100%;
  height: 100%;
}

.card__placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  color: rgba(255, 255, 255, 0.12);
  font-size: 40rpx;
  font-weight: 800;
  letter-spacing: 4rpx;
}

.card__body {
  padding: 16rpx 20rpx 20rpx;
}

.card__name {
  color: $goat-text;
  font-size: 26rpx;
  font-weight: 600;
  line-height: 1.4;
  min-height: 72rpx;
}

.card__maker {
  display: block;
  margin-top: 6rpx;
  color: $goat-text-muted;
  font-size: 22rpx;
}

.card__price {
  display: block;
  margin-top: 12rpx;
  font-size: 30rpx;
}

.search__list {
  display: flex;
  flex-direction: column;
  gap: 20rpx;
}

.goods {
  display: flex;
  gap: 20rpx;
  padding: 20rpx;
  border-radius: $goat-radius;
  background-color: $goat-surface;
  border: 1rpx solid $goat-border;
}

.goods__cover {
  width: 168rpx;
  height: 168rpx;
  border-radius: $goat-radius-sm;
  background-color: $goat-surface-2;
  flex-shrink: 0;
}

.goods__cover--empty {
  display: flex;
  align-items: center;
  justify-content: center;
  color: rgba(255, 255, 255, 0.12);
  font-size: 22rpx;
}

.goods__body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}

.goods__title {
  color: $goat-text;
  font-size: 26rpx;
  line-height: 1.45;
}

.goods__price {
  font-size: 32rpx;
}
</style>

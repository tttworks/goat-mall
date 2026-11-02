<!--
  GOAT 潮玩交易平台 / GOAT 潮玩数据采集
  Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
  Author: Aloysius Luo
  本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
-->

<script>
import { bootstrapSession } from './store/session'

export default {
  onLaunch() {
    // 冷启动时先用本地缓存恢复登录态，页面不必各自等待 /me 返回
    bootstrapSession()
  }
}
</script>

<style lang="scss">
@import './styles/theme.scss';

/* 全局底色：暗色底 + 荧光绿，贴合潮玩/街头调性；页面级样式不再重复定义变量 */
page {
  background-color: $goat-bg;
  color: $goat-text;
  font-family: -apple-system, BlinkMacSystemFont, 'PingFang SC', 'Helvetica Neue', Arial, sans-serif;
  font-size: 28rpx;
  line-height: 1.5;
}

view,
text,
scroll-view {
  box-sizing: border-box;
}
</style>

# GOAT Mall · 潮玩可动玩具 C2C 电商平台

> 多店铺潮玩可动玩具 C2C 运营平台（中国大陆市场）。电商对标微店+闲鱼混合体，社群对标 Facebook+小红书，行情三源（站内/eBay/煤炉 Mercari），全功能参考 mmacg.tw 并大陆化。
> 电商交易域是全项目的重中之重。

**权威文档**：产品与技术路线以 `D:/#工作文档/02.合作伙伴/GreatestOfAllToyz/潮玩电商平台-开发方案-v1.md`（当前 v1.4）为单一权威源，本仓库 `docs/` 存工程约定与执行记录。

## 仓库结构（Monorepo）

| 目录 | 内容 | 技术栈 |
|---|---|---|
| `backend/` | 后端 API + WebSocket + 队列 | PHP 8.3 + Laravel 12 (Octane/Reverb/Horizon) |
| `worker-pricing/` | 采集侧目录说明（实现代码在独立仓 `goat-collector`） | — |
| `app-mobile/` | C 端三端（买家 + 卖家手机后台分包） | uni-app (Vue3 + Vite) |
| `admin-pc/` | 平台管理后台（PC）：图鑑 / 卖家 / 商品 / 帖子审核 + 采集触发 | Vue3 + Element Plus + Vite |
| `docs/` | 项目约定 / 架构契约 / 架构说明 / 进度日历 / 开发日志 / 踩坑记录 / 软著材料清单 | — |

## 基础设施（v1.4 定案）

- 主库：**PostgreSQL 17 + TimescaleDB**（交易 OLTP + 行情时序同库）
- 缓存/队列/实时底座：**Redis 7**（PgBouncer 配套连接池）
- 搜索：Meilisearch
- 对象存储：UCloud UFile（S3 协议起步）→ 正式期迁腾讯 COS / 阿里 OSS（DB 只存 key）
- 临时站：东京 BitsFlow 216.23.112.40（`goat.websitedemos.cn`）

## 本地跑起来

```powershell
# 后端 API（http://127.0.0.1:8000）
cd backend
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate --seed          # 首次会建运营管理员：口令取 GOAT_ADMIN_PASSWORD，留空则随机生成并打印一次
php artisan serve

# 运营后台（http://127.0.0.1:5174，/api 代理到 8000）
cd admin-pc
npm install
npm run dev

# C 端 H5（uni-app 自带 dev server）
cd app-mobile
npm install
npm run dev:h5
```

- 实时推送默认关闭（`GOAT_WS_ENABLED=false`）：前端检测未配置即退回 HTTP 拉取，不影响任何功能；接 Reverb 后改成 `true` 并填 `REVERB_*`。
- 采集触发需要 `COLLECTOR_BASE_URL` / `COLLECTOR_HMAC_KEY`，未配置时后台返回 503 并提示"采集服务未配置"，属预期行为。

## 开发守则

1. **动代码/部署前必读**：`docs/项目约定.md` → `docs/进度日历.md` → `docs/踩坑记录.md`
2. **任何凭据不进仓库**（.env.example 起步，服务器侧维护 .env）
3. 分支：`main`（部署临时站）← `develop` ← `feature/*`；每次部署打 tag
4. 换行符已由 `.gitattributes` 统一为 LF，勿删除该文件

# GOAT Mall · 潮玩可动玩具 C2C 电商平台

> 多店铺潮玩可动玩具 C2C 运营平台（中国大陆市场）。电商对标微店+闲鱼混合体，社群对标 Facebook+小红书，行情三源（站内/eBay/煤炉 Mercari），全功能参考 mmacg.tw 并大陆化。
> 电商交易域是全项目的重中之重。

**权威文档**：产品与技术路线以 `D:/#工作文档/02.合作伙伴/GreatestOfAllToyz/潮玩电商平台-开发方案-v1.md`（当前 v1.4）为单一权威源，本仓库 `docs/` 存工程约定与执行记录。

## 仓库结构（Monorepo）

| 目录 | 内容 | 技术栈 |
|---|---|---|
| `backend/` | 后端 API + WebSocket + 队列 | PHP 8.3 + Laravel 12 (Octane/Reverb/Horizon) |
| `worker-pricing/` | 行情采集与圖鑑爬虫 Worker | Python 3.12 + FastAPI + Playwright |
| `app-mobile/` | C 端三端（买家 + 卖家手机后台分包） | uni-app (Vue3 + Vite) |
| `admin-pc/` | 平台管理后台（PC） | Vue3 + Element Plus + Vite |
| `docs/` | 项目约定 / 架构契约 / 架构说明 / 进度日历 / 开发日志 / 踩坑记录 / 软著材料清单 | — |

## 基础设施（v1.4 定案）

- 主库：**PostgreSQL 17 + TimescaleDB**（交易 OLTP + 行情时序同库）
- 缓存/队列/实时底座：**Redis 7**（PgBouncer 配套连接池）
- 搜索：Meilisearch
- 对象存储：UCloud UFile（S3 协议起步）→ 正式期迁腾讯 COS / 阿里 OSS（DB 只存 key）
- 临时站：东京 BitsFlow 216.23.112.40（`goat.websitedemos.cn`）

## 开发守则

1. **动代码/部署前必读**：`docs/项目约定.md` → `docs/进度日历.md` → `docs/踩坑记录.md`
2. **任何凭据不进仓库**（.env.example 起步，服务器侧维护 .env）
3. 分支：`main`（部署临时站）← `develop` ← `feature/*`；每次部署打 tag
4. 换行符已由 `.gitattributes` 统一为 LF，勿删除该文件

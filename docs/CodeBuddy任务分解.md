# CodeBuddy 任务分解（GOAT Mall 双系统并行开发）

> **用途**：把 GOAT 项目拆成可在 CodeBuddy 内「多模块 agent 并行」的任务清单。
> **唯一事实来源**：`goat-mall/docs/架构契约.md`（双系统边界 / 共享 PG 表 / Redis 队列 / 内部 API / 双 LLM 路由 / 软著边界）。任何 agent 动手前**先读契约**，再读本文件对应任务。
> **版本**：v1.0　生效 2026-11-03。改动任务前先改契约，再改这里。

---

## 0. 全局硬约束（每个 agent 必读，违反即作废）

1. **著作权人** = 火山文化科技（天津市火山文化科技有限责任公司）；**作者** = Aloysius Luo。每个源文件头部加 `docs/copyright-header.txt` 声明。
2. **命名隔离**：产品代码前缀用 `goat`；允许所有者级 `ttt` / `volcano` / `huoshan`；**禁止 `tttworks` / `vasture`**（含大小写）出现在任何参数/变量/包名/类名/配置键/DB 标识符/队列名/目录名。
3. **双 LLM 路由**：DeepSeek 做批量生成/脚手架/CRUD/迁移；智谱 GLM 5.3 做中文业务语义、支付分账/竞价/费率精细逻辑与审校。**禁止两模型对同一份代码并行改写**。
4. **WAF 不内联**：自研 WAF 面板为独立开源项目，本系统仅外部安全层集成，**绝不引入其源码**。
5. **软著边界**：只写 GOAT 自有代码，排除 `node_modules`/vendor/框架内部；站点与采集分别登记软著。
6. **两仓物理隔离**：`goat-mall`（Laravel+uni-app+PC后台）与 `goat-collector`（Python 采集）互不 import 源码，仅经 PG + Redis + 内部 API 通信。

---

## 1. 仓库与目录

- **站点系统** `goat-mall`：`backend/`(Laravel) · `app-mobile/`(uni-app) · `admin-pc/`(Vue3+ElementPlus) · `docs/`
- **采集系统** `goat-collector`：`src/goat_collector/`（adapters/ingest/cli）· Python 3.12

### 1.1 在 CodeBuddy 里打开「哪个文件夹」（重要）

> ⚠️ WorkBuddy 里选的 `D:\#工作文档\02.合作伙伴\GreatestOfAllToyz\` **只是项目记忆/文档目录**（含 `.workbuddy/` 与原始方案 docx），**不含任何代码**。代码在下面两个独立 git 仓，打开那个目录看不到代码。

**本地实际路径**（两仓同父目录 `D:\github\Clone repository\`）：
- 站点系统：`D:\github\Clone repository\goat-mall\`
- 采集系统：`D:\github\Clone repository\goat-collector\`

**CodeBuddy 打开方式（二选一）**：

- **方式 A（推荐，跨仓可见）**：直接打开父目录 `D:\github\Clone repository\` 作为工作区根。一个窗口即可见 `goat-mall` 与 `goat-collector` 两仓、可读 `goat-mall/docs/架构契约.md`、可跨仓参考。适合需要跨系统协作的 agent。
- **方式 B（严格隔离，每窗一仓）**：开**两个** CodeBuddy 窗口，分别打开 `D:\github\Clone repository\goat-mall\`（负责 T1–T8、T10）与 `D:\github\Clone repository\goat-collector\`（负责 T9）。最契合「双独立系统」边界，git 历史/权限互不串。

**契约在哪读**：架构契约在 `goat-mall/docs/架构契约.md`。方式 A 两仓都看得到；方式 B 下 `goat-collector` 窗口通过本仓 `CODEBUDDY.md` 指向 `../goat-mall/docs/架构契约.md`（相对路径，因两仓同父目录），agent 动手前先读。

**git 分支纪律**（呼应冲突规避）：两仓均 `main`(部署源) ← `develop` ← `feature/*`；agent 各自 feature 分支，PR 合 `develop`，**不在 `main` 直接推**。

**WorkBuddy ↔ CodeBuddy 边界**：WorkBuddy 已退出代码目录，后续只管文档/契约/服务器部署；代码全交 CodeBuddy。需要 WorkBuddy 动代码文件时先与 CodeBuddy 侧对齐「同一处没人同时在改」。

---

## 2. 任务清单

### T1 · 站点后端骨架（goat-mall/backend）— DeepSeek 主导
- 目标：Laravel 12 + Octane(Swoole) + Reverb(WS) + Horizon 可启动；`php artisan octane:start` 监听 :8000
- 范围：骨架、环境、PG 连接（PgBouncer）、Redis、Meili 客户端、UFile flysystem 适配器（DB 只存 key）
- 接口契约：遵循契约 §2 表结构；UFile URL 由 `MEDIA_BASE_URL` 配置拼
- 完成标准：本地/服务器 `octane:start` 起得来，`php artisan route:list` 有基础路由；vhost 反代 :8000（nginx 由宝塔管）
- 依赖：T3/T4 的迁移由各自任务产出，T1 先铺 migrations 基线

### T2 · 认证与用户体系（goat-mall/backend）— GLM 5.3 审校业务语义
- 目标：注册登录矩阵（用户名 / email / 微信 openid / Apple）+ 卖家开店 + 实名核身接口
- 范围：`users`/`identities` 表（phone 预留）、OAuth、卖家角色、人脸核身对接（按次付费 API，仅开店触发）
- 完成标准：四通道可注册登录；卖家开店触发实名；权限中间件就位
- 注意：手机号短信**初期不启用**（零采购原则）

### T3 · 图鑑管理端（goat-mall/backend + admin-pc）— DeepSeek
- 目标：`figures` 表 CRUD（仅管理员发布，Wiki 式）+ 媒体上传 UFile + 搜索筛选
- 接口契约：契约 §2.1 `figures` 字段；外部 ID 用 GIN 索引
- 完成标准：后台可录入/编辑图鑑，列表多维筛选，封面/画廊走 UFile key
- 数据来源：由 T9 采集系统灌入，T3 只做管理面

### T4 · 商品 / 交易 / 竞价（goat-mall/backend）— GLM 5.3（支付/竞价精细逻辑）
- 目标：`listings` / `orders` / `payments` / `auctions` 模型 + 状态机 + 幂等键
- 范围：上架/浏览/搜索、下单、担保交易、**竞价（防狙击/竞态）**、费率后台可配置（百分比+封顶+承担方，可设 0）
- 完成标准：端到端担保交易沙箱走通 + 完整竞拍流程可演示（W2 出口）
- 注意：支付走沙箱，真实收付通分账在 W8 联调

### T5 · 支付沙箱（goat-mall/backend）— GLM 5.3
- 目标：模拟收付通二级商户/冻结/分账流程，落 `payments` 状态机
- 完成标准：回调幂等、非法状态跃迁告警、分账计算正确

### T6 · 社群 / IM / 通知（goat-mall/backend + app-mobile）— DeepSeek
- 目标：图片帖 + 审核管线（机审 API）、私信（Reverb WS）、通知中心、收藏/购物车/关注/评价
- 完成标准：发帖→审核→展示全链路 + IM 并发可用（W4 出口）

### T7 · uni-app 移动端（goat-mall/app-mobile）— DeepSeek
- 目标：H5 / iOS / Android / 微信小程序 四端同源；C 端体验优先
- 范围：图鑑浏览、商品、交易、竞价、社群前端；Apple 登录（App 端必带 Sign in with Apple）
- 完成标准：四端可构建；H5 禁止直访（仅 App/小程序入口，W7 管制）

### T8 · PC 后台（goat-mall/admin-pc）— DeepSeek
- 目标：Vue3 + ElementPlus 管理后台（图鑑/商品/订单/竞价/费率/审核）
- 完成标准：后台基础可操作（W2 出口）

### T9 · 采集系统（goat-collector）— DeepSeek 生成 + GLM 复核数值语义
- 目标：图鑑底库抓取（MFC / 78dm / AmiAmi）+ 行情采集（Mercari 日本 IP / eBay）
- 范围：`adapters/*`（已含 MFC 骨架）、`ingest.py`（清洗→PG）、Redis 队列消费、`price-engine`（复用 jp-ebay-price-toolkit 算法，IQR/中位数/偏离带）
- 接口契约：契约 §3 Redis 队列、§4 内部 API、§2 表结构
- 完成标准：本地可跑 MFC 抓取出 schema 合规 JSON；服务端 worker 定时增量重爬；行情写入 `price_points` hypertable
- **优先级最高**（客户确认）：图鑑入库先于行情

### T10 · 基础设施 / CI / WAF 集成（goat-mall）— DeepSeek
- 目标：GitHub Actions 部署、宝塔站点的 Octane 反代、WAF 外部集成（nginx 配置/监控面板接入）
- 范围：监控维度（Octane/Reverb/PgBouncer/Redis/Meili/Horizon/采集器）对接自研 WAF 面板（**仅配置集成，不含其源码**）
- 完成标准：push→部署；WAF 规则与 Horizon 失败队列联动告警

---

## 3. 并行纪律（避免冲突 → 否则软著/合并灾难）

- 每个 agent **先读 `架构契约.md` 与自己任务的迁移/接口**，再写码。
- **迁移归属单一 owner**：PG 迁移由 T1 基线 + 各任务追加，禁止两 agent 改同一迁移文件。
- **分支策略**：`main`(部署源) ← `develop` ← `feature/*`；agent 各自 feature 分支，PR 合 develop。
- **双 LLM 不交叉改同一文件**：脚手架/CRUD 用 DeepSeek，业务语义/支付/竞价用 GLM，审校阶段 GLM 只读评审。
- **两仓不互相 import**；契约变更先改 `架构契约.md` 再同步两仓 README。

---

## 4. 里程碑验收（对照进度日历 W1-W10）
- **W3 末（11-22）**：核心功能 70~80%，图鑑有真实种子数据（T9 灌入），端到端交易沙箱 + 竞拍可演示。
- **W5（12-06）**：详情页三源行情 K 线实时刷新（≥1 源实时）。
- **W7（12-20）**：三端构建产物齐 + 小程序送审 + 软著证书到位。
- **W10（01-10）**：全平台上线就绪；春节（02-17）前硬底线。

---

## 5. 给 CodeBuddy 的启动 prompt（可直接复制）

> 你在 GOAT 潮玩电商平台项目（双仓 goat-mall / goat-collector）中作为开发 agent 工作。
> 第一步：阅读 `goat-mall/docs/架构契约.md` 与 `goat-mall/docs/CodeBuddy任务分解.md`，确认你负责的模块（见任务 T1-T10）。
> 严格遵守：著作权人=火山文化科技、作者=Aloysius Luo；命名禁用 tttworks/vasture，产品前缀用 goat；WAF 源码不内联；软著只含自有代码。
> 按任务清单实现，先读契约再写码，迁移/接口冲突先问不要擅自改他人文件。完成后给出自检清单。

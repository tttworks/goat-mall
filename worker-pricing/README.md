# worker-pricing · 目录说明（无自研代码）

**本目录不存放实现代码。** 采集与行情能力在**独立仓库 `goat-collector`**（Python 3.12）。

## 为什么分两个仓

| 维度 | 站点（`goat-mall`） | 采集（`goat-collector`） |
|---|---|---|
| 职责 | 交易 / 内容 / 审核 / 展示 | 图鑑底库抓取、行情采集、清洗归一 |
| 凭据 | 支付、OAuth、对象存储 | MFC / eBay / mercapi 数据源密钥 |
| 部署 | Web 与队列节点，随业务扩缩 | 常驻 worker，地域受限（Mercari 需日本归属 IP） |
| 合规 | 用户数据、担保交易 | 第三方站点抓取频控、robots 与条款 |

把一个需要日本 IP、需要浏览器通道（Edge CDP）、还带第三方抓取条款风险的进程塞进 Web 仓，
会让「部署站点」和「部署爬虫」绑成同一件事——任何一侧升级都要同时回归，且密钥权限无法分离。
因此采集侧独立成仓、独立部署，两侧只通过**受限内部 API + 共享库**通信。

## 两侧的唯一接口（契约 §4）

方向只有一条：**站点 → 采集**（管理员触发）。采集结果上行不走 HTTP 回调，只写共享库/队列，
避免两个系统互相等待、一起挂。

| 站点侧调用 | 采集侧端点 | 说明 |
|---|---|---|
| `InternalCollectorClient::crawlFigure()` | `POST /internal/collector/crawl-figure` | 抓图鑑条目与规格 |
| `InternalCollectorClient::crawlPrice()` | `POST /internal/collector/crawl-price` | 抓站内 / eBay / 煤炉行情 |
| `InternalCollectorClient::job($id)` | `GET /internal/collector/job/{job_id}` | 查询执行状态 |

鉴权为共享 HMAC-SHA256：

```
signature = hex(hmac_sha256(secret, timestamp . "\n" . rawBody))
Headers: X-Goat-Timestamp / X-Goat-Signature
```

采集侧校验时间戳窗口（默认 300s）防重放。**站点侧不做重试**：抓取是长任务，
重试属于采集侧队列的职责，站点侧重试只会把 1 个任务放大成 3 个。

## 站点侧代码落点（在本仓内）

- `backend/app/Services/Collector/InternalCollectorClient.php` — 签名、超时、错误分类
- `backend/app/Http/Controllers/Api/Admin/CollectorController.php` — 仅 `role:admin` 可触发
- `backend/app/Exceptions/CollectorException.php` — 未配置 → 503，上游失败 → 502
- `backend/routes/api.php` — `POST /api/v1/admin/collector/crawl-figure`、`/crawl-price`、`GET /job/{job}`
- `admin-pc/src/views/CollectorView.vue` — 运营触发入口与 job 查询

## 站点侧配置（`.env`，凭据不入仓）

```
COLLECTOR_BASE_URL=      # 采集侧内网地址，留空则后台触发返回 503
COLLECTOR_HMAC_KEY=      # 与采集侧共享，仅存服务器 /root/goat_secrets.env
COLLECTOR_HMAC_TTL=300
COLLECTOR_TIMEOUT=10
```

## 本目录保留原因

- 历史文档（`README.md` 目录表、`docs/软著材料清单.md`）曾以 `worker-pricing/` 指代采集侧；
- 保留本说明，让「按目录找代码」的人一次就走到正确的仓，而不是以为功能缺失。

> 采集侧源码与运行方式见 `goat-collector/README.md`。

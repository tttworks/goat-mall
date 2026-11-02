# GOAT 项目红线（每轮强制生效）

> 著作权人：火山文化科技（天津市火山文化科技有限责任公司）· 作者：Aloysius Luo
> 完整版见 `docs/架构契约.md` §8~§10 与 `docs/项目约定.md` §8。

## 1. 权属声明

- 每个新增自有源文件（`.php` / `.js` / `.ts` / `.vue` / `.scss` / `.blade.php`）首部必须加版权块，模板在 `docs/copyright-header.txt`：

```php
/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */
```

- `composer.json` / `package.json` 这类不能写注释的文件，用 `authors` + `extra.copyright` 字段表达同样信息。
- 任何新增文件若漏版权头，等于把「框架默认代码」混进软著材料，属于返工级缺陷。

## 2. 命名与品牌隔离

- 产品标识统一 `GOAT` / `goat`（包名、DB 名、队列名、配置键、缓存前缀、环境变量前缀）。
- **禁止** `tttworks`、`vasture`（含大小写变体）出现在参数、变量、包名、类名、配置键、DB 标识符、队列名、目录名。
- 文件名一律 ASCII；类名必须与文件名一致（PSR-4 硬要求）。
- 命名体系（不要再造第二套）：
  - 请求类：`实体 + 动作 + Request`（`ListingStoreRequest`、`SellerApplyRequest`、`IdentitySubmitRequest`），统一放 `app/Http/Requests/` 根目录，不再按域建子目录。
  - 服务类：`实体 + Service`（`ListingService`、`AuctionService`）。
  - 枚举：单数名词（`ListingStatus`、`OrderStatus`）。
  - 控制器：`实体 + Controller`，后台放 `Api/Admin/`。

## 3. 配置入口

- 自有配置只有一个入口：`config/goat.php`（键分级：`goat.auth.*`、`goat.trade.*`、`goat.auction.*`、`goat.media.*`、`goat.collector.*`）。
- 新增可运营参数先落配置或 DB，禁止写死在业务代码；新增配置项必须同步 `.env.example`（占位值）。

## 4. 数据与资金

- 媒体只存对象 key，不存完整 URL；URL 由 `MediaUrl` 按 `MEDIA_BASE_URL` 拼接。
- 金额用字符串传递（`'10.10'`），禁止 PHP float 参与金额运算；比较/相加走 `Support/Money`。
- 状态流转一律走状态机（`Support/Concerns/StateTransitions`），禁止直接赋值 `$model->status = ...`。
- 下单/支付/退款必须带幂等键；缺失幂等键直接 422，不静默补随机值。

## 5. 提交与交付

- commit 信息中文，格式 `类型: 摘要`（feat/fix/chore/docs/refactor/test）；**禁止**出现「无法生成提交信息」「本次代码变更内容为空」这类工具故障文案。
- 凭据、密钥、密码不进仓库，`.env.example` 只放占位值。
- 软著材料只含 GOAT 自有代码：`vendor/`、`node_modules/`、`docs/`、`.codebuddy/` 一律不进材料。
- WAF 安全面板是作者独立项目，只以外部安全层集成，**绝不**把其源码纳入本仓库或软著材料。

## 6. 交付前自检（必跑）

```powershell
cd backend
# 1) 违禁品牌名：必须为 0
(Get-ChildItem -Recurse . -File -Include *.php,*.json,*.xml,*.vue,*.ts,*.js |
  Where-Object { $_.FullName -notmatch '\\(vendor|node_modules)\\' } |
  Select-String -Pattern 'tttworks|vasture' -CaseSensitive:$false | Measure-Object).Count
# 2) 缺失版权头：必须为空
Get-ChildItem -Recurse app,config,database,routes,public,bootstrap -File -Include *.php |
  ForEach-Object { if (-not (Select-String -Path $_.FullName -Pattern '火山文化科技' -Quiet)) { $_.FullName } }
# 3) 类名与文件名不一致：必须 0
Get-ChildItem -Recurse app -File -Filter *.php |
  ForEach-Object { $t=[IO.File]::ReadAllText($_.FullName); if ($t -match 'class\s+(\w+)' -and $Matches[1] -ne $_.BaseName) { $_.FullName } }
```

三项任一不通过，不要提交、不要交付。

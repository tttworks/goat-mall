---
name: goat-dev
description: 在 GOAT 潮玩交易平台（goat-mall 仓：Laravel 12 + PG17 + Redis + Meilisearch，C 端 uni-app，后台 Vue3+Element Plus）里写任何业务代码前使用。触发词：goat、T1-T10、图鑑、商品、竞价、订单、费率、上架审核、商家开店、实名核身、软著整改、Goat 接口、新增迁移。内含分层顺序、命名/权属红线、必跑自检脚本与已踩过的坑。
---

# GOAT 站点系统开发套路

## 0. 动手前

1. 读 `docs/架构契约.md`（唯一事实来源）→ `docs/项目约定.md`（铁律）→ `docs/踩坑记录.md`（禁止踩第二遍）。
2. 读 `.codebuddy/rules/goat-redlines.md`（权属/命名/配置红线，每轮已自动加载，冲突时以它为准）。
3. 确认改动落在哪一层，不要跨层混改。

## 1. 分层与落地顺序

```
database/migrations → app/Enums → app/Models → app/Services/<域> → app/Http/Requests → app/Http/Controllers → routes/api.php → tests/Feature
```

- 迁移按时间戳排序，`09xxxx` 用户域、`10xxxx` 交易域；**已上线迁移只增不改**。
- 枚举带 `label()` 与 `transitions()`，状态机在 `Support/Concerns/StateTransitions` 里断言。
- 服务层是唯一放业务规则的地方；控制器只做「校验 → 取登录态 → 调服务 → 返回 JSON」。
- 请求类命名 `实体+动作+Request`，全部放 `app/Http/Requests/` 根目录（无子目录）。
- 路由放 `routes/api.php`，私域路由挂 `auth:sanctum`，卖家挂 `role:seller` 或 `seller`，后台挂 `role:admin`。

## 2. 必须遵守的硬约束

- 金额：入参字符串校验 `regex:/^\d{1,8}(\.\d{1,2})?$/`，内部用 `Money` 转分，禁止 float。
- 并发：库存/竞价/钱包一律 `lockForUpdate()` + `DB::transaction()`；幂等键缺失直接 422。
- 媒体：DB 存 key，出参用 `MediaUrl::url($key)`；外站 URL 直接拒绝。
- 错误：域内抛 `TradeException` / `CatalogException` / `AuthException`，由 `bootstrap/app.php` 统一渲染 JSON，进度码 `code` 字段给前端判定。
- 后台写操作要留审计：谁、何时、把什么从什么改成什么。

## 3. 交付前自检

```powershell
cd backend
# 违禁品牌名 0 命中 / 版权头齐全 / 类名=文件名（详见 rules 第 6 节）
# 语法：本机无 PHP 时，用 IDE 诊断 + 静态校验替代，并在提交信息里说明未跑测试
php artisan test --filter=<相关测试>
```

- 新增接口必须有 Feature 测试：成功路径 + 越权（别人家的资源）+ 非法状态流转 三条。
- 提交信息中文 `类型: 摘要`，正文列 3~5 条要点；**禁止**任何工具生成的占位文案。

## 4. 已知坑（踩过就别再踩）

- `git mv` 跨目录在 Windows PowerShell 里可能静默失败，脚本改名后必须逐个核对目标文件存在且内容非空。
- PowerShell 5.1 默认按 ANSI 读文件，看 UTF-8 中文会显示乱码 —— 判断编码损坏前先用 `[IO.File]::ReadAllText(..., UTF8)` 复核。
- `[regex]::Replace($s,$p,$r)` 三参重载会误判为 MatchEvaluator，用 `-replace` 运算符。
- 本机 Chrome 无头不可用，浏览器自动化走 Edge + CDP。
- 本机访问 GitHub 必须走 `http://127.0.0.1:2080` 代理。
- Meilisearch 的 `SCOUT_QUEUE=true`，索引抖动不阻塞保存；本地无 Meili 时 `SCOUT_DRIVER=null` 自动降级 SQL。

## 5. 软著视角

- 只写自有逻辑，别把框架默认文件（`config/*.php` 脚手架、`artisan`）当成"我们的代码"充数。
- 每个文件都要能回答：这段代码体现的是什么业务设计（状态机、幂等、防狙击、费率、图鑑检索）。

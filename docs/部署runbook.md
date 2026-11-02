# GOAT 部署 Runbook（flat-fire · 216.23.112.40）

> 本文件供 **CodeBuddy** 把 `goat-mall` 部署到服务器 / 新域名时照做。
> 分工：**CodeBuddy = 代码生成 + 部署**；**WorkBuddy = 文档契约守护 + 本地镜像数据库整理与导入 + 服务器基建维护**。详见 `项目约定.md` §9。
>
> ⚠️ 本文件**不含任何 secret**。所有密钥在服务器 `/root/goat_secrets.env`，部署时现场读取，绝不写入代码或提交。

## 0. 目标机与访问

- 主机名 `flat-fire`，IP `216.23.112.40`，Debian 13 (Trixie)，日本东京 BitsFlow。
- SSH：`root@216.23.112.40`，复用项目 `goat_ed25519` 私钥（本地绝对路径 `D:\#工作文档\02.合作伙伴\GreatestOfAllToyz\.workbuddy\goat_ed25519`；WorkBuddy 与 CodeBuddy 同机，直接 `-i` 该路径即可）。
- 宝塔面板已装（可视化管理 Nginx 站点 / SSL / cron / 文件 / 监控）。
- 代码源：GitHub `goat-mall`（部署分支 / 标签）。

## 1. 服务器现状（WorkBuddy 已建，结构勿擅自改动）

- PHP 8.3.36 @ `/www/server/php/83/`，宝塔服务名 `php-fpm-83`；扩展 `pgsql` / `pdo_pgsql` / `redis` / `swoole` 已启用。
- 数据层（均 systemd `ACTIVE`）：`postgresql`(17.11) + `PgBouncer`、`redis-server`(8)、`meilisearch`(1.56.1)。
- Octane 运行方式：**systemd（宝塔不接管）**。T1 完成后会产出 unit（约定名 `goat-octane.service`），监听 `:8000`。
- 凭据位置：`/root/goat_secrets.env`（PG / Redis / Meili / UFile），**不进文档、不进代码**。

## 2. 部署前检查（CodeBuddy 自审）

- [ ] 代码已合到部署分支（约定 `main` = 部署源，或 `release/*` 标签，如 `deploy-YYYYMMDD-HHMM`）。
- [ ] 迁移文件只新增、未改历史迁移（`项目约定.md` §2）。
- [ ] 新增配置项已更新 `.env.example`（占位值），无真实密钥入库。
- [ ] 本地 / CI 已 `composer install --no-dev --optimize-autoloader` 且 `php artisan test` 通过。
- [ ] **已与 WorkBuddy 互知窗口**：避免与镜像数据导入同时跑（见 §5）。

## 3. 部署步骤

### 3.1 传输代码到服务器

- 方式（二选一，推荐 A）：
  - **A. 服务器内 git pull**：部署目录执行 `git pull origin <部署分支/tag>`（私有仓需配 deploy key 或复用 `goat_ed25519` 拉取）。
  - **B. 本地构建后 rsync**：本地 `composer install --no-dev`，前端 `admin-pc` / `app-mobile` 构建产出到 `public` 或各自 `dist`，再 `rsync -az --exclude=.git ./ root@216.23.112.40:/www/wwwroot/goat/`。
- 部署目录（宝塔新建站点时指定，建议 `/www/wwwroot/goat/`；首次需建，见 3.3）。

### 3.2 服务器内装配

```bash
cd /www/wwwroot/goat
cp .env.example .env            # 不提交
# 从 /root/goat_secrets.env 取值填 PG / REDIS / MEILI / UFILE / MEDIA_BASE_URL
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --force     # 仅首次 / 明确需要时
php artisan db:seed --force     # 仅首次 / 按需
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan storage:link
systemctl restart goat-octane  # T1 产出的 unit
```

- 用 PHP：`/www/server/php/83/bin/php`（或确认 CLI 默认即 8.3）。
- `migrate --force` 常规小版本可省略，仅在结构变更时执行。

### 3.3 宝塔建站点 + 域名 + SSL（新域名首次）

1. 宝塔 → 网站 → 添加站点：域名填**新域名**（临时站用 `goat.websitedemos.cn` 已解析到本机）；根目录 `/www/wwwroot/goat/public`；PHP 版本选 **8.3**。
2. SSL → Let's Encrypt 申请并强制 HTTPS。
3. 反向代理：站点 → 反向代理 → 目标 `http://127.0.0.1:8000`（Octane）；或 Nginx 配 `location / { proxy_pass http://127.0.0.1:8000; ... }`。
4. 伪静态选 Laravel（或手写 `try_files $uri $uri/ /index.php?$query_string;`）。

### 3.4 健康检查

- `curl -sI https://<域名>/up` 或业务 `/health` → 200。
- `systemctl status goat-octane` 无异常；`journalctl -u goat-octane -n 50`。
- 验证 PG / Redis / Meili 连通（Laravel 启动无连接报错）；`php artisan route:list` 有路由。

## 4. 回滚

- 保留最近 5 个 release 目录，软链 `current → release-<ts>` 切换（部署脚本维护）。
- 或 `git checkout <上一 tag>` + 重新 3.2 / 3.3。
- 迁移回滚：`php artisan migrate:rollback --step=N`（仅在确认安全时）。

## 5. 协作纪律（与 WorkBuddy 同步）

- 落地前**互知窗口**：CodeBuddy 跑迁移 / 部署时，WorkBuddy 不同步做镜像数据导入（两者都写服务器，错峰避免锁 / 状态冲突）。
- 服务器结构改动（PHP 版本、数据层、Octane unit）先读 `项目约定.md` §4 / §9，不确定问 WorkBuddy。
- 部署上下文的权威源是 WorkBuddy；本 runbook 与其冲突以 `项目约定.md` 为准。

## 6. 临时站部署记录（2026-11-03 首次发布，可直接照此复现）

- 站点：`https://goat.websitedemos.cn`（Let's Encrypt，2027-01-31 到期）
- 代码根：`/www/wwwroot/goat.websitedemos.cn`；Laravel 在 `backend/`，站点根目录指向 `backend/public`
- nginx：`/www/server/panel/vhost/nginx/goat.websitedemos.cn.conf`（改动前已备份到 `/root/goat.vhost.bak.*`）
  - `/api`、`/healthz`、`/up` → `http://127.0.0.1:8000`（Octane）
  - `/admin/` → `try_files $uri $uri/ /admin/index.html`（Vite history 回落，`VITE_APP_BASE=/admin/`）
  - 80 → 443 强制跳转
- systemd：`goat-octane.service`（`octane:start --server=swoole --host=127.0.0.1 --port=8000 --workers=4`）、`goat-queue.service`（`queue:work redis --tries=3 --max-time=3600`）
- 宝塔 PHP CLI：`/www/server/php/83/bin/php`；composer 必须 `php -d disable_functions= /usr/local/bin/composer2`（宝塔默认禁用 `putenv` / `proc_open`，composer 起不来）
- `.env` 由部署脚本现场生成（`.env.example` 不被 git 跟踪）：PG 直连 `127.0.0.1:5432`（绕开 PgBouncer 端口），`APP_DEBUG=false`，管理员密码从 `/root/goat_secrets.env` 取
- 发布后自检（缺一不可）：
  1. `systemctl is-active goat-octane goat-queue` 均 active
  2. `POST /api/v1/auth/login` 拿 token → `auth/me`、`notifications`、`admin/figures`、`admin/reports`、`admin/posts`、`posts` 全 200，不带 token 访问 `admin/figures` 401
  3. feed 带 token 时 `liked` 为布尔值（造一条临时帖 + 点赞验证后清理）
  4. `/healthz`、`/up`、`/admin/` 均 200；`routes/web.php` 无临时调试路由
  5. `git status` 仅含本次发布应有改动；测试时产生的 token 全部删除
- 现状提醒：该目录是 tar 覆盖上传，**不是 git 仓库**，`git pull` 会失败；切 git 部署前先决策。`systemctl is-active nginx` 报 inactive 是正常的（宝塔直接拉起 master 进程）。

## 7. 排障：部署后「登录成功但所有带 token 请求 401」

- 先看 `backend/config/octane.php` 的 `listeners` 是否完整（`RequestReceived` 必须展开两个 `prepareApplicationFor...`；`FlushTemporaryContainerInstances` 必须在 `OperationTerminated`）
- 修复后必须 `php artisan config:clear && config:cache && route:cache` + `php -d disable_functions= artisan octane:reload`，只 reload 旧配置无效
- 想看真实异常再临时开 `APP_DEBUG=true`，**调完立刻改回 false 并重建缓存**（本次发布踩过）
- 详细成因见 `踩坑记录.md` §19 / §20

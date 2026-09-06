# Phorge (phorge-fork) 容器化部署说明

本 fork 提供一套精简的 Docker / Docker Compose 封装，以**单体**方式运行 Phorge：
一个基于 `php:8.3-apache`（mod_php）的应用容器，加一个 MySQL 8 容器，以及一个
只跑一次的授权初始化任务。所有可调项都有内置默认值，开箱即可跑。

## 前置要求

- Docker 与 Docker Compose（Compose V2，`docker compose ...`；Traefik 叠加编排要求
  Docker Compose 2.24.4 或更高版本，以支持 `!reset` 标签）
- 无需预装 PHP / MySQL：镜像自建，数据库随 Compose 一起启动

## 快速启动

```bash
# 1) 准备环境变量（可选，用于改密码 / 端口 / 域名；不改也能跑）
cp .env.example .env

# 2) 构建并启动全部服务
docker compose up -d --build

# 3) 浏览器访问（Host 必须含点号，用 127.0.0.1 而不是 localhost）
#    默认地址：http://127.0.0.1:8088/
```

首次启动会自动完成：

- 构建应用镜像（拉取 arcanist、编译 PHP 扩展）。
- 启动 MySQL，等待其健康后由一次性任务 `db-init` 为普通库用户补齐
  `phabricator_%` 整组库的授权（见 `docker/db-grant.sql`）。
- 应用容器 `entrypoint.sh` 依次：**守卫式**生成 `conf/local/local.json`（已存在则保留）
  → 幂等下发 Gorge 高亮配置（仅在叠加 `docker-compose.gorge.yml` 时有值可写）
  → 等待数据库就绪 → 执行 `bin/storage upgrade --force` 初始化 schema
  → 以 `www-data` 启动守护进程 `phd` → 启动 Apache。

访问根路径 `/` 会 302 跳到 `/auth/register/` 的初始管理员注册引导页，按向导创建第一个
账号即可。应用容器带 healthcheck（探测免鉴权的 `/status/`），有 60s `start_period`，
所以启动后需要等一会儿 `docker compose ps` 里才会变成 `healthy`。

## 环境变量

所有变量在 `docker-compose.yml` 里都有同名内置默认值，因此**不写 `.env` 也能跑**；
生产部署至少要改掉两个密码和 `PHORGE_BASE_URI`。

| 变量 | 默认值 | 说明 |
|------|--------|------|
| `MYSQL_ROOT_PASSWORD` | `phorge_root` | MySQL root 密码。只有 `db-init` 授权和 MySQL 自身健康检查用到，Phorge 应用**不用** root 连库。 |
| `MYSQL_USER` | `phorge` | Phorge 连库的普通账号。首次初始化数据卷时由 MySQL 镜像创建，随后由 `db-init` 授予 `phabricator_%` 整组库权限。 |
| `MYSQL_PASSWORD` | `phorge` | 上述普通账号的密码，会同时下发给 `mysql` 与 `phorge` 两个服务。 |
| `MYSQL_DATABASE` | `phorge` | MySQL 首次初始化顺带创建的默认库。Phorge 实际使用 `phabricator_*` 命名空间下的一组库，这里只是给普通账号一个落脚点，保持默认即可。 |
| `PHORGE_HTTP_PORT` | `8088` | 宿主机映射端口，浏览器访问 `http://127.0.0.1:<该端口>/`。 |
| `PHORGE_BASE_URI` | `http://127.0.0.1:${PHORGE_HTTP_PORT}/`（默认注释，由 compose 自动拼出） | 站点绝对地址，Phorge 用它生成链接并校验请求的 Host 头。**域名必须含点号**，裸 `localhost` 会被拒绝。改了端口一定要让它联动，否则会 redirect 到错误地址。 |
| `PHORGE_TIMEZONE` | `UTC` | 站点默认时区（`phabricator.timezone`），取 PHP 时区标识符，如 `Asia/Shanghai`。 |
| `PHORGE_WAIT_DB` | `1` | 是否在启动 Web 前等待数据库就绪。严格取值 `1` 开启，其它任何值视为关闭。关掉首启动 `storage upgrade` 大概率失败。 |
| `PHORGE_AUTO_UPGRADE` | `1` | 是否每次启动自动执行 `bin/storage upgrade --force`。开箱可跑靠它；生产建议关掉，升级前先备份再手工迁移。 |
| `PHORGE_START_PHD` | `1` | 是否随容器启动守护进程 `phd`（负责仓库拉取、任务队列、邮件投递等），容器内以 `www-data` 运行。 |

> 注意：`MYSQL_*` 与 `PHORGE_BASE_URI` / `PHORGE_TIMEZONE` **只在首次生成
> `conf/local/local.json` 时写入**。该文件由 `phorge-conf` 卷持久化，之后改 `.env`
> 不会覆盖它。需要按新环境变量重新生成，先删掉再重启：
> `docker compose exec phorge rm conf/local/local.json && docker compose restart phorge`，
> 或直接在 Web 界面 Config 里改。

## 镜像结构

- **基础镜像**：`php:8.3-apache`（mod_php）。构建时安装并编译 Phorge 需要的 PHP 扩展
  （`mysqli`、`gd`、`curl`、`mbstring`、`pcntl`、`posix`、`opcache`、`iconv`、`zip`），
  随后回收 `*-dev` 构建依赖以瘦身。
- **PHP 运行参数**：`docker/php/*.ini` 覆盖到 `/usr/local/etc/php/conf.d/phorge-*.ini`
  （opcache / 内存 / 上传限制），满足 Phorge 的 setup 检查。要单项覆盖，挂载同名文件到该目录即可。
- **arcanist 依赖**：构建阶段浅克隆到 `/opt/phorge/arcanist`（删掉 `.git`），Phorge 启动时会加载其库。
- **源码烤进镜像**：`COPY . /opt/phorge/phorge`，**不是**挂载。所以改了本仓库的 PHP 代码后，
  必须 `docker compose up -d --build` 重建镜像才能生效，光 `restart` 跑的还是旧镜像里的旧代码
  （见「常见故障排查」里对应那条）。想在开发时免重建，可以自己用
  `docker-compose.override.yml` 把源码目录挂到该路径上。
- **Web 根目录**：`/opt/phorge/phorge/webroot`，由 `docker/phorge-apache.conf` 把所有请求
  rewrite 到 `index.php`，Apache 监听 80，映射到宿主 `PHORGE_HTTP_PORT`。
- **持久化命名卷**：
  - `phorge-conf` → `/opt/phorge/phorge/conf/local`：守卫式生成的 `local.json` 与 Web 界面改的配置跨重建存活。
  - `phorge-repo` → `/var/repo`：仓库工作副本（`repository.default-local-path` 默认值）。
  - `phorge-db` → `/var/lib/mysql`：数据库数据。
- **运行时属主**：`/var/repo`、`/var/tmp/phd`、`conf/local` 在镜像内预建并 `chown www-data`，
  以便 Apache 与 `phd`（均以 `www-data` 运行）可写。

## 常见故障排查

- **`Bad "Host" Header` / Host 必须含点号**：Phorge 要求访问的 Host 含点号（`.`），不能用
  `localhost`。请用 **http://127.0.0.1:8088/** 或自设含点域名（如 `phorge.local` 写入
  `/etc/hosts`）。若已生成过 `local.json`，还要把里面的 `phabricator.base-uri` 改成对应地址。

- **改了端口但站点乱跳转**：`PHORGE_HTTP_PORT` 与 `PHORGE_BASE_URI` 必须联动。默认
  `PHORGE_BASE_URI` 会自动拼成 `http://127.0.0.1:${PHORGE_HTTP_PORT}/`，只改端口不会漏；
  但如果你在 `.env` 里手动写死了 `PHORGE_BASE_URI`，改端口时记得同步改它，否则 Phorge
  会按旧地址 redirect。注意 `local.json` 只在首次生成，改完 `.env` 需删除 `local.json`
  重新生成或在 Web Config 里改。

- **改了 PHP 代码但没生效 / 报 class not found、Unknown Configuration Option**：镜像用
  `COPY . /opt/phorge/phorge` 把源码**烤进去了**，容器里跑的是构建那一刻的快照。改完代码只
  `docker compose restart phorge` 无效，新增的类在镜像里根本不存在，表现就是 `Class not found`；
  新增的配置项同理，Config 页面会报 "Unknown Configuration Option"，`bin/config set` 会说
  "Configuration key is unknown"。修法是重建：
  ```bash
  docker compose up -d --build phorge
  # 用了叠加文件时把 -f 都带上，否则叠加的配置会丢：
  # docker compose -f docker-compose.yml -f docker-compose.gorge.yml up -d --build phorge
  ```
  另外，新增 PHP 类还需要先在宿主上 `arc liberate src/` 重新生成
  `src/__phutil_library_map__.php` —— 类映射没更新，重建了镜像也一样 `Class not found`。

  `docker/entrypoint.sh` 也一样被烤进镜像，但它出问题时**一声不吭**：镜像里若是旧版
  entrypoint，新增的下发逻辑（比如 `notification.servers`、`cluster.mailers`）整段不存在，
  于是既没有 `DONE Wrote configuration key ...`，也没有任何警告，唯一的线索是启动日志里
  少了 `[entrypoint] 下发 Gorge ... 配置` 那几行。已有镜像时 `up -d` **不会**自动重建，
  所以改过 entrypoint 之后必须带 `--build`——本文、`.env.example` 与两个叠加文件里的启动
  示例一律带上它，就是为了不留这个坑。启动后对着日志数一遍那几行，比事后查
  「配置为什么没写进去」快得多。

- **旧数据卷迁移断点（升级时最容易卡住的一条）**：`db-init` 用 `MYSQL_ROOT_PASSWORD`
  以 root 连接并对 `MYSQL_USER`（默认 `phorge`）执行 GRANT。**GRANT 要求该账号已存在**。
  全新数据卷由 MySQL 镜像的 `MYSQL_USER` 自动建出，没问题；但若你**沿用旧版的数据卷**
  （例如旧卷是 root-only、或 root 密码本就是 `phorge` 而非现在的 `phorge_root`），会出现两种卡点：
  1. root 密码对不上 → `db-init` 报 `ERROR 1045 Access denied for user 'root'@...`；
  2. `phorge` 账号不存在 → GRANT 因目标账号缺失而失败，`db-init` 退出非 0，`phorge` 服务
     因 `service_completed_successfully` 依赖被连带阻塞、起不来。

  处理办法二选一：
  - **保留旧数据**：进 MySQL 手工补账号再重启，例如
    `docker compose exec mysql sh -c 'mysql -uroot -p<旧root密码> -e "CREATE USER IF NOT EXISTS \"phorge\"@\"%\" IDENTIFIED BY \"phorge\";"'`，
    并确保 `.env` 里的 `MYSQL_ROOT_PASSWORD` 与旧卷里的 root 密码一致；
  - **不要旧数据**（测试环境重建）：`docker compose down -v` 删除命名卷后再
    `docker compose up -d --build`，让 MySQL 用当前 `.env` 干净初始化。
    ⚠️ `-v` 会一并删除 `phorge-conf` / `phorge-repo` / `phorge-db`，**生产数据请先备份**。

- **healthcheck 迟迟不 `healthy`**：应用容器有 60s `start_period`，之后每 15s 探测一次
  `/status/`（重试 5 次）。若一直不健康，按顺序排查：
  ```bash
  # 1) 看容器实际状态与失败计数
  docker compose ps
  docker inspect phorge-web --format '{{.State.Health.Status}} {{.State.Health.FailingStreak}}'
  # 2) 看 entrypoint / Apache 日志（storage upgrade、phd、Apache 是否报错）
  docker compose logs phorge
  # 3) 直接在容器内手动探一次 /status/
  docker compose exec phorge sh -c 'php -r "echo file_get_contents(\"http://127.0.0.1/status/\");"'
  # 4) 看健康检查最近一次的输出
  docker inspect phorge-web --format '{{json .State.Health}}'
  ```
  常见原因：数据库没起来 / GRANT 没成功（见上一条）→ `storage upgrade` 失败 → 页面 500；
  或自定义了 `PHORGE_BASE_URI` 域名后 healthcheck 的 Host 头匹配不到站点（本 fork 的
  healthcheck 已按 `PHORGE_BASE_URI` 改写 Host，通常不需额外处理）。

- **`storage upgrade` 手工重跑**：如需重来，进容器执行
  `docker compose exec phorge /opt/phorge/phorge/bin/storage upgrade --force`。

- **确认守护进程在跑**：
  `docker compose exec phorge su -s /bin/sh www-data -c "/opt/phorge/phorge/bin/phd status"`。

## 通过 Traefik Forward Auth 运行（可选）

本 fork 内置了一个 **"Traefik Auth"** 认证 Provider（`PhabricatorTraefikAuthProvider`）。
它的思路是：把认证交给前置的 [Traefik](https://traefik.io/) + ForwardAuth 中间件，
认证通过后由 Traefik 向后端注入身份头，Phorge 读取这些头完成登录/注册。适合把 Phorge
接入统一的 SSO（如 authelia / oauth2-proxy）网关，Phorge 本身不处理密码。

### 认证头与用户名推导

Traefik 在认证成功后应向后端注入以下 HTTP 头（Provider 依赖它们）：

| 头 | 含义 | 是否必需 |
|------|------|----------|
| `X-Auth-User` | 外部账号唯一标识（用作 external account id，也是用户名兜底值） | **必需**（缺失则拒绝并提示需经 Traefik 访问） |
| `X-Auth-Email` | 邮箱，用于推导用户名并作为账号邮箱 | 可选 |
| `X-Auth-Name` | 展示用真实姓名 | 可选 |

**用户名推导规则**（`deriveUsernameFromEmail`）：

1. 若有 `X-Auth-Email`，取其 `@` 之前的本地部分，剔除 `[a-zA-Z0-9._-]` 之外的字符、
   去掉结尾的点号；若结果非空且通过 `PhabricatorUser::validateUsername()` 校验，则用它做用户名。
2. 否则（无邮箱 / 本地部分非法）回退使用 `X-Auth-User` 作为用户名。

外部账号的稳定标识始终是 `X-Auth-User`，因此即使邮箱变化也不会重复建号。

### 在后台启用 "Traefik Auth" Provider

Provider 会被 `PhutilClassMapQuery` 自动发现（类映射已登记在
`src/__phutil_library_map__.php`），无需改代码。但和其它 Provider 一样，需要在
**Web 后台新建并启用一个实例**后才生效：

1. 以管理员登录，进入 **Auth → Auth Providers**（`/auth/`）。
2. 点击 **Add Authentication Provider**，选择 **Traefik Auth**，创建实例。
3. 按需勾选：
   - **Allow Login**：允许已有账号用该 Provider 登录。
   - **Allow Registration**：允许首次经该 Provider 的用户自动注册新账号。
   - **Allow Linking**：允许已有账号绑定该外部身份。
4. 保存后，登录页会出现 "Traefik Auth" 登录按钮（`GET` 方式跳到登录流程）。

> 首次搭建时若还没有管理员账号，请先按常规流程（`/auth/register/`）创建初始管理员，
> 再回来启用本 Provider；否则可能出现"无人可管理"的情况。

### 用叠加文件启动

Traefik 相关编排放在独立的叠加文件 `docker-compose.traefik.yml`，**不改动**
`docker-compose.yml`，因此默认的一键启动不受影响。启用 Traefik：

```bash
# 可选：在 .env 里设置 TRAEFIK_DOMAIN / TRAEFIK_FORWARD_AUTH_ADDRESS 等
docker compose -f docker-compose.yml -f docker-compose.traefik.yml up -d --build
```

叠加文件做了三件事：

- 新增一个 `traefik` 服务（`web:80` / `websecure:443` 入口、Docker provider、
  可选 dashboard），并定义 `phorge-forwardauth` ForwardAuth 中间件。
- 给 `phorge` 服务补充路由标签（`Host(${TRAEFIK_DOMAIN})` → websecure），按顺序绑定
  身份头清洗与 ForwardAuth 中间件，并用 `!reset` 移除应用的宿主机端口（对外入口只剩
  Traefik）。
- 挂载 `./support/preamble.proxy-https.php` 为容器内 `/opt/phorge/phorge/support/preamble.php`。

相关可选变量（都有默认值，见 `.env.example`）：

| 变量 | 默认值 | 说明 |
|------|--------|------|
| `TRAEFIK_DOMAIN` | `phorge.local` | 对外域名，Traefik 路由匹配用；**必须含点号**。 |
| `TRAEFIK_HTTP_PORT` / `TRAEFIK_HTTPS_PORT` | `80` / `443` | Traefik 入口的宿主端口。 |
| `TRAEFIK_FORWARD_AUTH_ADDRESS` | `http://auth-backend:9091/api/verify`（**占位**） | ForwardAuth 回调的真实认证后端地址，需你自行提供。 |
| `TRAEFIK_AUTH_RESPONSE_HEADERS` | `X-Auth-User,X-Auth-Email,X-Auth-Name` | 认证通过后从响应复制到后端请求的头名，需与后端实际返回对齐。 |
| `TRAEFIK_DASHBOARD` | `false` | 是否开启 Traefik Dashboard（仅排障）。 |

> **认证后端需自备**：`TRAEFIK_FORWARD_AUTH_ADDRESS` 只是占位，本 fork 不含具体的
> 认证服务（authelia / oauth2-proxy 等）。你需要提供一个 ForwardAuth 后端，并保证它在
> 认证成功时返回 `X-Auth-User`（及可选的 `X-Auth-Email` / `X-Auth-Name`）响应头。

### HTTPS 识别（preamble）

Traefik 做 TLS 终止后，到后端是明文 HTTP，但会带上 `X-Forwarded-Proto: https`。
挂载的 `support/preamble.php`（即 `support/preamble.proxy-https.php`）会在该头为 `https`
时设置 `$_SERVER['HTTPS']='on'` 与 `SERVER_PORT=443`，消除 Phorge 的 HTTP/HTTPS 不一致告警。
配合叠加文件时，建议把 `PHORGE_BASE_URI` 也设为 `https://${TRAEFIK_DOMAIN}/`。

### 安全提醒（务必阅读）

Provider **无条件信任** `X-Auth-User/Email/Name` 头。因此：

- **后端必须只能经由 Traefik 到达**：不要把 `PHORGE_HTTP_PORT` 暴露到公网。叠加文件用
  `ports: !reset []` 移除基础编排的宿主机端口，对外入口只剩 Traefik。若需要临时直连，
  请用单独的 override 文件显式添加回环映射，并在排障后移除。
- **必须先清洗客户端身份头，再执行认证**：路由先经过
  `phorge-strip-auth-headers`，用空的 `customRequestHeaders` 删除请求方带来的
  `X-Auth-*`；随后 `phorge-forwardauth` 完成认证，并通过 `authResponseHeaders` 把认证
  后端返回的可信值写入发往 Phorge 的请求。不要在 Apache 层无条件 `RequestHeader unset`，
  因为请求到达 Apache 时已经经过 ForwardAuth，那会把可信身份头一并删除。
- 若 Phorge 仍可被直接访问，攻击者可伪造这些头冒充任意用户——请务必在网络层隔离。

## 用 Gorge 做语法高亮（可选）

Phorge 自带的高亮器只覆盖几种语言（PHP / Python / Java / JSON），本镜像也**没装**
Pygments，所以其余文件在 Paste、Differential、Diffusion 里都是无色的。叠加编排文件
`docker-compose.gorge.yml` 会起一个 `gorge-render` 服务（Go + Chroma），Phorge 把高亮
请求发给它，覆盖面与 Pygments 相当，但不用在镜像里塞一套 Python 运行时。

镜像 `ghcr.io/soulteary/gorge-render` 由 Gorge 仓库的 release 工作流在打 `v*` tag 时推送，
容器内固定监听 `8140`，路由 `/api/highlight/*`。**拉不到这个镜像是正常情况**，本地构建一条
命令即可，见下面「本地构建 gorge-render 镜像」。与 Traefik 叠加文件一样，本文件**不改动**
`docker-compose.yml`，因此默认的一键启动不受影响。

### 用叠加文件启动

**起服务**和**切引擎**刻意是两步：可以先确认 `gorge-render` 健康再接上去，接了之后不
满意也能一条命令切回来。

```bash
# 1) 起 gorge-render（可选：先在 .env 里改 GORGE_IMAGE_TAG / GORGE_RENDER_TOKEN）
#    拉镜像失败（403）见下面「本地构建 gorge-render 镜像」
#    --build 不能省：本地已有旧 phorge 镜像时 up -d 会直接复用它，容器里跑的就是旧版
#    entrypoint，Gorge 的配置整段不下发且不报任何错（见「常见故障排查」）
docker compose -f docker-compose.yml -f docker-compose.gorge.yml up -d --build

# 2) 把高亮引擎切到 Gorge
docker compose exec phorge /opt/phorge/phorge/bin/config set \
  syntax-highlighter.engine PhabricatorGorgeSyntaxHighlighterEngine

# 3) 清掉已缓存的渲染结果（不能省，原因见下）
docker compose exec phorge /opt/phorge/phorge/bin/cache purge --all
```

> **第 3 步不能省**：Phorge 缓存的是**高亮之后的 HTML**，不是源码。Paste 的正文与摘要
> 存在 `cache_general` 表，Differential 的 changeset 存在自己的缓存表，两者都不会因为
> 换了引擎而失效。只切引擎不清缓存，已经看过的 Paste 和 diff 会继续吐旧 HTML，很容易
> 误判成「配置没生效」，转头去反复折腾 URI 和 token。只想清相关的两项可以用
> `bin/cache purge --caches general,changeset`。切换之后**新建**的 Paste / diff 不受影响，
> 它们本来就会走新引擎。

叠加文件做了两件事：

- 新增 `gorge-render` 服务，**不声明 `ports`**：它只被 `phorge` 通过 Compose 默认网络的
  服务名 `gorge-render` 调用，不需要宿主入口。要在宿主上直连排障，用
  `docker-compose.override.yml` 单独加一条回环映射（如 `127.0.0.1:8140:8140`）。
- 给 `phorge` 服务补上 `depends_on: gorge-render: service_healthy`，并注入
  `GORGE_RENDER_URI` / `GORGE_RENDER_TOKEN` 两个环境变量。等健康再启动是为了避免首屏就
  打到没就绪的高亮服务——那次失败的渲染结果会被缓存下来，反过来又要清一次缓存。
  正因为有这条依赖，`entrypoint.sh` 里**没有**再自己轮询 `/healthz`。

`entrypoint.sh` 会把这两个环境变量用 `bin/config set` 写进 `conf/local/local.json` 的
`gorge.render.uri` / `gorge.render.token`。与前面 `MYSQL_*` / `PHORGE_BASE_URI` 那些
**不同**，这两项刻意放在守卫式生成块之外：它们描述的是部署拓扑，该跟着编排走而不是跟着
`phorge-conf` 卷走，所以改完 `.env` 直接 `docker compose ... up -d` 就生效，不必删
`local.json`。环境变量留空时整项跳过，不会写空值也不会删掉你在 Web 界面配好的值。

相关可选变量（都有默认值，见 `.env.example`）：

| 变量 | 默认值 | 说明 |
|------|--------|------|
| `GORGE_RENDER_URI` | `http://gorge-render:8140` | Phorge 访问高亮服务的基础地址。**结尾不要带斜杠**，双斜杠会让服务端路由不匹配，返回 404 `ERR_NOT_FOUND`。 |
| `GORGE_RENDER_TOKEN` | 空 | 服务间共享密钥，请求头 `X-Service-Token`。同一个值同时下发给两个服务，两端必须一致；留空表示 `gorge-render` 不鉴权（默认它不对宿主暴露端口，可接受）。 |
| `GORGE_IMAGE_TAG` | `latest` | `ghcr.io/soulteary/gorge-render` 的镜像标签。生产建议钉到具体版本；本地构建时 `docker build -t` 的标签要与它一致。 |
| `GORGE_RENDER_MAX_BYTES` | `1048576` | 单次高亮的源码大小上限（字节）。超限返回 413，该文件退化为无高亮。 |
| `GORGE_RENDER_TIMEOUT_SEC` | `15` | 单次高亮的服务端超时（秒）。 |

### 本地构建 gorge-render 镜像

两种情况都需要自己构建，用的是同一条命令：

- **`ghcr.io/soulteary/gorge-render` 拉不下来。** 发布依赖 Gorge 仓库打 `v*` tag，尚未发布
  时这个镜像就是不存在的。注意 ghcr 对「不存在」和「无权访问」返回的是同一个
  `403 Forbidden`，所以别按报错去折腾 `docker login`——先本地构建。
- **你 fork 了 Gorge、改了 Go 代码**，想让 Phorge 用自己的构建而不是上游镜像。

```bash
# 构建上下文是 gorge 仓库的 go/ 子目录，不是仓库根目录：Dockerfile 在 go/ 下，
# 一份 Dockerfile 覆盖 go/cmd 下的所有二进制，由 SERVICE 构建参数挑一个。
docker build -t ghcr.io/soulteary/gorge-render:latest \
  --build-arg SERVICE=gorge-render \
  /path/to/gorge/go

# 确认镜像已在本地
docker images ghcr.io/soulteary/gorge-render
```

把 `/path/to/gorge/go` 换成你机器上 Gorge 仓库的 `go/` 目录。**Gorge 是独立仓库，不在本仓库
里**，所以这个路径取决于你 clone 到了哪儿；如果两个仓库是并列的兄弟目录，在 `phorge-fork/`
下就是 `../gorge/go`。构建耗时约 20 秒（Go 静态编译 + alpine 运行层）。

关键是 `-t` 打出的名字要和编排里 `image:` 的完全一致，这样**不用改任何编排文件**：默认是
`ghcr.io/soulteary/gorge-render:latest`；若你在 `.env` 里设了 `GORGE_IMAGE_TAG`，`-t` 的标签
要跟着改成同一个值。构建完镜像就在本地，`docker compose ... up -d` 直接拿来用、不会再去拉；
但 `docker compose pull` 仍然会去 ghcr 找，那一步照样会 403，跳过它即可。

> 同理，`phorge` 应用镜像里的 PHP 源码也是**烤进镜像**的（见「镜像结构」）。如果你改的是
> 本仓库的 PHP 代码，别忘了 `docker compose ... up -d --build`。

### 回滚与排障

```bash
# 切回 Phorge 内置引擎（同样要清缓存，否则看到的还是 Gorge 渲染的旧 HTML）
docker compose exec phorge /opt/phorge/phorge/bin/config set \
  syntax-highlighter.engine PhutilDefaultSyntaxHighlighterEngine
docker compose exec phorge /opt/phorge/phorge/bin/cache purge --all

# 看当前生效的引擎与地址（会打印值来自哪个配置源）
docker compose exec phorge /opt/phorge/phorge/bin/config get syntax-highlighter.engine
docker compose exec phorge /opt/phorge/phorge/bin/config get gorge.render.uri

# 在高亮服务容器内探活（镜像基于 alpine，用 busybox 的 wget，没有 curl）
docker compose exec gorge-render wget -qO- http://127.0.0.1:8140/healthz
```

- **切了引擎但页面还是无色**：先看是不是缓存（见上面的 `bin/cache purge`），再看 Config
  页面的 setup 检查——`PhabricatorGorgeSetupCheck` 会探 `/healthz` 并把不可达报出来。
- **高亮服务挂了会怎样**：客户端抛 `PhutilSyntaxHighlighterException`，Differential 页面
  显示高亮失败提示，其余位置回退到无高亮渲染，页面本身不会 500。故障是可见的，不是静默的。
- **日志里出现 404 `ERR_NOT_FOUND`**：几乎总是 `gorge.render.uri` 结尾多了一个斜杠。
- **401 `ERR_UNAUTHORIZED`**：两端 token 不一致。注意只改 `.env` 里的
  `GORGE_RENDER_TOKEN` 后必须重启**两个**容器，否则一端还拿着旧值。

## 用 Gorge 做实时通知（可选）

Phorge 的实时通知（页面右上角的小铃铛即时亮起、Conpherence 消息实时到达）依赖一个
叫 **Aphlict** 的独立通知服务器，上游用 Node.js 写成、由 `bin/aphlict start` 启动。本镜像
**没装 Node.js**，所以开箱状态下通知只有刷新页面才看得到。

`docker-compose.gorge.yml` 里的 `gorge-notification` 服务是它的替代品：Go 实现，与 Aphlict
**线兼容**——一样的路径、一样的报文格式、一样不做鉴权。正因为线兼容，接入不需要改任何 PHP
代码，只是编排加上一段配置下发。镜像 `ghcr.io/soulteary/gorge-notification` 与 `gorge-render`
同源同标签（共用 `GORGE_IMAGE_TAG`），拉不到时本地构建一条命令即可，见下面「本地构建镜像」。

### 一个进程、两个端口，且两条配置的地址必须不同

这是接这个服务时唯一容易搞错的地方，值得先说清楚。

`notification.servers` 必须同时包含一条 `admin` 和一条 `client` 记录
（[`PhabricatorNotificationServersConfigType`](src/applications/notification/config/PhabricatorNotificationServersConfigType.php)
校验，缺任一类直接报错），而这两条记录的**消费者根本不是同一个进程**：

| 记录 | 谁在访问 | 因此 host 要填什么 | 端口要不要发布到宿主 |
|------|---------|------------------|-------------------|
| `admin` :22281 | `phorge` 容器里的 PHP（投递消息、查状态） | Compose 内网服务名 `gorge-notification` | **不要**。它不做鉴权，暴露出去等于允许任何人代发通知 |
| `client` :22280 | **用户的浏览器**（WebSocket） | 用户地址栏里用的那个主机名 / IP | **必须**。否则浏览器根本连不上 |

`client` 那条会被 [`PhabricatorNotificationServerRef::getWebsocketURI()`](src/applications/notification/client/PhabricatorNotificationServerRef.php)
拼成 `ws://host:port/` 交给页面里的 JS 客户端，所以填 Compose 服务名的话，浏览器做 DNS
解析时就会失败——服务端一切正常，Config 页面也全绿，只是通知永远不来。配置校验还额外要求
两条记录的 `host:port` 互不相同，这也是为什么明明是同一个容器，两条记录的 host 也不能写成
一样的值。

### 用叠加文件启动

和高亮共用同一个叠加文件，两个服务互不依赖，一条命令一起起来：

```bash
docker compose -f docker-compose.yml -f docker-compose.gorge.yml up -d --build
```

默认值（`127.0.0.1:22280`）适用于「在 Docker 宿主本机上开浏览器」这一种情况，不改 `.env`
就能用。别人要从局域网或公网访问时，改两个变量，它们**必须一起改**：

```bash
# .env —— 假设站点是 https://code.example.com/，用户浏览器解析得到的是同一个域名
GORGE_NOTIFICATION_CLIENT_HOST=code.example.com   # 写进配置、交给浏览器的地址
GORGE_NOTIFICATION_CLIENT_BIND=0.0.0.0            # 容器端口发布到宿主的哪张网卡
```

只改前者的表现是「Config 页面显示服务器正常，但页面收不到通知」（PHP 在内网探活成功，
浏览器却连不上）；只改后者则是配置里仍写着 `127.0.0.1`，别人的浏览器会去连他们自己的机器。

`entrypoint.sh` 每次启动都会把这些变量拼成下面这样一段 JSON，用
`bin/config set notification.servers --stdin` 幂等写进 `conf/local/local.json`：

```json
[
  {"type": "admin",  "host": "gorge-notification", "port": 22281, "protocol": "http"},
  {"type": "client", "host": "127.0.0.1",          "port": 22280, "protocol": "http"}
]
```

几个细节值得留意：

- 用 `--stdin` 而不是 `bin/config set <key> <value>`，是因为这个配置项是 JSON 列表类型，
  位置参数只能表达标量。
- `port` 是 JSON **整数**，写成字符串 `"22281"` 会被校验直接判非法，所以那段 JSON 由
  `php -r` 的 `json_encode` 生成而不是拼字符串。
- `notification.servers` 是 `setHidden(true)` 的配置项，Config 页面上**只读**，本来就只能落在
  `local.json` 里，所以这里的每次启动重写不会覆盖掉谁在界面上的改动。
- 写入失败只告警、不阻塞容器启动：通知是可降级功能，配不上时站点照常可用，只是回到「刷新
  页面才看到通知」。

相关可选变量（都有默认值，见 `.env.example`）：

| 变量 | 默认值 | 说明 |
|------|--------|------|
| `GORGE_NOTIFICATION_ADMIN_HOST` | `gorge-notification` | PHP 访问 admin 端口的地址，Compose 内网服务名。 |
| `GORGE_NOTIFICATION_ADMIN_PORT` | `22281` | admin 端口。与容器内监听端口绑定，改了要同时改叠加文件里的 `environment` 与 `healthcheck`。 |
| `GORGE_NOTIFICATION_CLIENT_HOST` | `127.0.0.1` | **浏览器**连 WebSocket 的地址。填 Compose 服务名会让浏览器解析失败。 |
| `GORGE_NOTIFICATION_CLIENT_PORT` | `22280` | 浏览器连的端口，同时也是发布到宿主的端口（容器一侧固定 `22280`）。走反代时填反代的端口（如 `443`），此时那条端口发布应当一并去掉。 |
| `GORGE_NOTIFICATION_CLIENT_PROTOCOL` | `http` | `http` 或 `https`。决定发给浏览器的是 `ws://` 还是 `wss://`。 |
| `GORGE_NOTIFICATION_CLIENT_PATH` | 空 | 反代上 WebSocket 路由的路径前缀，如 `/ws/`。只对 `client` 条目合法，`admin` 条目带 `path` 会被校验拒绝。 |
| `GORGE_NOTIFICATION_CLIENT_BIND` | `127.0.0.1` | client 端口发布到宿主时绑哪张网卡。改成 `0.0.0.0` 才能让其他机器连上。 |

### 走 Traefik 时的填法

叠加 `docker-compose.traefik.yml` 之后，站点走 `https://${TRAEFIK_DOMAIN}/`，浏览器直连
`22280` 这条路就不成立了：一是端口多半没对外开，二是 HTTPS 页面里发起 `ws://` 连接会被浏览器
按「混合内容」拒绝。正确做法是让 WebSocket 也从 Traefik 走一条路径路由。

**第一步，在 `.env` 里把 client 条目改成指向 Traefik：**

```bash
GORGE_NOTIFICATION_CLIENT_HOST=phorge.local   # 与 TRAEFIK_DOMAIN 一致
GORGE_NOTIFICATION_CLIENT_PORT=443            # Traefik 的 websecure 端口
GORGE_NOTIFICATION_CLIENT_PROTOCOL=https      # 于是发给浏览器的是 wss://
GORGE_NOTIFICATION_CLIENT_PATH=/ws/           # 下面那条路由的路径前缀
```

`admin` 那三项**不用动**：PHP 到 admin 端口这一跳仍然走 Compose 内网，中间没有 Traefik。

**第二步，给 `gorge-notification` 加一条 Traefik 路由，并停掉宿主端口发布。**
`docker-compose.traefik.yml` 目前只管 `phorge` 一个后端，通知服务的路由放在自己的
`docker-compose.override.yml` 里（`override` 文件会被 `docker compose` 自动叠加，不必写进
`-f` 列表）：

```yaml
# docker-compose.override.yml
services:
  gorge-notification:
    # 这一段不能省。走 Traefik 之后不该再从宿主直连，而且上面把 CLIENT_PORT 改成
    # 443 之后，叠加文件里那条端口发布会变成「把宿主 443 绑给通知服务」，正好和
    # Traefik 的 websecure 入口撞车，栈会起不来。
    # !reset 需要 Docker Compose 2.24.4 或更高版本，与 traefik 叠加文件的要求一致。
    ports: !reset []
    labels:
      - "traefik.enable=true"
      # 路径前缀要与 GORGE_NOTIFICATION_CLIENT_PATH 完全一致。
      - "traefik.http.routers.gorge-notification.rule=Host(`${TRAEFIK_DOMAIN:-phorge.local}`) && PathPrefix(`/ws/`)"
      - "traefik.http.routers.gorge-notification.entrypoints=websecure"
      - "traefik.http.routers.gorge-notification.tls=true"
      - "traefik.http.services.gorge-notification.loadbalancer.server.port=22280"
```

两点说明：

- **不需要 StripPrefix 中间件。** 与 Aphlict 一样，client 端口对**任意路径**都接受 WebSocket
  升级（上游本来就要用 `/~{instance}/` 这样的路径承载多实例），所以 `/ws/` 原样转发过去即可。
- **不要给这条路由挂 `phorge-forwardauth` 中间件。** WebSocket 握手带的是浏览器的 Cookie，
  ForwardAuth 后端未必认；而且通知服务本身不解析身份，走 Aphlict 的订阅模型。若确实需要把它
  保护起来，应该在网络层做，而不是在这条路由上加认证。

改完两处后重启：

```bash
docker compose -f docker-compose.yml -f docker-compose.traefik.yml \
  -f docker-compose.gorge.yml up -d --build
```

### 本地构建镜像

与 `gorge-render` 是同一个 Dockerfile、同一个上下文，只是换一个 `SERVICE` 构建参数：

```bash
docker build -t ghcr.io/soulteary/gorge-notification:latest \
  --build-arg SERVICE=gorge-notification \
  --build-arg PORT=22281 \
  /path/to/gorge/go
```

`PORT` 只喂给镜像**内置**的那条 HEALTHCHECK，不影响服务实际监听哪两个口。它的默认值是
gorge-render 的 8140，漏了这个参数，镜像自带的探针就会一直去敲并不存在的 8140，`docker run`
直接跑这个镜像会显示 unhealthy。本编排里看不出来——上面的 service 段显式写了自己的
healthcheck（探 admin 口 22281），正好盖掉它。

同样地，`-t` 打出的标签要和编排里 `image:` 完全一致；设了 `GORGE_IMAGE_TAG` 就跟着改。
`ghcr` 对「镜像不存在」和「无权访问」都返回 `403 Forbidden`，拉不下来时先本地构建，别去折腾
`docker login`。

### 验证与排障

```bash
# 看写进去的配置（会打印值来自哪个配置源）
docker compose exec phorge /opt/phorge/phorge/bin/config get notification.servers

# 在通知服务容器内探活（alpine 镜像，用 busybox 的 wget，没有 curl）
docker compose exec gorge-notification wget -qO- http://127.0.0.1:22281/healthz
docker compose exec gorge-notification wget -qO- http://127.0.0.1:22281/status/
```

界面上的确认方式：登录后打开 **Config → Cluster → Notification Servers**，两台服务器都应显示
正常。最后开两个浏览器窗口用不同账号登录，一方操作（比如在某个 Task 上留言），另一方应当
立即看到通知，不需要刷新。

- **Config 页面报 "Unable to Connect to Notification Server"**：这是
  `PhabricatorAphlictSetupCheck` 在探 admin 端口。先确认容器在跑
  （`docker compose ps gorge-notification`），再确认 `GORGE_NOTIFICATION_ADMIN_HOST`
  确实是 Compose 服务名而不是 `127.0.0.1`——后者在 phorge 容器里指的是 phorge 自己。
- **服务器都显示正常，但页面收不到通知**：几乎总是 `client` 条目填了浏览器够不着的地址。
  打开浏览器开发者工具的 Network → WS，看那个 `ws://` 请求连的是哪儿。
- **client 端口的 `GET /` 返回 501，这是正常的**，不是故障。
  `PhabricatorNotificationServerRef::testClient()` 正是拿 501 当健康信号，收到 200 反而会报错。
  也正因如此，叠加文件里的 `healthcheck` 探的是 admin 端口的 `/healthz`，不是 client 端口。
- **HTTPS 站点下浏览器控制台报混合内容 / `ws:` 被阻止**：`GORGE_NOTIFICATION_CLIENT_PROTOCOL`
  还是 `http`，改成 `https` 后重启 phorge 容器。
- **`bin/config get` 显示配置没写进去**：看容器启动日志里 `[entrypoint] 下发 Gorge 通知配置`
  那几行，写入失败会打印具体原因。最常见的是两条记录的 `host:port` 撞车。

## 用 Gorge 发送邮件（可选）

Phorge 的邮件（订阅通知、找回密码、`bin/mail send-test`）由 `cluster.mailers` 里配置的
mailer 投递。本镜像基于 `php:8.3-apache`，**没装任何 MTA**，也没有默认 mailer，所以开箱
状态下一封信也发不出去，Config 页面会常驻一条 "Mailers Not Configured"。

`docker-compose.gorge.yml` 里的 `gorge-mailer` 服务补的就是这一块：一个 Go 服务把 SMTP、
Sendmail、Amazon SES、SendGrid、Mailgun、Postmark 收在同一个 HTTP API
（`/api/mailer/*`）后面，容器内固定监听 `8110`。Phorge 侧新增了一个
[`PhabricatorMailGorgeAdapter`](src/applications/metamta/adapter/PhabricatorMailGorgeAdapter.php)，
在 `cluster.mailers` 里表现为一个 `type: "gorge"` 的条目。镜像
`ghcr.io/soulteary/gorge-mailer` 与另外两个服务同源同标签（共用 `GORGE_IMAGE_TAG`），拉不到
时本地构建一条命令即可。

与高亮**不同**，这里没有「手工切引擎」那一步：配置写进 `cluster.mailers` 就生效。
与高亮和通知都**不同**的是下面这两件事，也是接这个服务时唯一容易踩的地方。

### 两半配置都要填，只填一半的表现是「healthy 但发不出信」

变量分成两组，职责完全不同：

| 组 | 回答的问题 | 有没有默认值 |
|----|-----------|------------|
| `GORGE_MAILER_*` | Phorge 怎么找到 gorge-mailer | 有，直接能用 |
| `MAILER_TYPE` / `SMTP_*` / `MAILER_*` | gorge-mailer 用什么把信发出去 | **没有，必须填** |

只填上面一组时，容器起得来、`/healthz` 返回 200、Compose 报 `healthy`、`bin/config get
cluster.mailers` 也一切正常——然后每一封信都失败。这是本服务最容易达到、也最难自己看出来
的状态，所以它有一个独立的信号：`/readyz`。

```text
/healthz   进程活着、HTTP 栈在服务        -> 容器健康检查探它
/readyz    至少有一个投递后端配置成功      -> setup check 探它
```

叠加文件里的 `healthcheck` 刻意探 `/healthz` 而不是 `/readyz`：`phorge` 对它的
`depends_on` 用的是 `service_healthy`，若探 `/readyz`，一个还没配后端的 `gorge-mailer` 会把
整个站点堵在启动阶段，而「发不出邮件」不该等同于「站点起不来」。这个状态改由
[`PhabricatorGorgeMailerSetupCheck`](src/applications/config/check/PhabricatorGorgeMailerSetupCheck.php)
在 Config 页面报出来，报的时候会把服务端给出的具体原因一起带上。

### `cluster.mailers` 是共享列表，所以 entrypoint 做的是合并

`gorge.render.uri` 与 `notification.servers` 整项都归 Gorge 所有，`entrypoint.sh` 每次启动
整体重写它们是安全的。`cluster.mailers` 不是：你完全可以在里面手工配自己的 postmark 或
smtp 条目，整体重写会把它们悄悄抹掉。

所以 `gorge_mailer_set()` 做的是三步合并，而不是覆盖：

1. `bin/config get cluster.mailers` 读出现有列表（只取 `source: local` 那一份，因为
   `bin/config set` 写的正是 local 源，读写不同源的话一次「合并」就会把数据库里的值复制进
   `local.json`）；
2. 剔除 `key` 等于 `GORGE_MAILER_KEY`（默认 `gorge-mailer`）的条目；
3. 追加本次生成的条目后写回。

第 2 步不只是为了幂等：`cluster.mailers` 的校验明确拒绝重复的 `key`，不剔的话第二次启动
就会整项写入失败。反过来，**认键不认类型**也意味着你想再手工配一条指向另一个实例的
`gorge` mailer 时，只要换个 `key`，它就不会被覆盖。

如果读出来的内容解析不了，这一步会直接放弃本次写入并告警——那意味着没看懂你已有的配置，
此时写回去等于删掉它们。

写进去的条目长这样：

```json
{
  "key": "gorge-mailer",
  "type": "gorge",
  "inbound": false,
  "media": ["email"],
  "options": {
    "uri": "http://gorge-mailer:8110",
    "timeout": 30,
    "supports-message-id": false
  }
}
```

几个细节值得留意：

- **端点与 token 在 `options` 里，没有对应的全局配置项。** mailer 本来就是一条一条配的，
  多一个全局项只会多一个要查的地方，而且没法描述两个服务。代价是 `uri` 变成必填——写漏了
  会在写配置时就被拒绝，而不是在发第一封信时才失败。
- **`"inbound": false` 不能省。** 这个服务只做出站，而 `inbound` 的默认值是 `true`，适配器
  侧没有覆盖它的钩子，只能在配置里声明。
- **`supports-message-id` 默认 `false`。** 走 SMTP / Sendmail / SES 时服务自建 MIME、会保留
  我们指定的 `Message-ID`，走 SendGrid / Postmark 时 provider 通常会覆盖掉。谎报支持的表现
  是邮件会话在客户端里串不起来，很难察觉，所以按你实际使用的后端来开
  （`GORGE_MAILER_SUPPORTS_MESSAGE_ID=1`）。

### 用叠加文件启动

```bash
# 1) 在 .env 里配一个投递后端（这一步不能省，原因见上）
cat >> .env <<'EOF'
MAILER_TYPE=smtp
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_PROTOCOL=tls
SMTP_USER=phorge@example.com
SMTP_PASSWORD=...
EOF

# 2) 起服务（拉镜像失败 403 见「本地构建镜像」，命令与前两个服务相同，
#    只是 --build-arg SERVICE=gorge-mailer；--build 同样不能省）
docker compose -f docker-compose.yml -f docker-compose.gorge.yml up -d --build

# 3) 确认配置写进去了，且服务已就绪
docker compose exec phorge /opt/phorge/phorge/bin/config get cluster.mailers
docker compose exec gorge-mailer wget -qO- http://127.0.0.1:8110/readyz

# 4) 发一封测试邮件
echo 'test body' | docker compose exec -T phorge /opt/phorge/phorge/bin/mail send-test \
  --to you@example.com --mailer gorge-mailer --subject 'gorge test'
```

`--mailer gorge-mailer` 里的名字就是 `GORGE_MAILER_KEY`。不加这个参数时 Phorge 按 `priority`
自己挑，配了多个 mailer 时想定向验证哪一个就写哪一个。

相关可选变量（上半组都有默认值，下半组没有，全部见 `.env.example`）：

| 变量 | 默认值 | 说明 |
|------|--------|------|
| `GORGE_MAILER_URI` | `http://gorge-mailer:8110` | Phorge 访问发信服务的基础地址，同时是整段配置的总开关（留空即整段跳过）。**结尾不要带斜杠**。 |
| `GORGE_MAILER_TOKEN` | 空 | 服务间共享密钥，请求头 `X-Service-Token`，两端必须一致。留空表示不鉴权；这个服务比另外两个更值得设一个值——能往这个口投请求就等于能以你的域名对外发信。 |
| `GORGE_MAILER_KEY` | `gorge-mailer` | 条目在 `cluster.mailers` 里的 `key`，也是 entrypoint 判断「哪一条归我管」的依据。 |
| `GORGE_MAILER_TIMEOUT` | `30` | 单次发信的客户端超时（秒）。这一跳占住一个 phd worker，而服务端内部已有有界重试，不宜给大。 |
| `GORGE_MAILER_PRIORITY` | 空 | 条目的 `priority`，数字越大越优先，必须大于 0。留空表示不写这个键。 |
| `GORGE_MAILER_SUPPORTS_MESSAGE_ID` | `0` | 取 `1` 时允许 Phorge 指定 `Message-ID`。按实际后端来开，理由见上。 |
| `GORGE_MAILER_BODY_LIMIT` | `524288` | 单封邮件正文的长度上限（字节），纯文本与 HTML 各自计算，超出按 UTF-8 边界截断。**不是**请求体上限：那一项固定 10MiB、没有开关，附件受它约束。 |
| `GORGE_MAILER_MAX_RETRIES` | `2` | 单个后端内部重试几次才回退到下一个后端。永久失败立即短路，不重试也不换后端。 |
| `GORGE_MAILER_RETRY_WAIT` | `2` | 上述每次重试之间等待的秒数。 |
| `MAILER_TYPE` | 空 | 投递后端：`smtp` / `sendmail` / `ses` / `sendgrid` / `mailgun` / `postmark` / `test`。**留空则 `/readyz` 不通。** |
| `GORGE_MAILER_CONFIG` | 空 | 一次配多个后端用的 JSON 列表（单行）。配了它，`MAILER_TYPE` 那一组整体不生效。 |

### 本地构建镜像

与另外两个服务是同一个 Dockerfile、同一个上下文，只换 `SERVICE` 构建参数：

```bash
docker build -t ghcr.io/soulteary/gorge-mailer:latest \
  --build-arg SERVICE=gorge-mailer \
  --build-arg PORT=8110 \
  /path/to/gorge/go
```

`PORT` 只喂给镜像**内置**的那条 HEALTHCHECK，不影响服务实际监听哪个口（那个由
`GORGE_LISTEN_ADDR` 决定）。它的默认值是 gorge-render 的 8140，漏了这个参数，镜像自带的
探针就会一直去敲 8110 上并不存在的 8140，`docker run` 直接跑这个镜像会显示 unhealthy。
本编排里看不出来——上面的 service 段显式写了自己的 healthcheck，正好盖掉它。

同样地，`-t` 打出的标签要和编排里 `image:` 完全一致；设了 `GORGE_IMAGE_TAG` 就跟着改。

### 验证与排障

```bash
# 服务端认得哪些后端（要带 token，留空时可省掉 --header）
docker compose exec gorge-mailer wget -qO- \
  --header="X-Service-Token: ${GORGE_MAILER_TOKEN}" \
  http://127.0.0.1:8110/api/mailer/mailers

# 看某封信最后发生了什么（id 由 bin/mail send-test 打印）
docker compose exec phorge /opt/phorge/phorge/bin/mail show-outbound --id <id>
```

- **Config 页面报 "Gorge Mailer Service Not Ready"**：服务活着但一个后端都没配，或后端的
  凭据不完整。issue 正文里带着服务端给出的原因。补齐 `.env` 的下半组变量后重启
  `gorge-mailer` 容器。
- **Config 页面报 "Gorge Mailer Service Unreachable"**：容器没起来，或 `GORGE_MAILER_URI`
  指向了这台服务器够不着的地址。注意在 `phorge` 容器里 `127.0.0.1` 指的是 phorge 自己。
- **信一直卡在队列里反复重投**：这是**临时**失败的正常表现（连接被拒、provider 限流），
  队列会继续重试。若是收件人地址非法这类**永久**失败，服务端返回 `ERR_PERMANENT_FAILURE`，
  PHP 侧会转成 `PhabricatorMetaMTAPermanentFailureException`，该封信直接落 `FAIL` 不再重投。
  `bin/mail show-outbound` 能看到具体是哪一种。
- **日志里出现 404 `ERR_NOT_FOUND`**：几乎总是 `GORGE_MAILER_URI` 结尾多了一个斜杠。
- **401 `ERR_UNAUTHORIZED`**：两端 token 不一致。只改 `.env` 后必须重启**两个**容器。
- **`bin/config set cluster.mailers` 报 mailer 类型 `gorge` 未知**：类映射没重新生成，
  `src/__phutil_library_map__.php` 里缺 `PhabricatorMailGorgeAdapter`。改过 PHP 源码后别忘了
  `docker compose ... up -d --build`——源码是烤进镜像的。
- **自己手工配的 mailer 不见了**：不该发生，entrypoint 做的是合并而不是覆盖（见上）。真遇到
  的话看启动日志里 `[entrypoint] 下发 Gorge 发信配置` 那几行，读取失败时它会告警并整段跳过。

## 参考

- [安装指南](src/docs/user/installation_guide.diviner)
- [配置指南](https://we.phorge.it/book/phorge/article/configuration_guide/)

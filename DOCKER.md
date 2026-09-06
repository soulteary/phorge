# Phorge (phorge-fork) 容器化部署说明

本 fork 提供一套精简的 Docker / Docker Compose 封装，以**单体**方式运行 Phorge：
一个基于 `php:8.3-apache`（mod_php）的应用容器，加一个 MySQL 8 容器，以及一个
只跑一次的授权初始化任务。所有可调项都有内置默认值，开箱即可跑。

## 前置要求

- Docker 与 Docker Compose（Compose V2，`docker compose ...`）
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
docker compose -f docker-compose.yml -f docker-compose.traefik.yml up -d
```

叠加文件做了三件事：

- 新增一个 `traefik` 服务（`web:80` / `websecure:443` 入口、Docker provider、
  可选 dashboard），并定义 `phorge-forwardauth` ForwardAuth 中间件。
- 给 `phorge` 服务补充路由标签（`Host(${TRAEFIK_DOMAIN})` → websecure，绑定
  ForwardAuth 中间件），并把应用端口收回到本机回环（对外入口改为 Traefik）。
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

- **后端必须只能经由 Traefik 到达**：不要把 `PHORGE_HTTP_PORT` 暴露到公网。叠加文件已
  把应用端口收到 `127.0.0.1`，对外入口只剩 Traefik。
- **Apache 默认清洗客户端伪造头**：`docker/phorge-apache.conf` 用 `RequestHeader unset`
  无条件清除请求方带来的 `X-Auth-*`（需 `mod_headers`，已在 Dockerfile `a2enmod headers`
  中启用），Traefik 会重新注入可信头，二者不冲突。这是"纵深防御"的一层，**不能**替代
  "后端只经 Traefik 到达"这个前提。
- 若 Phorge 仍可被直接访问，攻击者可伪造这些头冒充任意用户——请务必在网络层隔离。

## 参考

- [安装指南](src/docs/user/installation_guide.diviner)
- [配置指南](https://we.phorge.it/book/phorge/article/configuration_guide/)

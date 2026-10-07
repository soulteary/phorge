# Phorge (phorge-fork) 容器化部署说明

本 fork 默认以 Phorge + Gorge 协作栈运行。Phorge 镜像按职责拆成一次性的
`phorge-migrate`、Apache `phorge` 和 `phorge-daemon`；Gorge 核心服务随默认
Compose 启动。数据库基础设施单独放在 `docker-compose.mysql.yml`，由默认
`docker-compose.yml` 用 `extends` 引用。旧版纯 Phorge 单容器编排
（`docker-compose.legacy.yml`）已经移除，不再是受支持的启动方式。

## 前置要求

- Docker 与 Docker Compose（Compose V2，`docker compose ...`；Traefik 叠加编排要求
  Docker Compose 2.24.4 或更高版本，以支持 `!reset` 标签）
- 无需预装 PHP / MySQL：镜像自建，数据库随 Compose 一起启动

## 启动接管验证

启动需要 Docker Compose 2.20 或更新版本，以支持可选 profile 配置任务的
`depends_on.required=false`。Web 等待已启用的 mailer/search 配置任务成功后再加载部署配置。
`mailer` profile 默认 `GORGE_MAILER_EXCLUSIVE=true`：旧 SMTP/provider 保留为收信适配器，
出站邮件只选择 Gorge，SMS 仍使用原有适配器。`search` profile 默认
`GORGE_SEARCH_EXCLUSIVE=true`：只发布 Gorge 搜索引擎；需要保留旧引擎时显式设为 false。
独占模式与 `GORGE_SEARCH_KEEP_MYSQL=1` 冲突，生成器会拒绝该配置。

Web/daemon 在执行主进程前运行 `scripts/setup/check_gorge_startup.php`。
它检查已配置服务的 `/readyz`、两个 diff 路由、队列和 worker 的 v1 capability；
搜索还调用只读 `/api/search/exists` 验证后端可达，`exists=false` 仍需初始化/重建索引。
`PHORGE_GORGE_STARTUP_TIMEOUT` 默认为 60 秒（可设 1..600）。required 检查失败阻止启动，
fallback 检查只告警；queue/worker 版本和 diff 路由不能降级到已经移除的 PHP 实现。
邮件就绪只证明适配器/持久投递存储配置可用，不发送测试邮件，也不证明供应商接受投递。

worker 的 `/api/worker/meta` 在 PHP 启动前报告静态 capability，避免启动闭环；
`/readyz` 在 PHP 启动后验证 `worker.execute` capabilities、队列协议、政策和 outbox。
首次握手成功前不会领取业务任务。daemon 等待 Web 和 worker 健康。
迁移角色不等待 worker ready；否则新安装无法生成它需要的表和政策文件。

配置非空且一致的 `GORGE_CONDUIT_TOKEN`，PHP 执行接口拒绝空 token。
默认编排必须部署包含这些协议与探针的 Gorge 镜像；其中只声明 `image:` 的
服务不会被 `docker compose up --build` 重新构建。生产覆盖配置则为核心 Gorge
服务补充本地源码 `build:`，启动方式见 [生产切换](PRODUCTION-CUTOVER.md)。
旧镜像缺少 capability 时会明确阻止启动。

## 快速启动

本节基础编排保留历史 `service-2026.10.07-r2` 镜像标签，仅用于该标签实际存在且
已有对应源码配对验收的栈。它不会自动选择匹配当前 checkout 的新发布；新发布的
digest 部署或当前源码本地构建请先按后面的“发布镜像与源码配对”选择路径。

```bash
# 1) 准备部署环境变量；必需的服务 token 要明确配置
cp .env.example .env
# 编辑 .env，设置非空 GORGE_CONDUIT_TOKEN，并使用已确认与源码匹配的镜像配置。
# token 可以用 openssl rand -hex 32 生成；PHP、gateway、worker 共用这个值。

# 2) 构建 Phorge，并启动默认核心服务
docker compose up -d --build

# 3) 浏览器访问（Host 必须含点号，用 127.0.0.1 而不是 localhost）
#    默认地址：http://127.0.0.1:8088/
```

首次启动会自动完成：

- 构建应用镜像（包含内置兼容运行库、编译 PHP 扩展）。
- 启动 MySQL，等待其健康后由一次性任务 `db-init` 为普通库用户补齐
  `phabricator_%` 整组库的授权（见 `docker/db-grant.sql`）。
- `phorge-migrate` 独占 `bin/storage upgrade --force`。它与 Mailer/Search 可选配置
  任务通过 `phorge-conf` 共享卷上的排他锁串行运行，并原子替换
  `deployment.json`。新安装默认选择 `collaboration`；检测到现有
  `phabricator_meta_data` 且没有持久化选择时保持 `full`，避免升级自动
  停用应用。
- schema 完成后，`phorge` 只运行 Apache，`phorge-daemon` 独立监护 phd；两者
  只读部署配置，不再迁移或争写配置。
- 默认启动 render、conduit、notification、file-storage、webhook、taskqueue、
  worker 与 db-api。基础编排历史标签默认锁定 `2026.10.07-r2`，不会跟随 `latest`
  漂移，也不能据此推断它满足当前源码协议。

访问根路径 `/` 会 302 跳到 `/auth/register/` 的初始管理员注册引导页，按向导创建第一个
账号即可。应用容器带 healthcheck（探测免鉴权的 `/status/`），有 60s `start_period`，
所以启动后需要等一会儿 `docker compose ps` 里才会变成 `healthy`。

Phorge `2026.10.07-r2` 的合并与发布依赖 Gorge 同版本全部服务镜像先发布成功。
候选阶段的发布顺序、配对源码与验收证据见 [发布准备](docs/releases/2026.10.07-r2.md)。
这是该版历史记录；当前发布流程见下一节。

## 发布镜像与源码配对

当前 Gorge 发布入口是整套 GitHub Release 的 `release-manifest.json`，不再生成新
`service-<CalVer>` 或更新 `service-latest` 标签。`GORGE_IMAGE_TAG` 及各
`*_IMAGE_TAG` 只是基础编排的历史标签选择器，不能拼出本次发布的镜像。
当前流程和验收覆盖见 [Gorge 发布门禁](../gorge/deploy/release/README.md)。

使用已发布产物时，先核对清单的 `phorgeCommit`、`gorgeCommit` 和验收记录，检出
对应 Phorge 源码；将 `images.<service>.image` 的完整 `repository@sha256:...`
逐项写入本地 Compose override 的 `gorge-<service>.image`。所有启用的服务都要覆盖，
不能只钉住 mailer 后混用其他历史标签。下面仅说明一个服务的写法，替换占位符并
补齐其余服务后使用：

```yaml
services:
  gorge-mailer:
    image: ghcr.io/soulteary/gorge@sha256:<manifest中该服务的64位digest>
    build: !reset null
```

把该 override 放在生产配置之后，清除每个候选服务继承的 `build:`，分别构建配对
Phorge 镜像、拉取 Gorge digest，再以 `up --no-build` 启动；保留相同 profiles 和生产
预检。不能对 digest 配置继续 `up --build`，重新构建后沿用已验收产物的身份。
镜像可执行文件和标签验证不替代目标环境的供应商、搜索/权限和浏览器业务验收。

使用当前本地源码时，选择 [生产覆盖配置](PRODUCTION-CUTOVER.md) 的本地 `build:`
路径。它从相邻 Gorge 源码构建核心服务；两个 checkout 必须是完整验收使用的同一
源码对，记录 commit/dirty/源码摘要。不能把已有历史 tag 的验收证据套到新构建上。

## 环境变量

### 安装完成检查

Web 容器启动时会以告警模式执行安装检查。尚未创建管理员、没有可用管理员、
或没有启用允许登录的认证提供者时，日志明确提示安装未完成，但保持 Web 运行，
方便首次注册或账号恢复。该检查不修改账号、认证配置或生成恢复凭据。

显式验收当前安装：

```bash
docker compose exec -w /opt/phorge/phorge phorge php scripts/setup/check_installation.php
```

输出 JSON 状态：`needs_admin`、`needs_admin_recovery`、`login_unavailable`、
`ready` 或 `unavailable`；除 `ready` 外退出码为 1。
`ready` 仅证明本地存在有效管理员和登录提供者配置，不能替代实际登录或外部
OAuth/LDAP 可达性验证。添加 `--warn-only` 时未完成状态只告警并返回 0。
镜像内代码需要重新构建后才会在启动时执行新检查。

### 配置变量

默认值以 Compose 文件和 `.env.example` 为准。启动执行协议需要非空 `GORGE_CONDUIT_TOKEN`；生产部署同时配置数据库凭据、各服务 token 和 `PHORGE_BASE_URI`。

| 变量 | 默认值 | 说明 |
|------|--------|------|
| `MYSQL_ROOT_PASSWORD` | `phorge_root` | MySQL root 密码。只有 `db-init` 授权和 MySQL 自身健康检查用到，Phorge 应用**不用** root 连库。 |
| `MYSQL_USER` | `phorge` | Phorge 连库的普通账号。首次初始化数据卷时由 MySQL 镜像创建，随后由 `db-init` 授予 `phabricator_%` 整组库权限。 |
| `MYSQL_PASSWORD` | `phorge` | 上述普通账号的密码，会同时下发给 `mysql` 与 `phorge` 两个服务。 |
| `MYSQL_DATABASE` | `phorge` | MySQL 首次初始化顺带创建的默认库。Phorge 实际使用 `phabricator_*` 命名空间下的一组库，这里只是给普通账号一个落脚点，保持默认即可。 |
| `PHORGE_HTTP_PORT` | `8088` | 宿主机映射端口，浏览器访问 `http://127.0.0.1:<该端口>/`。 |
| `PHORGE_BASE_URI` | `http://127.0.0.1:${PHORGE_HTTP_PORT}/`（默认注释，由 compose 自动拼出） | 站点绝对地址，Phorge 用它生成链接并校验请求的 Host 头。**域名必须含点号**，裸 `localhost` 会被拒绝。改了端口一定要让它联动，否则会 redirect 到错误地址。 |
| `PHORGE_TIMEZONE` | `UTC` | 站点默认时区（`phabricator.timezone`），取 PHP 时区标识符，如 `Asia/Shanghai`。 |
| `PHORGE_PRODUCT_PROFILE` | `auto` | `auto` 让新安装使用协作模式、已有安装保持 full；也可显式指定 `collaboration` 或 `full`。结果持久化在只读部署配置中。 |
| `PHORGE_GORGE_POLICY` | `required` | 已配置 Gorge 服务的失败策略：`required` 直接暴露错误；`fallback` 迁移期允许旧实现并记录 `[gorge-fallback]` 日志；`off` 停用请求路由型服务。 |
| `PHORGE_DB_NAMESPACE` | `phabricator` | Phorge、db-init 与 file-storage/webhook/taskqueue/db-api 的唯一数据库前缀。已有自定义 `storage.default-namespace` 的安装升级前必须设为相同值；不一致时 entrypoint 会拒绝启动。 |
| `GORGE_IMAGE_TAG` | `2026.10.07-r2` | 基础栈的历史服务标签选择器；只用于已存在且已验收的历史镜像。当前 Release 用 manifest digest override，见“发布镜像与源码配对”。 |
| `PHORGE_WAIT_DB` | `1` | `migrate` 角色是否在执行 `storage upgrade` 前等待数据库就绪。严格取值 `1` 开启，其它任何值视为关闭。关掉首启动 `storage upgrade` 大概率失败。 |
| `PHORGE_AUTO_UPGRADE` | `1` | `migrate` 角色是否自动执行 `bin/storage upgrade --force`。只有 `migrate` 读它，`web` / `daemon` 永不迁移 schema。 |
| `PHORGE_CONTAINER_ROLE` | `web` | 容器角色：`migrate`（写 `local.json`、迁移 schema、发布 `deployment.json`）、`web`、`daemon`。`web` / `daemon` 是只读消费者，缺少这两个文件时直接退出。单容器 `all` 角色已移除。 |

`MYSQL_*`、站点地址与时区用于首次生成 `conf/local/local.json`，后续改 `.env` 不会自动覆盖已有本地配置。升级前备份配置，按有效配置来源修改这些普通配置；数据库账号或地址变化还须同步 Gorge 连接配置并重新创建消费者。不要靠删除整个配置文件重置服务所有权。

普通本地配置调整先暂停会写配置的 migrate/profile 任务，备份整个 `conf/local/`
到受控目录，再对照 `local.json`、`deployment.json` 和 Web Config 确认来源。
只更新确认需要变更的键，可用 `bin/config set <key> --stdin` 输入 JSON 值；
只有确认应恢复到其他来源或默认值的单个本地键才运行 `bin/config delete <key>`。
这个命令只删除该键，不会根据新 `.env` 自动重建它。部署配置管理的键应修改
`.env`/Compose，重跑同一 profiles 的 migrate/config 任务并重启消费者；它们仍由
只读 `deployment.json` 决定，不能靠 Web Config 或删除本地键覆盖。

### 统一部署控制面

默认栈将服务端点、token、产品 profile 和消费者所有权写入
`conf/local/deployment.json`。这个文件作为只读配置源加载，优先级高于 Web Config
数据库和 `local.json`；一次生成整份文件并用 `rename(2)` 原子替换，所以 Web 与 daemon
不会看到“端点已经更新、所有权尚未更新”的中间状态。它包含的部署值在 Config 页面不可写，
应通过 `.env` 和 Compose 变更。

阶段三默认将 `gorge.service-policy` 设为 `required`：已选中的 Gorge
服务超时、返回错误或响应非法时，Phorge 不再按请求静默进入旧实现。迁移期间可临时设为
`fallback`；每次实际进入原生路径都会记录
`[gorge-fallback] service=... operation=... process_count=...`。稳定一个发布周期且
日志计数归零后即可开始删除旧实现。`off` 只影响 render/diff、conduit、search、
file 和 mailer 等请求路由型能力；webhook/taskqueue 的排他消费者仍由各自
`GORGE_*_MODE` 切换，不能靠全局策略隐式改写。
两个服务是例外，因为它们要回退到的原生实现已经删除：render/diff 无论策略取值都会在
服务不可用时直接失败；db 的 `fallback` 与 `off` 会被忽略并按 `required` 处理，
`PhabricatorGorgeDBSetupCheck` 会报告这个被忽略的覆盖。

Task queue 的 enqueue 是例外：只有在发送请求前即发现端点不可用时才允许进入 SQL
fallback。请求发出后的超时或坏响应会直接报错，因为 Gorge 可能已经提交任务；此时再
写一条原生任务会造成重复执行。完整的跨路径重试需要两端共享幂等键后才能开放。
注意这条 fallback 现在只保证任务被写下来而不会丢：原生 taskmaster 已经删除，
`phd` 不再启动它，所以写进 SQL 队列的任务要等 Gorge 恢复后才会被消费。
同理，worker 业务逻辑成功但 required completion 回报失败时，Phorge 会显式报错并把
SQL 任务停在 `gorge-completion-pending` lease 下，避免租约到期后重复执行；恢复服务后
应由运维确认实际归档状态再做 reconciliation。

Webhook 与 task queue 分别使用 `gorge.webhook.owner` 和
`gorge.taskqueue.owner` 明确选择唯一消费者；URI 只表示端点。默认栈把两者设为
`gorge`。源码安装未设置 owner 时仍支持 `auto`，保持“存在 URI 即使用 Gorge”的旧行为。

但 `phorge` 这一侧现在是空的：原生 webhook 投递与原生 taskmaster 都已经移除，
所以把 owner 设为 `phorge`——或者在没有配置 URI 的情况下让 `auto` 落到 `phorge`——
意味着**没有任何消费者**，而不是交还给 phd。两个 setup check
（`gorge.webhook.no-consumer` / `gorge.taskqueue.no-consumer`）会把这个状态报出来，
`phorge-migrate` 也会拒绝 `GORGE_WEBHOOK_MODE=disable` 与 `GORGE_TASKQUEUE_MODE=disable`。

Gorge 的接入域由 `PhabricatorGorgeServiceRegistry` 统一登记。默认控制面不再逐项
调用 `bin/config set`，也不再用 `collaboration-profile-state.json` 长期维护应用和字段
的三方合并；从阶段一升级时若检测到旧状态文件，会先恢复原始管理员配置，再一次性迁移。

`notification.servers` 是这次迁移里唯一可能被清掉的管理员可见配置。旧控制面把 Gorge
的两条记录直接写进 `local.json`，快照（`gorge-notification-state.json`）是后来才加的，
所以最早那批安装没有可恢复的原值，而不带通知选择器的迁移又不会覆盖这一项。迁移因此会
识别 Gorge 写出的那个固定结构（两条记录：`admin` 固定 `http`，其后一条 `client`，没有
多余字段）并移除它，同时在 stderr 上打印被移除的值。这个结构也可能与管理员自建的
Aphlict 配置相同，取舍是明确的：清掉是“通知需要重新配置”并且有警告，留下则是悄悄指向
一台即将下线的主机。看到该警告后按后文的 Aphlict/通知章节重新配置即可。

部署配置由 `phorge-migrate` 和启用的 profile 配置任务生成，不由 Web 的 entrypoint 逐项写 `local.json`。`PHORGE_CONTROL_PLANE=legacy` 已移除，显式设置会退出。

自定义编排要接 Gorge，不要用 `-f` 叠加 `docker-compose.gorge.yml` —— 它只是
`gorge-*` 服务定义的来源，由默认文件用 `extends` 引用。自定义栈必须自己定义
`phorge-migrate` / `phorge` / `phorge-daemon` 三个角色，并把全部 `GORGE_*` 端点
变量放在 **`phorge-migrate`** 上；`web` 与 `daemon` 只读加载配置，缺少 migrate 角色
的编排会因为没有 `local.json` / `deployment.json` 直接退出。

需要邮件、搜索或 Gitea 单向事件桥时，显式启用对应 profile：

```bash
docker compose --profile mailer up -d
docker compose --profile search up -d
docker compose --profile gitea up -d
```

Mailer 与 Search 的一次性配置任务会随 profile 运行；Search 的全量索引仍需由管理员
显式执行。之后不带相应 profile 再运行基础栈时，`phorge-migrate` 会撤销
已持久化的受管配置：Mailer 只移除 `GORGE_MAILER_KEY` 命名的条目；Search 移除
Gorge 搜索条目，保留其它引擎，列表为空时恢复 MySQL/Ferret。
启用 `gitea` profile 前，确认 Gorge 镜像已经发布，并显式设置
`GORGE_GITEA_IMAGE_TAG=2026.10.07-r2`；未设置时默认 `unreleased` 会阻止拉取。
不再提供「不接入 Gorge」的 Compose 入口。`docker-compose.legacy.yml` 已经删除，
默认栈是唯一受支持的编排；需要按域取舍时，用 `PHORGE_GORGE_POLICY` 与各服务的
`GORGE_*_MODE` 变量控制，而不是切回旧编排。仍在宿主机上裸跑 Gorge 的联调场景，
用 `docker-compose.host-gorge.yml` 叠加默认文件（见该文件顶部说明）。

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

## 核心服务与可选能力

以下命令在本仓库根执行。默认栈已经包含 render、conduit、notification、file-storage、webhook、taskqueue、worker 和 db-api；`docker-compose.gorge.yml` 是 `extends` 的定义来源，不能作为额外的 `-f` 叠加文件。

当前源码配对联调使用相邻 `../gorge` checkout：

```bash
docker compose -f docker-compose.yml -f docker-compose.local.yml config --quiet
docker compose -f docker-compose.yml -f docker-compose.local.yml up -d --build
```

后续命令必须使用同一组 `-f` 和 profiles。只执行 `restart` 不会更新环境变量、挂载或部署配置；修改后重新运行配置任务并重新创建消费者。基础文件锁定的旧发布标签不代表支持当前能力，使用发布镜像前先核对配对协议和验收结果。生产搜索、原生邮件、图片与清理使用 [PRODUCTION-CUTOVER.md](PRODUCTION-CUTOVER.md) 的覆盖配置及交接流程。

### 高亮与 diff

两者由 `gorge-render:8140` 提供，通过 `gorge.render.uri/token` 访问。unified 与 prose diff 的本地实现均已删除；`gorge.diff.enabled` 不再被读取，编排固定启用 Go diff 路由。服务故障或响应校验失败直接报错，没有本地算法回退。

协作 profile 会选择 Gorge 高亮器。部署源持有 `syntax-highlighter.engine` 时，不能用 `bin/config set` 覆盖它；full profile 下按有效配置选择引擎。引擎或输出变更后清理渲染缓存：

```bash
docker compose exec -w /opt/phorge/phorge phorge bin/cache purge --caches general,changeset
```

`GORGE_RENDER_MAX_BYTES` 和 `GORGE_DIFF_MAX_BYTES` 控制域输入上限；`GORGE_RENDER_TIMEOUT_SEC` 是优雅关闭等待时间，不是请求超时。接口、语言别名和限制见 [render](../gorge/docs/modules/render.md) 与 [diff](../gorge/docs/modules/diff.md)。

### 实时通知

notification 在一个进程内监听 admin `22281` 和 client `22280`，严格 Aphlict 兼容，不使用 service token。PHP 从 Compose 网络访问 admin；浏览器必须能解析 client 的公开地址。公网或反代部署需同时设置 client host/protocol/port/path 和实际端口发布，不能把 Compose 服务名交给浏览器。

本地开发的 client `127.0.0.1:22280` 指向浏览器所在宿主机；同一地址在 PHP 容器中指向容器自身，因此容器探测失败不能代表浏览器断线。通知状态页分别展示 PHP 对 admin 的检查和当前浏览器的连接状态，client 列保留公开入口信息。多条 client 配置是候选入口，当前浏览器已连接不表示每条入口都已验证。通知关闭、入口禁用、页面协议不兼容或未登录时，也不能从 admin 健康推断浏览器已连接。

排查时确认浏览器生成的 WebSocket 地址及 `cluster.instance`，保留正确的公开 host/protocol/port/path；远程用户不能使用开发机的 loopback 地址。client 普通 HTTP GET 返回 HTTP 501，正文为 `HTTP/501 Use Websockets\n`，仍是 Aphlict 协议约定，不能为消除界面提示而改为 200。该 HTTP 诊断与浏览器实际 WebSocket 状态相互独立。

`notification.servers` 和 worker 的 notification policy 由迁移任务原子生成。检查有效值：

```bash
docker compose exec -w /opt/phorge/phorge phorge bin/config get notification.servers
```

admin/client 的 `host:port` 不能相同；admin 不接受 `path`。TLS 在反代终止，Go 不读取 Aphlict 的 `ssl.*`。多副本必须通过 `GORGE_NOTIFICATION_CONFIG_FILE` 配置 peer cluster，进程内 history 不提供持久回执。协议和实例隔离见 [notification](../gorge/docs/modules/notification.md)，原生投递边界见后文。

### 邮件

`mailer` profile 启动服务与一次性 `phorge-mailer-config`。它将受管 `key` 的 Gorge 条目写入 deployment 配置；provider 旧条目保留收信用途，出站 email 选择 Gorge，SMS 单独配置。端点/token 放在该 adapter 的 options 中。

必须配置真实 provider。当前 Go 只接受 `GORGE_MAILER_TYPE`、`GORGE_MAILER_CONFIG`、`GORGE_MAILER_KEY` 等规范服务变量，provider 原生 `SMTP_*`、`MAILER_API_KEY` 等仍有效。基础 Compose 已把旧 `MAILER_TYPE`/`MAILER_KEY` 输入桥接到规范变量，新输入优先；旧 `MAILER_CONFIG` 没有桥接，必须改为 `GORGE_MAILER_CONFIG`。`.env` 的 `GORGE_MAILER_BACKEND_KEY` 传给 Go 的 `GORGE_MAILER_KEY`；PHP 使用的 `GORGE_MAILER_KEY` 是 adapter 标识，两者分别配置。自定义 override 可直接给 `gorge-mailer.environment` 设置规范变量，例如：

```yaml
services:
  gorge-mailer:
    environment:
      GORGE_MAILER_TYPE: smtp
      GORGE_MAILER_KEY: default
      SMTP_HOST: ${SMTP_HOST}
      SMTP_PORT: ${SMTP_PORT:-587}
      SMTP_USER: ${SMTP_USER}
      SMTP_PASSWORD: ${SMTP_PASSWORD}
      SMTP_PROTOCOL: ${SMTP_PROTOCOL:-}
```

配置 provider 后用同一 override 启动 `--profile mailer`。`/healthz` 只表示进程存活；`/readyz` 检查后端配置，PHP required 启动门禁仍会阻止未就绪的服务。受控收件人的真实投递与账本/投影验收另行执行。provider accepted 不等于收件箱送达。完整配置见 [mailer](../gorge/docs/modules/mailer.md)，native outbox 见后文与生产切换文档。

`GORGE_MAIL_DELIVERY_MODE=legacy` 表示 PHP 兼容任务通过 Gorge 同步发送，
并不恢复已退役的 PHP SMTP/provider 实现。`native` 让新建邮件进入持久 outbox、
账本与结果投影；它还要求 `GORGE_MAILER_DELIVERY_DSN`，worker 的
`GORGE_WORKER_MAIL_OUTBOX_DSN` 必须指向同一 metamta 主库。生产覆盖配置从同一
`GORGE_MAILER_DELIVERY_DSN` 下发这两个消费者的 DSN。legacy 的旧任务仍需收尾。

同步路径在网络提交前先持久化 unknown fence。只有明确未开始/未被接受的失败
才恢复 queued；超时、连接中断、坏 JSON、无有效 `mailerKey` 回执、进程崩溃或
接受后的状态保存失败都会保留 unknown，禁止自动重投及切换后端。
`ERR_SEND_FAILED` 是确认未接受的可重试回执，`ERR_PERMANENT_FAILURE` 是明确拒绝，
`ERR_OUTCOME_UNKNOWN` 需要人工核对。legacy unknown 没有 native delivery 查询记录，
应核对 PHP 邮件状态、任务记录和供应商日志；native unknown 可使用后文的只读查询。
不要把状态手改为 queued，或以重发来修复结果投影。

### 搜索

`search` profile 启动 `gorge-search` 和 `phorge-search-config`；Elasticsearch/Meilisearch 由部署者提供。`/readyz` 默认只验证存在可读配置，不保证所有后端可达、索引存在或权限正确；启动门禁另调用只读 exists。

默认 `GORGE_SEARCH_EXCLUSIVE=true` 仅发布 Gorge 读写适配器。保留 MySQL 要明确关闭 exclusive，再配置 `GORGE_SEARCH_KEEP_MYSQL=1`，两者同时开启会被拒绝。PHP Elasticsearch 客户端已经退役，不能恢复旧 PHP ES adapter。

首次启用后端或 mapping 不兼容时，使用已有索引工作流：

```bash
docker compose --profile search exec -w /opt/phorge/phorge phorge bin/search init
docker compose --profile search exec -w /opt/phorge/phorge phorge bin/search index --all --force
```

`init` 会删除并重建索引，只在已安排重建的目标上执行；随后验收已知对象、更新、删除和权限过滤。`bin/search ngrams` 是 MySQL/Ferret 工作流，不用于 Gorge 的 CJK 分析器。

回退通过配置任务生成受管 deployment 配置，不能用 Web 或 `bin/config set cluster.search` 覆盖。退出 search profile 会移除受管 Gorge 条目、保留其他引擎，空列表恢复 MySQL；恢复读取前仍须证明其索引完整并重启消费者。生产投影 generation 的切换和回退见 [search-projection](../gorge/docs/modules/search-projection.md)。

### 文件与生命周期

默认 file-storage 使用命名卷。PHP 仍负责权限、元数据与引用，Gorge 负责字节。大文件使用 chunks 或可选分块上传协议；协议启用不等于历史文件已迁移。上传、删除 outbox 和历史迁移见 [file-lifecycle](../gorge/docs/modules/file-lifecycle.md)，后端配置见 [file-storage](../gorge/docs/modules/file-storage.md)。

不再提供“把新写入切回 MySQL、删除 Gorge URI”的回滚配方：存量 Gorge 文件需要该端点及原卷读取。停用、迁移或删除旧读取器前，用 retirement/integrity 审计证明没有历史引用并完成停写验收，见 [运维说明](scripts/operations/README.md)。不要删卷或墓碑来消除积压。

### Webhook、队列与 worker

Webhook 与 taskqueue 的 PHP 消费者已退役，owner=phorge 或 disable 不会恢复消费；迁移任务拒绝 disable。不可让旧 PHP 与 Go 同时取任务。Webhook 的签名按完整 JSON 字节计算，检查队列积压与真实受控接收端；不能仅凭 HTTP stats 判定投递成功。

worker 在依赖首次握手通过前不领取任务。Web bootstrap 只等待静态 meta，daemon 再等待 worker ready；迁移角色不能等待依赖其 schema 的 worker ready。Feed、通知、邮件、文件和其他任务的 native/delegated 边界见 [worker](../gorge/docs/modules/worker.md)、[taskqueue](../gorge/docs/modules/taskqueue.md)、[scheduler](../gorge/docs/modules/scheduler.md) 与后文。旧格式任务必须先处理，不能靠清队列或改 task ID 升级。

停机先停止领取，默认继续处理和续租 30 秒（`GORGE_WORKER_DRAIN_TIMEOUT_SEC`）；
基础编排和生产覆盖使用 45 秒 `stop_grace_period`。到期执行以最新同 owner 的有效
token 尝试永久归档，业务结果仍为未确认，需人工核对；队列不可达时不能保证归档。
增加 drain 时须同步增加 Compose 等待时间，至少另留 15 秒给归档与进程退出。
关停语义见 [Worker](../gorge/docs/modules/worker.md)，不能把容器退出当作业务完成。

### 数据库诊断

默认 `gorge-db-api:8080` 提供只读 schema、节点和迁移诊断。PHP 原生诊断回退已经移除，缺少 URI、能力或 namespace 不匹配是部署错误；`GORGE_DB_MODE=disable` 不能恢复旧实现。
基础开发编排默认复用 Phorge 普通数据库凭据，这个账号具有写权限；API 只执行读取
不代表数据库权限已隔离。生产覆盖强制独立 `GORGE_DB_MYSQL_USER/PASS`，账号授权
不会由 `db-init` 自动创建。按 [生产切换](PRODUCTION-CUTOVER.md) 创建最小权限账号，
再执行 `preflight.py --check-db-grants` 检查实际选中的 user@host 和角色。

多节点用独立只读 JSON 描述 `cluster.databases` 与 `storage.default-namespace`，通过 override 挂载到 `GORGE_DB_CONFIG_FILE`；密码单独注入 `GORGE_DB_MYSQL_PASS`。不要将含全部应用密钥的 `local.json` 用作默认挂载。文件无效时启动失败，不静默退回单节点。

诊断账号需能读取目标 namespace 的 schema 元信息及 meta_data 的 patch_status/hoststate，查看复制状态还需 REPLICATION CLIENT。INFORMATION_SCHEMA 可见性取决于目标对象权限，不能照抄对系统库的 GRANT 作为完整授权方案；按实际 MySQL/MariaDB 部署验证最小只读权限。配置、路由和错误码见 [db-api](../gorge/docs/modules/dbapi.md)。

### Gitea、图片与 integrations

`gitea` profile 提供签名 webhook 到任务评论的单向桥，使用独立最小权限 bot；SSO 和身份映射由既有系统维护。新安装 auto 使用 collaboration，已有安装保持 full；选择和 Gitea URI 由部署配置持久化。该桥当前只支持单副本，见 [gitea](../gorge/docs/modules/gitea.md)。

图片为可选覆盖配置，默认 legacy 或 shadow；生产切换须验收真实样本。thumbnail、Meme 和 builtin composition 各有独立模式，GD 仍用于未迁移能力。见 [image](../gorge/docs/modules/image.md) 与后文。

入站邮件、SMS、外部连接器与 Fact 接入不由核心默认栈自动启用，配置、幂等身份与 unknown 核对见 [integrations](../gorge/docs/modules/integrations.md)。

## 自定义编排

镜像角色为 migrate、web、daemon，没有 all 或纯 PHP legacy 栈。自定义 docker run/编排必须提供同版本的必要 Gorge 服务、共享持久配置卷和相同启动门禁。迁移先完成 schema 与 deployment 配置，再启动只读 Web/daemon；不要删除 deployment.json 试图恢复已退役的消费者。

基础镜像、依赖和标签以 [Dockerfile](Dockerfile)、[docker-compose.yml](docker-compose.yml)、[.env.example](.env.example) 为准。精确版本配对与交付证据见“独立 Docker 交付验收”。

### Gorge worker 执行协议 v1 升级

新版 `worker.execute` 在 PHP 内独立校验 `X-Service-Token`。启用委派执行时，必须设置非空 `GORGE_CONDUIT_TOKEN`，并与 gateway 和 Go worker 的 conduit token 保持一致；空 token 将拒绝执行任务。新版 PHP、gorge-taskqueue、gorge-worker 需要配套升级。升级期间暂停队列消费者，先更新 PHP 和 taskqueue，再更新 worker，只恢复 Go worker。不要因此停掉仍承担 trigger 或 fact 的其他 PHP 守护进程；本分支已退役仓库拉取 daemon。回滚应同步回滚配套组件。

新版成功执行会返回全部子任务，由 taskqueue 一次提交父任务归档和子任务入队。Feed HTTP 新任务使用完整投递快照，旧 key/uri 任务仍保留兼容处理。PHP 业务副作用在响应丢失或进程崩溃时仍可能重试，需要业务本身保持幂等。

可使用 `php tests/contract/worker/execution.php` 检查 PHP 执行协议、子任务、重试策略及认证。

## Gorge 生命周期与旧实现退役（2026-10）

升级顺序：先运行 `bin/storage upgrade` 创建 `worker_gorgeinbox` 和
`feed_gorgeoutbox`，再升级 Gorge queue，最后升级 Gorge worker 与 PHP。
新 Worker 要求 queue meta 的 `executionVersion=1` 和 `leaseOutcomes=true`。
不能用旧 complete/fail/yield 接口回退处理新 Worker 的结果。

Feed 发布现在在 feed 数据库事务内写 outbox，不再在请求中调用远程队列。
Bundled compose 的 `GORGE_WORKER_OUTBOX_DSN` 默认指向 `${namespace}_feed`；
外部部署必须配置同样的 relay，并监控 `deliveredEpoch IS NULL` 的积压与
`attempts/lastError`。尚未完成的 outbox 事件和 queue inbox 回执都不能提前
清理；接收幂等保证不等于外部 HTTP 请求只执行一次。

原生 Feed 的执行政策来自共享 conf 卷的 `feed-policy.json`，由 migrate
角色生成，Worker 每次投递重新读取。`feed.http-hooks` 和
`phabricator.silent` 现在属于 deployment 配置：已有实例必须先把 DB-only
值迁入 local.json，再生成 deployment.json；随后通过配置发布变更。
旧 key 格式任务由 PHP 转为快照任务，不再从 PHP 直接发送 HTTP。

SMTP/sendmail/SES/SendGrid/Mailgun/Postmark 的 PHP 出站发送实现已移除，
统一使用 `cluster.mailers` 的 `type:gorge`。供应商账号与后端优先级移到
Gorge；保留入站供应商条目时显式设 `outbound:false`。Twilio/SNS 等短信
介质不在本次邮件退役范围内。重复 PHP 高亮算法已移除，特殊 Remarkup、
console、rainbow 等展示逻辑仍保留，未配置 Gorge 时只提供纯文本恢复路径。

PHP Elasticsearch 客户端已退役。先在 Gorge 配置后端，重建替代索引并
验证查询，再切换 `cluster.search` 到 `type:gorge`。不会自动删除旧索引。
Ferret 同时服务领域查询索引，尚未整体退役，不能删除其表和扩展。

文件旧引擎保留只读兼容。先执行 `bin/files migrate --engine gorge --all --copy --dry-run`，再执行复制迁移；迁移增加新内容读回完整性校验，验证通过前
不更新旧 handle。最终执行 `bin/files integrity --all` 并核验 chunk-member 文件。
本次不会操作部署实例的历史文件或删除旧字节。

只读检查：`php scripts/setup/audit_gorge_retirement.php`。其 SQL 队列统计
不能替代 Redis 队列排空检查；配置切换也不能替代索引重建验收。
当前应用退役分支已经移除仓库拉取 daemon，并从标准启动列表移除 PHP
Taskmaster；保留 Trigger（定时事件）和 Fact（统计构建），它们仍有实际
领域职责。迁移它们之前，不应把整个 PHP daemon 运行时删掉。


### 图片变换灰度

新增独立 Gorge image 服务（8190），默认仍为 `gorge.image.mode=legacy`。
在 `.env` 配置非空 `GORGE_IMAGE_TOKEN` 后，可用
`docker compose -f docker-compose.yml -f docker-compose.image.yml up -d --build`
接入本地构建。overlay 默认 shadow，10% 按源 PHID 稳定采样，仅旧结果持久化；
正式切换设 `GORGE_IMAGE_MODE=gorge` 并重新运行 migrate 配置生成。
新服务启动校验 JPEG/PNG/GIF/WebP 编解码能力。临时错误不写派生关系；
显式重生成失败保留旧文件，成功后才替换关系并销毁旧结果。

原 URL、secret key、已有派生文件和旧存储读取不变。gorge 模式上传尺寸探测也
使用 Go；图片输入限制16MiB，画布50,135,040像素，动画100帧且累计像素同样受限。
该动画预算比旧实现更严格，切换前需要核验部署样本。JPEG质量、重采样与GIF
调色板视觉差异仍需 shadow 验收，不应仅凭尺寸测试切换全量。
头像/图标/Meme 另有可选 Gorge 配方及独立模式；保留 legacy 路径与 SpriteSheet 等能力仍需 GD，不能直接删除扩展。
回滚设置GORGE_IMAGE_MODE=legacy并重新生成配置；已生成图片仍可读取。

### 实时通知 Worker 原生迁移

升级通知相关 PHP 与 Gorge worker 后，共享 conf 卷会生成
`notification-policy.json`，Bundled worker 默认使用 `native`。也可以先设置
`GORGE_WORKER_NOTIFICATION_MODE=shadow` 验证任务与政策；shadow 只校验，实际
发送仍由 PHP 执行。`delegated` 可恢复 PHP 执行，`native` 不调用 PHP prepare/execute。
已有 DB-only `notification.servers`、`cluster.instance` 与 notification 服务政策
必须先导入 local.json；部署文件是原生 Worker 和 PHP 的共同配置来源。

所有消费者都支持新版本后，再设置 `GORGE_NOTIFICATION_OUTBOX=true` 并运行
migrate 配置角色。此开关默认 false。新通知与 Feed 事件在同一个 feed 数据库
事务写入现有 outbox；新 Feed 父任务不再创建通知子任务，旧父任务继续兼容。
未配置 Gorge queue 时仍可直接写入 SQL 队列，但 PHP taskmaster 已退役；这不构成可工作的生产消费路径，部署必须配置 Gorge taskqueue 与 worker。回滚时关闭新事件生产，
继续消费已提交事件；不要删除 outbox、inbox 或未完成任务。

通知 admin HTTP 成功只表示服务接受消息；节点转发、浏览器接收与跨重启恢复
没有新增持久保证。required 无 endpoint 的历史 no-op 行为保持不变并输出告警。
统计可在受认证保护的 `/api/worker/notification-stats` 查看。PHP 通知 Worker
仍保留作为回滚入口，ServerRef 和管理 UI 不退役。

通知拆分范围：本次原生 Worker 处理 Feed 的 `type:notification`。Conpherence
消息 (`message`) 和 Maniphest 看板刷新 (`workboards`) 仍从 PHP 直接投递；
迁移这些入口前必须定义各自的事务/outbox 边界，不能删除 PHP 通知客户端。
关闭通知时 deployment 显式写空 servers，以覆盖旧 local/DB 配置；显式清空
`cluster.instance` 会恢复 default。切换配置时暂停消费者，待部署政策文件
全部生成后再恢复，避免 PHP 与 Go 读取不同配置版本。

## 原生邮件投递编排 v1

默认 `GORGE_MAIL_DELIVERY_MODE=legacy`。新实现已经具备生产事件、准备任务、
原生提交、投递账本和独立结果投影；启用前仍需使用目标供应商做真实邮件验收。
没有供应商凭据的本地测试不能替代上线验收。

1. 暂停旧邮件消费者，更新 PHP、Gorge mailer/worker，运行
   `bin/storage upgrade` 创建 metamta outbox、delivery 和 attempt 表。
2. 配置 `GORGE_MAILER_DELIVERY_DSN` 与 `GORGE_WORKER_MAIL_OUTBOX_DSN`，两者均
   指向 `${PHORGE_DB_NAMESPACE}_metamta`；使用同一实例，不要共享多个实例的账本。
   配置 `GORGE_WORKER_MAILER_URL=http://gorge-mailer:8110` 和非空
   `GORGE_MAILER_TOKEN`，Worker 沿用同一 token。Conduit token 也必须非空且一致。
   Worker 挂载的 `feed-policy.json` 同时提供邮件的全局 silent 开关。
3. 先保持 legacy 生产，验证更新后的消费者、schema、mailer 和结果投影可用。
   再设置 migrate 角色的 `GORGE_MAIL_DELIVERY_MODE=native` 并重新生成 deployment。
   只有之后新建的 email 进入新路径；短信和旧邮件仍走兼容 Worker。
4. 在测试邮件中核验 To/CC、订阅偏好、线程头、附件、静默开关和邮件详情状态。
   Native 重试冻结快照；发送前复核收件人决策，变化时取消尚未提交的旧快照。
5. 历史 queued 邮件先预览：
   `php scripts/setup/migrate_gorge_mail.php --limit 100`；核对后加 `--apply`。
   用输出的 `next after-id` 配合 `--after-id` 继续分页。
   认领与旧 Worker 共用锁，旧 sendNow 拒绝 Gorge-owned 邮件。
   24 小时截止时间从原邮件创建时间计算，陈旧邮件会过期，不能盲目补发。

回滚将生产模式改回 legacy，仅影响未来新邮件。已经由 Gorge 认领的邮件继续
由原路径排空或核对；不要清除 owner、快照或账本后交回旧发送器。
`sent` 表示供应商接受；`unknown` 表示可能已被接受，必须核对后再决定是否
创建新的邮件。取消接口仅允许 prepared/retry_wait，提交中不能撤回。

监控 `metamta_gorgeoutbox.deliveredEpoch IS NULL`、
`gorge_mail_delivery.projectionPending=1`、unknown、retry_wait 和 expired 数量。
投影使用单调 revision；旧回执不能把 sent 改回 queued。
默认 native 并发为 4，可用 `GORGE_MAILER_DELIVERY_CONCURRENCY` 调整到 1..64。
未配置原生 handler 的新版 Worker 会延后邮件任务，不会委派给不存在的 PHP 类。

本阶段保留旧邮件兼容入口。供应商结果查询、账户级限流、大附件引用及显式
重发 generation 管理尚未覆盖，不应通过删除旧记录来模拟重发。

邮件恢复补丁 `20261006.metamta.03.gorgerecovery.sql` 也必须执行。它从已有快照
回填 deadline，并增加投影退避和恢复索引。Mailer 独立扫描悬挂提交和过期快照，
即使原队列任务已取消也会落盘 unknown/expired，恢复扫描不会发送邮件。
投影失败按记录退避，新增 `projectionAttempts/projectionNextAttempt/projectionLastError`
用于排查。Worker readiness 会核验原生协议与投影 schema。
新快照固定 PHP 选中的 mailerURI；Worker 配置不同的服务地址时停止准备，避免
邮件进入错误后端。PHP adapter key 与 Go provider key 分别保留。

原生邮件可通过带服务 token 的 `GET /api/mailer/delivery?deliveryID=...` 只读查询。
接口返回状态与 revision、供应商回执、deadline、最近 12 次尝试和投影退避信息，
不返回正文、收件人或附件，也不会触发发送。查询使用同一只读事务，超时为 5 秒；
记录不存在返回 404，数据库暂不可用返回 503。排查 unknown 时先核对尝试时间与
供应商日志；排查 accepted 且 projectionPending 为 true 时检查 PHP Conduit 回写，
不要通过重发邮件来修复投影。示例：

```sh
curl --get "$GORGE_WORKER_MAILER_URL/api/mailer/delivery" \
  -H "X-Service-Token: $GORGE_WORKER_MAILER_TOKEN" \
  --data-urlencode 'deliveryID=mail/PHID-MAIL-example/1'
```

### 搜索投影 outbox（shadow 捕获）

先运行 `bin/storage upgrade` 创建 `search_gorgeprojection` 和
`search_gorgeoutbox`。`gorge.search.projection-shadow` 默认关闭；显式开启后，
Gorge 搜索适配器在原同步投递之前，原子保存快照事件和每对象递增版本。
相同快照重试复用原事件；不能提前清理最后一条 outbox 回执。

此开关只启用 PHP 影子捕获，不自动启用 Go relay、提升 generation 或切换生产查询。Go 持久投影和重建控制器已有独立配置与验收入口，见 Gorge 的 search-projection 模块文档；默认生产查询和同步写入保持现有路径。outbox 捕获失败会让 SearchWorker 重试，不把索引版本标记
为完成。新事务只覆盖 search 数据库，不能代替各业务库事务中的变更捕获。
删除捕获已接入经销毁引擎处理的 Lisk 全文对象，具体恢复边界见后文“搜索删除意图与恢复”；`search.export` 的 missing 不能作为删除事件。

真实 MySQL 契约：`GORGE_TEST_MYSQL_PORT=3306 GORGE_TEST_MYSQL_PASSWORD=test php tests/contract/search/outbox.php`。
仅在临时测试实例执行；脚本会创建并删除随机命名的测试数据库。

## Gorge 日志与缓存清理

首批清理模块为可选 `maintenance` profile，默认未启动、未移交执行权。
覆盖通用/Markup/TTL 缓存、Conduit 日志、daemon 事件与锁日志，以及 Differential
解析缓存、浏览状态和 Multimeter 事件（共九项）。锁日志默认
仍无限保留，认证、任务归档、文件销毁及 outbox/inbox 不在此范围。

先执行 `bin/storage upgrade` 创建 cache/conduit/daemon/differential/multimeter 库的
`gorge_gc_control`，再部署本版 PHP。在部署环境设置 `GORGE_CLEANUP_GUARD=true` 并重新生成 deployment 配置，让所有 Web、CLI、daemon 节点加载；只有未受部署源管理的源码安装才使用 `bin/config set phd.gorge-cleanup true`。重启常驻 PHP daemon，确认旧版本进程和在途清理已退出。保护必须同时覆盖 Trigger 和 `bin/garbage collect`；
控制表缺失时，本版登记清理器失败关闭；关闭确认开关不会绕过控制行保护。

导出真实有效配置（不要手工复制默认 TTL）：

```sh
php scripts/setup/export_gorge_cleanup.php > cleanup.json
```

为 `gorge-maintenance` 配置 cache/conduit/daemon/differential/multimeter 五个目标主库 DSN 和非空服务 token；账号只授予
登记表 SELECT/DELETE、控制表 SELECT/INSERT/UPDATE。禁止使用读副本或把
控制行放到独立主库。启用 profile 只启动服务，import 不接管执行权。
CLI 运行时沿用同一环境，可将导出文件只读挂载到容器。使用 Compose 的导入示例：

```sh
docker compose --profile maintenance run --rm \
  -v "$PWD/cleanup.json:/tmp/cleanup.json:ro" \
  gorge-maintenance import /tmp/cleanup.json
```

二进制 CLI 的逐项切换命令：

```sh
gorge-maintenance import cleanup.json
gorge-maintenance dry-run cache.general.ttl
gorge-maintenance pause cache.general.ttl
gorge-maintenance resume cache.general.ttl gorge
gorge-maintenance run-once cache.general.ttl
```

一次只切换一个 collector，先 TTL 缓存，再年龄缓存和日志。仅配置一个
角色时，将导出的 policies 过滤到该角色完整登记项，保留 version、guardEnabled
与 readOnly；服务 readiness 要求配置角色的全部策略已导入。跨库 import
并非全局事务；检查逐项输出，失败后重试，已导入策略不会自动切为 Go。

策略调整时先 pause，重新导出/import，再 resume。进入 Phorge 只读维护前
也先 pause 已移交项。回滚为 pause、等待事务完成、resume 到 php，再恢复
PHP 调度；已删数据不能靠切换执行权恢复。PHP 清理 SQL 暂留作灰度回滚，
完成部署数据验收后再删除。执行边界和预算见 Gorge 的 maintenance 模块文档。

### 搜索删除意图与恢复

启用 `gorge.search.projection-shadow` 前，先在全部 PHP 节点部署代码并运行
`bin/storage upgrade`，创建 `search_gorgedeletion`。经销毁引擎处理的 Lisk
全文对象，会先持久化删除意图，再删除源对象。Trigger 自动恢复未完成意图；
也可运行 `php scripts/setup/recover_gorge_search_deletions.php` 做最多 32 项的
恢复批次。该命令会发布搜索 tombstone，不是只读检查，不会删除业务对象。
源对象仍存在、源查询失败或源类已退役时不发布删除。

这是搜索 shadow 的恢复边界，不能替代生产重建与切换验收。直接 SQL 删除、
非 Lisk 对象及外层事务中的销毁尚未覆盖；已开启捕获的外层事务销毁会拒绝
执行，应先拆清提交边界。关闭 shadow 暂停自动恢复，不删除历史意图。

## 独立 Docker 交付验收

宿主机需要 Docker Compose、Python 3 和两个源码 checkout；默认 Gorge 路径是本仓库
相邻的 `../gorge`，其他布局必须传 `--gorge-dir`。PHP、Go、MySQL、Redis、真实 S3、
render/image、Elasticsearch 8 与 Meilisearch 服务全部在容器内。入口复用 paired
acceptance，包括真实 PHP HTTP、文件迁移、中断恢复和全部 Go race 测试；命名的 Go
测试被 skip 也会失败。它不等于浏览器登录后的全业务、供应商投递或生产性能验收。

```bash
python3 deploy/acceptance/accept.py --gorge-dir ../gorge --check
GOPROXY=https://goproxy.cn \
ALPINE_MIRROR=https://mirrors.tuna.tsinghua.edu.cn/alpine \
python3 deploy/acceptance/accept.py --gorge-dir ../gorge --output /tmp/gorge-delivery-result
```

每次使用随机 Compose project，不读取应用 `.env`，不暴露宿主端口，不复用业务卷；
数据库和 S3 使用临时存储，成功或失败都清理测试容器。输出目录不可复用已存在的
manifest。若强制杀死宿主入口，按输出 manifest 的 project 手工清理遗留测试栈。

`result.json` 保存每阶段状态，`paired-acceptance.json` 保存配对故障验收身份，
`delivery-manifest.json` 保存 PHP/Go commit、dirty、源码摘要、构建锁、实际镜像 ID
和已知 registry digest。构建失败同样保存交付 manifest 与 fixture 日志，不报通过。
不把配置、DSN、token 或业务载荷写入 manifest。测试凭据仅用于隔离环境。

`deploy/acceptance/build-lock.json` 锁定基础/后端镜像 digest、APCu 版本
及 Debian 包快照；内置运行库的来源和内容摘要由 `support/runtime/manifest.json` 记录。当前摘要来自本机所用镜像；首次在另一架构使用前验证其平台支持。
Phorge Dockerfile 接受 `PHP_BASE_IMAGE`、`APCU_VERSION`、
`DEBIAN_SNAPSHOT`；Gorge Dockerfile 接受 `GO_BASE_IMAGE`、`RUNTIME_BASE_IMAGE`。
普通开发构建仍允许默认镜像标签；冻结构建必须使用这份锁和验收入口。

`--check` 只校验运行库身份、Compose 隔离拓扑与镜像锁，不运行契约或证明镜像可用。
完整执行需要 Docker daemon 可用、足够的后端内存与磁盘，以及已缓存或可访问的
镜像仓库、系统包仓库、Go modules 和解析器构建依赖。`--candidate-images` 还需完整
十四服务 digest map：全部检查包装和源码标签，真实 render/image fixture 使用候选
digest；其余业务 Go 验收仍运行配对源码，不能宣称十四个生产服务均已端到端运行。
直接运行 `tests/contract/worker/acceptance.py` 则需宿主 PHP/Go/OpenSSL、显式测试
MySQL、真实 S3 与 image fixture 和 `GORGE_TEST_*` 参数；优先使用上面的隔离入口。

此锁提高依赖可追溯性，不承诺字节级重建一致：Alpine APK 仓库仍可能更新，构建器和
时间戳也会改变镜像 ID。交付已验收产物时应发布并使用最终镜像 digest，保留配对
manifest；不要重新构建后继续沿用旧验收结果。


## 内置 PHP 兼容运行库

当前 Docker 与完整配对契约的 PHP 基线是 8.3。CI 的 `runtime-compatibility`
矩阵另在 8.4/8.5 检查保留运行库、维护工具和响应安全；这些版本仍是运行库候选，
尚不等于全应用、迁移或生产工作负载已支持。升级基线前须重新完成整套配对验收和
有代表性的业务验证。维护流程见 [运行库说明](support/runtime/README.md)。

Phorge 的 PHP 基础运行库位于 `support/runtime/`，随本仓库源码和镜像交付。
构建、网页、管理命令和契约测试都不需要相邻的 Arcanist checkout。内部库注册名
`arcanist` 和原有 PHP 类名暂时保留，以兼容动态类发现和共享 diff 数据模型；
这不代表仍需要安装或执行 `arc` 客户端。

在 Phorge 仓库中运行：

```sh
php scripts/runtime/verify.php
php scripts/runtime/build.php all
php bin/rebuild-library-map
php bin/unit --no-coverage src/infrastructure/cluster/__tests__/PhabricatorGorgeDBContractTestCase.php
php tests/contract/runtime/compatibility.php
php tests/contract/runtime/diff.php
php tests/contract/runtime/integrity.php
php tests/contract/runtime/maintenance.php
php tests/contract/runtime/products.php
php tests/contract/worker/execution.php
```

映射重建覆盖内置运行库和 Phorge，PHP 单元测试继续执行实际测试和失败退出码。
按整个模块选择测试时，也会运行建立数据库 fixture 的祖先测试；应先配置独立测试
数据库，再运行 `bin/unit --no-coverage src/infrastructure/cluster/`。镜像构建已准备
两个解析工具，网页和 CLI 请求不会触发下载或编译。
产品渲染契约 `products.php` 需要显式设置 `GORGE_TEST_RENDER_URL` 和
`GORGE_TEST_RENDER_TOKEN`，指向独立的 Gorge render 测试服务；完整隔离验收入口
`deploy/acceptance/accept.py` 会自动构建、启动并清理这个服务。
依赖来源、源码快照摘要与本项目补丁记录在 `support/runtime/manifest.json`；
派生运行库的内容摘要和上游来源分别记录，不把派生包冒充上游原始提交。
运行库更新需要同步审查资源、动态实现与工具支持，并重新执行隔离验收。

macOS 私有 CA 的 OpenSSL/SecureTransport 兼容修复由内置运行库维护，验收不再
复制外部 checkout 或临时替换 PHP 源码。共享文件比较、Jupyter 差异、国际化、
PHP AST 页面、翻译提取和仍保留的后台任务均在兼容范围内。

`bin/i18n validate --extract` 严格检查当前源字符串的格式和翻译分支；历史功能的
未引用词条继续保留，可用 `bin/i18n validate --unused` 单独审计。所有库的提取
缓存统一写入 Phorge 的 `src/.cache/i18n/`，删除源文件也会刷新缓存，内置运行库
的受管理文件不会被提取过程修改。

解除 Arcanist 下载不表示整个构建离线：基础镜像、系统包、Go modules 和单独
管理的 PHP-Parser 等依赖仍需按其构建流程准备。回退使用已验证的旧镜像和配对
版本，不在运行时自动搜索外部 Arcanist；本轮不改变数据库 schema 或业务协议。

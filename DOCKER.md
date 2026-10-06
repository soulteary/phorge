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
必须部署包含这些协议与探针的 Gorge 镜像；Phorge 的 `docker compose up --build`
不会构建使用 `image:` 的 Gorge 服务。旧镜像缺少 capability 时会明确阻止启动。

## 快速启动

```bash
# 1) 准备环境变量（可选，用于改密码 / 端口 / 域名；不改也能跑）
cp .env.example .env
# 编辑 .env，设置非空 GORGE_CONDUIT_TOKEN 和匹配本次协议的 Gorge 镜像标签。
# token 可以用 openssl rand -hex 32 生成；PHP、gateway、worker 共用这个值。

# 2) 构建 Phorge，并启动默认核心服务
docker compose up -d --build

# 3) 浏览器访问（Host 必须含点号，用 127.0.0.1 而不是 localhost）
#    默认地址：http://127.0.0.1:8088/
```

首次启动会自动完成：

- 构建应用镜像（拉取 arcanist、编译 PHP 扩展）。
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
  worker 与 db-api。Gorge 镜像默认锁定 `2026.09.09-r3`，不会跟随 `latest` 漂移。

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
| `PHORGE_PRODUCT_PROFILE` | `auto` | `auto` 让新安装使用协作模式、已有安装保持 full；也可显式指定 `collaboration` 或 `full`。结果持久化在只读部署配置中。 |
| `PHORGE_GORGE_POLICY` | `required` | 已配置 Gorge 服务的失败策略：`required` 直接暴露错误；`fallback` 迁移期允许旧实现并记录 `[gorge-fallback]` 日志；`off` 停用请求路由型服务。 |
| `PHORGE_DB_NAMESPACE` | `phabricator` | Phorge、db-init 与 file-storage/webhook/taskqueue/db-api 的唯一数据库前缀。已有自定义 `storage.default-namespace` 的安装升级前必须设为相同值；不一致时 entrypoint 会拒绝启动。 |
| `GORGE_IMAGE_TAG` | `2026.09.09-r3` | 默认栈所有 Gorge 镜像的版本锁；可用各服务的 `*_IMAGE_TAG` 单独覆盖。 |
| `PHORGE_WAIT_DB` | `1` | `migrate` 角色是否在执行 `storage upgrade` 前等待数据库就绪。严格取值 `1` 开启，其它任何值视为关闭。关掉首启动 `storage upgrade` 大概率失败。 |
| `PHORGE_AUTO_UPGRADE` | `1` | `migrate` 角色是否自动执行 `bin/storage upgrade --force`。只有 `migrate` 读它，`web` / `daemon` 永不迁移 schema。 |
| `PHORGE_CONTAINER_ROLE` | `web` | 容器角色：`migrate`（写 `local.json`、迁移 schema、发布 `deployment.json`）、`web`、`daemon`。`web` / `daemon` 是只读消费者，缺少这两个文件时直接退出。单容器 `all` 角色已移除。 |

> 注意：`MYSQL_*` 与 `PHORGE_BASE_URI` / `PHORGE_TIMEZONE` **只在首次生成
> `conf/local/local.json` 时写入**。该文件由 `phorge-conf` 卷持久化，之后改 `.env`
> 不会覆盖它。需要按新环境变量重新生成，先删掉再重启：
> `docker compose exec phorge rm conf/local/local.json && docker compose up -d --force-recreate phorge-migrate phorge phorge-daemon`，
> 或直接在 Web 界面 Config 里改。

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

Gorge 的九个接入域由 `PhabricatorGorgeServiceRegistry` 统一登记。默认控制面不再逐项
调用 `bin/config set`，也不再用 `collaboration-profile-state.json` 长期维护应用和字段
的三方合并；从阶段一升级时若检测到旧状态文件，会先恢复原始管理员配置，再一次性迁移。

`notification.servers` 是这次迁移里唯一可能被清掉的管理员可见配置。旧控制面把 Gorge
的两条记录直接写进 `local.json`，快照（`gorge-notification-state.json`）是后来才加的，
所以最早那批安装没有可恢复的原值，而不带通知选择器的迁移又不会覆盖这一项。迁移因此会
识别 Gorge 写出的那个固定结构（两条记录：`admin` 固定 `http`，其后一条 `client`，没有
多余字段）并移除它，同时在 stderr 上打印被移除的值。这个结构也可能与管理员自建的
Aphlict 配置相同，取舍是明确的：清掉是“通知需要重新配置”并且有警告，留下则是悄悄指向
一台即将下线的主机。看到该警告后按后文的 Aphlict/通知章节重新配置即可。

后文保留每个 Gorge 服务的逐项说明。其中提到 entrypoint 用 `bin/config set` 写
`local.json` 的描述是旧版兼容控制面的行为，已随 `PHORGE_CONTROL_PLANE=legacy`
一并移除（显式传 `legacy` 会 exit 64）；现在这些端点由 `phorge-migrate` 一次性写进
上述 `deployment.json`，服务协议与环境变量含义不变。

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
`gorge-gitea` 在 Gorge r3 之后才合入，启用 `gitea` profile 前必须设置
`GORGE_GITEA_IMAGE_TAG` 为实际已经发布的构建；默认 `unreleased` 用来阻止误拉 r3。
不再提供「不接入 Gorge」的 Compose 入口。`docker-compose.legacy.yml` 已经删除，
默认栈是唯一受支持的编排；需要按域取舍时，用 `PHORGE_GORGE_POLICY` 与各服务的
`GORGE_*_MODE` 变量控制，而不是切回旧编排。仍在宿主机上裸跑 Gorge 的联调场景，
用 `docker-compose.host-gorge.yml` 叠加默认文件（见该文件顶部说明）。

### 不用 Compose 直接 docker run

镜像只有 `migrate` / `web` / `daemon` 三个角色（`PHORGE_CONTAINER_ROLE`，默认 `web`），
旧的单容器 `all` 角色已随 legacy 控制面一起移除。`web` 与 `daemon` 是配置的**只读消费者**：
它们在 `conf/local/local.json` 或 `conf/local/deployment.json` 缺失时直接退出，不会自己
迁移 schema。所以直接 `docker run` 也必须按 migrate → web → daemon 的顺序来，三个容器
共享同一个 `conf/local` 卷：

```bash
docker build -t phorge:local .
docker network create phorge-net
docker volume create phorge-conf
docker volume create phorge-repo
docker volume create phorge-db

# 0) 数据库自备（这里用官方镜像示意）
#    phorge-db 卷不能省：不挂它的话 /var/lib/mysql 只存在于容器可写层，
#    删掉或重建 phorge-mysql 就会连同整个站点的数据一起消失（配置和仓库在
#    另外两个卷里，反而还在，于是故障看起来像「数据库空了」而不是「卷没挂」）。
#    默认栈在 docker-compose.mysql.yml 里挂的就是这个路径。
docker run -d --name phorge-mysql --network phorge-net \
  -v phorge-db:/var/lib/mysql \
  -e MYSQL_ROOT_PASSWORD=phorge_root -e MYSQL_DATABASE=phorge \
  -e MYSQL_USER=phorge -e MYSQL_PASSWORD=phorge \
  mysql:8.0.46 --sql-mode=STRICT_ALL_TABLES --local-infile=0 --ft-min-word-len=3

# 1) 授权：等同于默认栈里的 db-init 一次性任务，这一步不能省。
#    官方镜像的 MYSQL_USER 只对 MYSQL_DATABASE 一个库有权限，而 Phorge 用的是
#    {namespace}_xxx 一整组库，少了这步 migrate 会在 storage upgrade 阶段直接
#    报 ERROR 1044 (Access denied)，deployment.json 根本不会发布。
#    占位符与 db-init 用的是同一份 docker/db-grant.sql，替换规则也一致；
#    PHORGE_DB_NAMESPACE 用了非默认值时，两处要同时改。
until docker exec phorge-mysql \
  mysqladmin ping -h localhost -uroot -pphorge_root --silent; do sleep 2; done
sed -e 's/__PHORGE_USER__/phorge/g' \
    -e 's/__PHORGE_NAMESPACE__/phabricator/g' \
    docker/db-grant.sql |
  docker exec -i phorge-mysql mysql -uroot -pphorge_root

# 2) migrate：写 local.json、跑 storage upgrade、原子发布 deployment.json
#    它是一次性任务，跑完就退出；--rm 之后配置留在 phorge-conf 卷里。
#
#    ⚠️ 本配方假定 phorge-conf 是**全新**卷。如果你复用的是之前跑过 Gorge 默认栈
#    的那个卷，先删掉里面的 deployment.json 再执行这一步：
#      docker run --rm -v phorge-conf:/conf alpine \
#        rm -f /conf/deployment.json
#    原因：build_deployment_config.php 是在**现有** deployment.json 的基础上增量
#    重建的，选择器变量缺失的服务一律按 preserve 保留。本配方不传任何 GORGE_*，
#    所以 gorge.taskqueue.owner=gorge、phd.taskmasters=0、webhook 所有权以及各个
#    gorge.*.uri 都会原封不动留下来。Gorge 容器已经停了，结果就是队列和 webhook
#    没有消费者、required 策略下的调用打向已经不存在的主机。
#    不要试图用 GORGE_*_MODE=disable 回滚：webhook 与 taskqueue 的 disable 已经
#    不再受支持（migrate 会直接报错退出），删掉 deployment.json 是唯一的重置方式。
#    PHORGE_PRODUCT_PROFILE 显式写 full：默认的 auto 在空库上会解析成
#    collaboration，那会停用 Diffusion 代码托管应用，
#    并预期由 GITEA_BASE_URI 指向一个外部代码托管。这个不带 Gorge 的最小示例
#    两者都没有，用 full 才能拿到一个自带代码托管的完整站点。真要跑协作模式，
#    就把这一行改成 collaboration 并同时补 -e GITEA_BASE_URI=https://git.example.com/。
docker run --rm --network phorge-net \
  -v phorge-conf:/opt/phorge/phorge/conf/local \
  -v phorge-repo:/var/repo \
  -e PHORGE_CONTAINER_ROLE=migrate \
  -e PHORGE_AUTO_UPGRADE=1 \
  -e PHORGE_PRODUCT_PROFILE=full \
  -e MYSQL_HOST=phorge-mysql -e MYSQL_USER=phorge -e MYSQL_PASS=phorge \
  -e PHORGE_BASE_URI=http://127.0.0.1:8088/ \
  phorge:local /bin/true

# 3) web：Apache，只读加载上一步的配置
docker run -d --name phorge-web --network phorge-net -p 8088:80 \
  -v phorge-conf:/opt/phorge/phorge/conf/local \
  -v phorge-repo:/var/repo \
  -e PHORGE_CONTAINER_ROLE=web \
  phorge:local

# 4) daemon：phd，同样只读加载配置
docker run -d --name phorge-daemon --network phorge-net \
  -v phorge-conf:/opt/phorge/phorge/conf/local \
  -v phorge-repo:/var/repo \
  -e PHORGE_CONTAINER_ROLE=daemon \
  phorge:local /usr/local/bin/phd-foreground
```

要点：

- 升级同样是「先跑一次 migrate，再重建 web/daemon」。`PHORGE_AUTO_UPGRADE` 只有
  `migrate` 角色会读，`web` / `daemon` 永远不迁移 schema。升级时数据库已经授权过，
  第 1 步可以跳过；它本身是幂等的，重复执行也没有副作用。
- 改用非默认命名空间时，授权步骤里的 `__PHORGE_NAMESPACE__` 与 migrate 容器的
  `-e PHORGE_DB_NAMESPACE=` 必须填成同一个值，否则授权覆盖不到实际用的那组库，
  `storage upgrade` 仍然会 ERROR 1044。
- `phd` 由 `daemon` 角色的 `phd-foreground` 固定托管，没有「是否随容器启动 phd」的开关。
- 接 Gorge 时，`GORGE_*` 端点变量要传给 **migrate** 容器：它们由 migrate 一次性写进
  `deployment.json`，web 与 daemon 只读加载。传给 web 不会生效。
- `PHORGE_CONTROL_PLANE` 只接受 `deployment`；显式传 `legacy` 会直接退出（exit 64），
  不会静默退回已经删除的旧控制面。

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
  # docker compose up -d --build phorge
  ```
  另外，新增 PHP 类还需要先在宿主上 `arc liberate src/` 重新生成
  `src/__phutil_library_map__.php` —— 类映射没更新，重建了镜像也一样 `Class not found`。

  `docker/entrypoint.sh` 也一样被烤进镜像，但它出问题时**一声不吭**：镜像里若是旧版
  entrypoint，新增的下发逻辑（比如 `notification.servers`、`cluster.mailers`、
  `cluster.search`）整段不存在，
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
Pygments，所以其余文件在 Diffusion 里都是无色的。叠加编排文件
`docker-compose.gorge.yml` 会起一个 `gorge-render` 服务（Go + Chroma），Phorge 把高亮
请求发给它，覆盖面与 Pygments 相当，但不用在镜像里塞一套 Python 运行时。
同一个进程还承载 `/api/diff/*`：可以替换系统 `diff -U65535` 子进程与 PHP 的 prose
difference engine。高亮与 diff 分别启用，可以独立回滚。

镜像 `ghcr.io/soulteary/gorge:render-latest` 由 Gorge 仓库的 release 工作流推送，
容器内固定监听 `8140`，路由 `/api/highlight/*` 与 `/api/diff/*`。拉不到时，本地构建一条
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
docker compose up -d --build

# 2) 把高亮引擎切到 Gorge
docker compose exec phorge /opt/phorge/phorge/bin/config set \
  syntax-highlighter.engine PhabricatorGorgeSyntaxHighlighterEngine

# 3) 清掉已缓存的渲染结果（不能省，原因见下）
docker compose exec phorge /opt/phorge/phorge/bin/cache purge --all
```

> **第 3 步不能省**：Phorge 缓存的是**高亮之后的 HTML**，不是源码。diff 的 changeset
> 存在自己的缓存表，其余渲染结果存在 `cache_general` 表，都不会因为换了引擎
> 而失效。只切引擎不清缓存，已经看过的 diff 会继续吐旧 HTML，很容易误判成「配置没生
> 效」，转头去反复折腾 URI 和 token。只想清相关的两项可以用
> `bin/cache purge --caches general,changeset`。切换之后**新建**的 diff 不受影响，它们
> 本来就会走新引擎。

### 用 Gorge 生成 diff

diff **没有开关**。原生的 GNU `diff -U65535` 子进程与 PHP prose 实现都已删除，
`PhabricatorDifferenceEngine` 的 unified diff 与 `PhutilProseDifferenceEngine` 的
prose diff 一律调用 `/api/diff/generate` 和 `/api/diff/prose`。请求沿用
`gorge.render.uri` / `gorge.render.token`，不再增加一组重复的地址和密钥配置。

因此与高亮不同，这里没有「先起服务再切引擎」两步：配好 `gorge.render.uri`、服务健康，
diff 就已经走 Gorge 了。

同样因为没有本地实现可退，服务不可达、超时、返回错误或响应不能无损还原输入时，Phorge
会**直接抛异常**，而不是记一条日志再悄悄换一套算法。编排把服务端的
`GORGE_RENDER_ENABLE_DIFF` 写死为 `true`（见 `docker-compose.gorge.yml`），`.env` 里
覆盖不掉——服务端不注册这两条路由就等于整个站点算不出 diff。

两个历史开关已经退役，设置它们不再有任何效果：

| 开关 | 现状 |
|------|------|
| `gorge.diff.enabled`（Phorge 配置项） | 已隐藏并锁定。旧值保留在配置里不报错，但不再被读取。 |
| `GORGE_RENDER_ENABLE_DIFF`（编排环境变量） | 编排写死 `true`，`.env` 里的残留值被忽略。 |

排障时确认服务端确实注册了 diff 路由：

```bash
docker compose exec gorge-render \
  wget -qO- --post-data '{"old":"a\n","new":"b\n"}' \
  --header 'Content-Type: application/json' \
  http://127.0.0.1:8140/api/diff/generate
```

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
| `GORGE_IMAGE_TAG` | `latest` | Gorge 服务的公共镜像标签。 |
| `GORGE_RENDER_IMAGE_TAG` | 空（继承 `GORGE_IMAGE_TAG`） | `ghcr.io/soulteary/gorge` 的 render 标签后缀；实际标签为 `render-<值>`，生产可单独钉住本服务。 |
| `GORGE_RENDER_MAX_BYTES` | `1048576` | 单次高亮的源码大小上限（字节）。超限返回 413，该文件退化为无高亮。 |
| `GORGE_DIFF_MAX_BYTES` | `1048576` | 单次 diff 的 `old` 与 `new` 合计大小上限（字节）。超限时 Phorge 记录错误并回退到本地实现。 |
| `GORGE_RENDER_TIMEOUT_SEC` | `15` | 单次高亮的服务端超时（秒）。 |

### 本地构建 gorge-render 镜像

两种情况都需要自己构建，用的是同一条命令：

- **`ghcr.io/soulteary/gorge:render-*` 拉不下来。** 尚未发布对应标签时镜像不存在。
  注意 ghcr 对「不存在」和「无权访问」返回的是同一个
  `403 Forbidden`，所以别按报错去折腾 `docker login`——先本地构建。
- **你 fork 了 Gorge、改了 Go 代码**，想让 Phorge 用自己的构建而不是上游镜像。

```bash
# 构建上下文是 gorge 仓库的 go/ 子目录，不是仓库根目录：Dockerfile 在 go/ 下，
# 一份 Dockerfile 覆盖 go/cmd 下的所有二进制，由 SERVICE 构建参数挑一个。
docker build -t ghcr.io/soulteary/gorge:render-latest \
  --build-arg SERVICE=gorge-render \
  /path/to/gorge/go

# 确认镜像已在本地
docker images ghcr.io/soulteary/gorge
```

把 `/path/to/gorge/go` 换成你机器上 Gorge 仓库的 `go/` 目录。**Gorge 是独立仓库，不在本仓库
里**，所以这个路径取决于你 clone 到了哪儿；如果两个仓库是并列的兄弟目录，在 `phorge-fork/`
下就是 `../gorge/go`。构建耗时约 20 秒（Go 静态编译 + alpine 运行层）。

关键是 `-t` 打出的名字要和编排里 `image:` 的完全一致，这样**不用改任何编排文件**：默认是
`ghcr.io/soulteary/gorge:render-latest`；若你设置了 `GORGE_RENDER_IMAGE_TAG`（或公共的
`GORGE_IMAGE_TAG`），`-t` 的标签也要跟着改（例如 `:render-2026.09.08-r5`）。构建完镜像
就在本地，`docker compose ... up -d` 直接拿来用、不会再去拉；
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
- **高亮服务挂了会怎样**：客户端抛 `PhutilSyntaxHighlighterException`，Diffusion 的 diff
  页面显示高亮失败提示，其余位置回退到无高亮渲染，页面本身不会 500。故障是可见的，不是静默的。
  **但 diff 没有这层兜底**：同一个进程挂掉时，raw 与 prose diff 直接失败（原生实现已
  移除）。所以 `gorge-render` 对本部署是必需服务，不是可选增强。
- **日志里出现 404 `ERR_NOT_FOUND`**：几乎总是 `gorge.render.uri` 结尾多了一个斜杠。
- **401 `ERR_UNAUTHORIZED`**：两端 token 不一致。注意只改 `.env` 里的
  `GORGE_RENDER_TOKEN` 后必须重启**两个**容器，否则一端还拿着旧值。

## 用 Gorge 做实时通知（可选）

Phorge 的实时通知（页面右上角的小铃铛即时亮起、Conpherence 消息实时到达）依赖一个
叫 **Aphlict** 的独立通知服务器，上游使用 Node.js。本发行版已删除该服务端和进程管理命令，
镜像也不包含 Node.js。

`docker-compose.gorge.yml` 里的 `gorge-notification` 服务是它的替代品：Go 实现，与 Aphlict
**线兼容**——一样的路径、一样的报文格式、一样不做鉴权。正因为线兼容，接入不需要改任何 PHP
代码，只是编排加上一段配置下发。镜像 `ghcr.io/soulteary/gorge:notification-*` 与 `gorge-render`
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
docker compose up -d --build
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
  up -d --build
```

### 本地构建镜像

与 `gorge-render` 是同一个 Dockerfile、同一个上下文，只是换一个 `SERVICE` 构建参数：

```bash
docker build -t ghcr.io/soulteary/gorge:notification-latest \
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
`ghcr.io/soulteary/gorge:mailer-*` 与另外两个服务同源同标签（共用 `GORGE_IMAGE_TAG`），拉不到
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
#    --profile mailer 不能省：gorge-mailer 与写配置的 phorge-mailer-config 都挂在
#    mailer profile 上，不带它 up 只会起默认核心服务，下面第 3 步的 exec 会直接找不到
#    容器，配置里的 mailer 也一直是 disable。
docker compose --profile mailer up -d --build

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
docker build -t ghcr.io/soulteary/gorge:mailer-latest \
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

## 用 Gorge 做全文搜索（可选）

Phorge 默认的全文检索走 MySQL（`cluster.search` 里那条 `type: mysql` 的条目，实现是
`PhabricatorFerretFulltextStorageEngine`）。它开箱可用，但对中文基本无效：分词按空格
和标点切，一整句中文会被当成一个词。上游的解法是配一个 Elasticsearch 集群，用
`PhabricatorElasticFulltextStorageEngine` 直连——那个引擎把 mapping 与查询 DSL 全写在
PHP 里，改一个分析器就要改 PHP 代码。

`docker-compose.gorge.yml` 里的 `gorge-search` 服务换一种分法：Go 服务持有
Elasticsearch / Meilisearch 客户端、mapping 与查询构造（含**内置的 CJK 分析链**），
Phorge 侧只剩一个薄引擎把文档与查询序列化成 HTTP 请求。Phorge 这边新增的是三个类，走
的是原生扩展点，没有改任何核心代码：

- [`PhabricatorGorgeFulltextStorageEngine`](src/applications/search/fulltextstorage/PhabricatorGorgeFulltextStorageEngine.php)
  —— `cluster.search` 里 `type: "gorge"` 对应的引擎；
- [`PhabricatorGorgeSearchHost`](src/infrastructure/cluster/search/PhabricatorGorgeSearchHost.php)
  —— 条目里 `hosts` 每一项对应的主机，也负责 Config 页面上那一行状态；
- [`PhabricatorGorgeSearchClient`](src/infrastructure/cluster/PhabricatorGorgeSearchClient.php)
  —— HTTP 客户端，与高亮、发信两个客户端共用
  [`PhabricatorGorgeServiceClient`](src/infrastructure/cluster/PhabricatorGorgeServiceClient.php)
  的请求构建与 `{data, error}` 信封解析。

镜像 `ghcr.io/soulteary/gorge:search-*` 与另外三个服务同源同标签（共用 `GORGE_IMAGE_TAG`），
容器内固定监听 `8120`，路由 `/api/search/*`。拉不到时本地构建一条命令即可。

### 三件和前三个服务都不同的事

**一、后端实例要你自己提供。** 这个编排只起 `gorge-search`，**不起** Elasticsearch。ES 的
内存、磁盘、数据卷与版本升级都得按自己的规模定，塞一个默认配置进来只会给出一个不该用
在生产上的默认值。所以 `.env` 里必须把 `ES_HOST`（或 `MEILI_HOST`）指向你已有的实例。
和发信一样，只填上半组变量时容器起得来、`/healthz` 返回 200、Compose 报 `healthy`，然后
每一次检索都失败——这个状态由
[`PhabricatorGorgeSearchSetupCheck`](src/applications/config/check/PhabricatorGorgeSearchSetupCheck.php)
探 `/readyz` 在 Config 页面报出来。

```text
/healthz   进程活着、HTTP 栈在服务        -> 容器健康检查探它
/readyz    至少有一个可读的搜索后端        -> setup check 探它
```

叠加文件里的 `healthcheck` 因此刻意探 `/healthz`：`phorge` 对它的 `depends_on` 用的是
`service_healthy`，若探 `/readyz`，一个还没配后端的 `gorge-search` 会把整个站点堵在启动
阶段，而「搜不了」不该等同于「站点起不来」。（Gorge 仓库自己的 `deploy/compose` 里探的
是 `/readyz`，那边没有别的服务依赖它。）

**二、必须手工建一次索引。** 配置写进去不等于能搜。第一次启用要跑：

```bash
docker compose exec phorge /opt/phorge/phorge/bin/search init
docker compose exec phorge /opt/phorge/phorge/bin/search index --all --force
```

`init` 创建索引与 mapping（已存在则先删再建），`index --all --force` 把现有对象全部灌进
去。不跑 `index` 的表现是「搜什么都没有结果」，但页面不报错——索引是空的，不是坏的。
大库上这一步是**小时级**操作，期间检索结果不完整，建议挑低峰期。

**`bin/search ngrams` 在这个引擎下不适用。** 那条命令属于 MySQL/Ferret 那一侧
（`PhabricatorSearchNgrams` / `PhabricatorFerretEngine`），是给 MySQL 全文检索补中文能力
的。切到 gorge 引擎之后中文检索靠的是服务端的 CJK 分析链，跟 ngrams 没有关系；旧文档里
「跑 ngrams 启用中文搜索」那套说法在这里是误导。

**三、`cluster.search` 是共享列表，但没有 `key` 字段。** `cluster.mailers` 的每个条目有
`key`，所以 `entrypoint.sh` 能精确地只覆盖自己那一条。`cluster.search` 的条目只有
`type` / `hosts` / `roles` / `port` / `protocol` / `path` / `version` 这几个键（校验用的是
一张固定表，多写一个键会被拒绝），于是这里托管的是**所有** `type: gorge` 的条目。想手工
再配一条指向另一个 gorge 实例的条目，请把 `GORGE_SEARCH_HOST` 留空、整段自己配。其他类型
的条目（比如你手工配的 `elasticsearch`）连相对顺序一起原样保留。

gorge 条目是**插在列表最前面**的，不是追加。读检索走
[`PhabricatorSearchService::newResultSet()`](src/infrastructure/cluster/search/PhabricatorSearchService.php)，
它按列表顺序取第一个可读且成功的服务；`mysql` 条目只要还可读，追加就等于 gorge 永远不
被读到，症状是「配置全绿、服务健康，但中文检索没有任何变化」。

### mysql 那条留不留

`cluster.search` 的**默认值本身**就是一条 `type: mysql`。这个默认值不在任何配置源里，
所以 `bin/config get cluster.search` 在没写过的机器上会显示 `local` 与 `database` 都
`unset`——一旦本地源被写上任何值，那条默认条目就整条消失。

默认（`GORGE_SEARCH_KEEP_MYSQL=0`）就是这个效果：写进去的只有 gorge 一条，MySQL 全文检索
不再被读到。

`GORGE_SEARCH_KEEP_MYSQL=1` 时列表变成 `[gorge, mysql]`：

- 两条都可写 ⇒ **双写双索引**（不过 Ferret 索引本来就由一个扩展始终维护，与
  `cluster.search` 无关，所以这一半几乎没有额外开销）；
- 读按顺序优先走 gorge，**gorge 报错时回落到 MySQL**。

这个开关是给回滚用的，两个方向都幂等：从 `1` 翻回 `0` 会把 mysql 条目摘掉，从 `0` 翻回
`1` 会把它补上，不依赖它此刻是否还在列表里。代价是开着的时候「这一次检索到底由谁回答」
多了一层不确定性——gorge 偶发失败时用户会拿到一份质量不同的结果，而页面上看不出来。

### 用叠加文件启动

```bash
# 1) 在 .env 里指一个搜索后端（这一步不能省，原因见上）
cat >> .env <<'EOF'
ES_HOST=es.example.com:9200
ES_VERSION=7
EOF

# 2) 起服务（拉镜像失败 403 见「本地构建镜像」，命令与前三个服务相同，
#    只是 --build-arg SERVICE=gorge-search；--build 同样不能省）
#    --profile search 同 mailer：gorge-search 与 phorge-search-config 都在 search
#    profile 上，不带它这一步等于没起服务。
docker compose --profile search up -d --build

# 3) 确认配置写进去了，且服务已就绪
docker compose exec phorge /opt/phorge/phorge/bin/config get cluster.search
docker compose exec gorge-search wget -qO- http://127.0.0.1:8120/readyz

# 4) 建索引并全量灌一次（第一次必须做，大库上是小时级操作）
docker compose exec phorge /opt/phorge/phorge/bin/search init
docker compose exec phorge /opt/phorge/phorge/bin/search index --all --force
```

写进去的条目长这样：

```json
[
  {
    "type": "gorge",
    "hosts": [
      {
        "host": "gorge-search",
        "port": 8120,
        "protocol": "http",
        "roles": {"read": true, "write": true}
      }
    ]
  }
]
```

几个细节值得留意：

- **只放一个 gorge 条目、读写都给它。** Phorge 的 `cluster.search` 有 read/write 角色与多
  条目，`gorge-search` 内部的 `backends` 也有 read/write 角色与主机健康表——这两层语义是
  重叠的。扇出与 failover 全部交给服务端一层做，否则下一个人会在两层里各配一半，而两层
  的失败表现完全不同。
- **`port` 是 JSON 整数**，写成字符串 `"8120"` 会被校验直接判非法，所以那段 JSON 由
  `php -r` 的 `json_encode` 生成而不是拼字符串。
- **token 不在条目里，走全局隐藏配置项 `gorge.search.token`。** 这不是「两层配置」：端点
  只有一个家（条目的 `hosts`），密钥只有一个家（这个配置项）。之所以分开，是因为条目的
  键表是固定的，没有地方放 token。指向多个服务的多个条目只能共用同一个 token。
- **`path` 不是索引名。** `cluster.search` 的 `path` 在 Elasticsearch 引擎里表示索引名，
  在这里表示「服务挂在反代的哪个路径前缀下」，通常留空。索引名在服务端配（`ES_INDEX`）。

相关可选变量（上半组都有默认值，下半组没有，全部见 `.env.example`）：

| 变量 | 默认值 | 说明 |
|------|--------|------|
| `GORGE_SEARCH_HOST` | `gorge-search` | Phorge 访问检索服务的主机名，同时是整段配置的总开关（留空即整段跳过）。 |
| `GORGE_SEARCH_PORT` | `8120` | 端口。与容器内监听端口绑定，改了要同时改叠加文件里的 `environment` 与 `healthcheck`。 |
| `GORGE_SEARCH_PROTOCOL` | `http` | `http` 或 `https`。走 compose 内网时保持默认。 |
| `GORGE_SEARCH_TOKEN` | 空 | 服务间共享密钥，请求头 `X-Service-Token`，两端必须一致。留空表示不鉴权；能往这个口投请求就等于能读写整个索引。 |
| `GORGE_SEARCH_KEEP_MYSQL` | `0` | 取 `1` 时在 `cluster.search` 里保留一条 `mysql` 条目作为双写与读回退，理由见上。 |
| `ES_HOST` | 空 | Elasticsearch 地址，可逗号分隔多台。**留空则 `/readyz` 不通。** |
| `ES_INDEX` / `ES_VERSION` / `ES_PROTOCOL` / `ES_TIMEOUT` | 空（服务端默认 `phabricator` / `5` / `http` / `15`） | 索引名、主版本号、协议与超时。 |
| `MEILI_HOST` / `MEILI_INDEX` / `MEILI_MASTER_KEY` / `MEILI_PROTOCOL` / `MEILI_TIMEOUT` | 空 | 换用 Meilisearch 时填这一组，并把 `GORGE_SEARCH_ENGINE` 设为 `meilisearch`。 |
| `GORGE_SEARCH_BACKENDS` | 空 | 一次配多个后端用的 JSON 列表（单行）。配了它，`ES_*` / `MEILI_*` 那一组整体不生效。 |

### 本地构建镜像

与另外三个服务是同一个 Dockerfile、同一个上下文，只换 `SERVICE` 构建参数：

```bash
docker build -t ghcr.io/soulteary/gorge:search-latest \
  --build-arg SERVICE=gorge-search \
  --build-arg PORT=8120 \
  /path/to/gorge/go
```

`PORT` 只喂给镜像**内置**的那条 HEALTHCHECK，不影响服务实际监听哪个口（那个由
`GORGE_LISTEN_ADDR` 决定）。它的默认值是 gorge-render 的 8140，漏了这个参数，镜像自带的
探针就会一直去敲并不存在的 8140，`docker run` 直接跑这个镜像会显示 unhealthy。本编排里
看不出来——上面的 service 段显式写了自己的 healthcheck，正好盖掉它。

同样地，`-t` 打出的标签要和编排里 `image:` 完全一致；设了 `GORGE_IMAGE_TAG` 就跟着改。

### 验证与排障

```bash
# 界面上：Config → Cluster → Search Servers 应该出现一行 "Gorge Search"，状态 Okay。
# 那一行的状态列打的是 /api/search/sane，所以 mapping 不匹配时它会显示 Failed 而不是
# 假装正常。

# 服务端认得哪些后端（要带 token，留空时可省掉 --header）
docker compose exec gorge-search wget -qO- \
  --header="X-Service-Token: ${GORGE_SEARCH_TOKEN}" \
  http://127.0.0.1:8120/api/search/backends

# 索引存在吗 / mapping 还对得上吗
docker compose exec gorge-search wget -qO- \
  --header="X-Service-Token: ${GORGE_SEARCH_TOKEN}" \
  http://127.0.0.1:8120/api/search/exists
```

- **Config 页面报 "Gorge Search Service Not Ready"**：服务活着但一个搜索后端都没配，或
  地址填错。issue 正文里带着服务端给出的原因。注意服务**刻意不拨测**后端来回答
  `/readyz`——它报的是「配了」而不是「连得上」，一个写错的 ES 地址在这里仍然显示 ready，
  到查询时才失败。
- **Config 页面报 "Gorge Search Service Unreachable"**：容器没起来，或 `GORGE_SEARCH_HOST`
  指向了这台服务器够不着的地址。注意在 `phorge` 容器里 `127.0.0.1` 指的是 phorge 自己。
- **搜索页面报 "All of the configured Fulltext Search services failed"**：请求到了服务但
  被拒绝或后端出错。日志里的 `error.code` 说得比状态码清楚：`ERR_SEARCH_FAILED` 是后端
  报错（ES 连不上、索引不存在），`ERR_UNAUTHORIZED` 是两端 token 不一致，
  `ERR_BAD_REQUEST` 是请求本身不合法（不该出现，出现了就是 bug）。
- **搜什么都没有结果，但不报错**：索引是空的，`bin/search index --all --force` 没跑过或
  没跑完。
- **Search Servers 那一行显示 Failed，`bin/search init` 又说索引存在**：mapping 与服务端
  现在会生成的那一套不一致，`indexIsSane()` 返回了 false。这是**升级 gorge-search 之后
  的正常现象**——分析器改动（比如加上 CJK 子字段）会让所有既有索引都判为 not sane。修法
  是重跑 `bin/search init` + `bin/search index --all --force`。这一条会明确报错而不是静默
  退化，是刻意的：CJK 子字段不见了的话中文检索会悄悄变差，报错比不报好。
- **`bin/config set cluster.search` 报搜索引擎类型 `gorge` 未知**：类映射没重新生成，
  `src/__phutil_library_map__.php` 里缺 `PhabricatorGorgeFulltextStorageEngine`。改过 PHP
  源码后别忘了 `docker compose ... up -d --build`——源码是烤进镜像的。
- **自己手工配的 elasticsearch 条目不见了**：不该发生，entrypoint 只剔除 `type: gorge`
  与（默认情况下）`type: mysql` 的条目。真遇到的话看启动日志里
  `[entrypoint] 下发 Gorge 检索配置` 那几行，读取失败时它会告警并整段跳过。

### 回滚

```bash
# 1) 停掉下发：把 GORGE_SEARCH_HOST 从 .env 里清空（或整段删掉），
#    这样 entrypoint.sh 下次启动就不再动 cluster.search。
#    注意：清空它**不会**把已经写进 local.json 的条目撤掉，得手工设回去。

# 2) 手工把 cluster.search 设回只有 MySQL 一条
echo '[{"type":"mysql","roles":{"read":true,"write":true}}]' | \
  docker compose exec -T phorge /opt/phorge/phorge/bin/config set cluster.search --stdin

# 3) 确认生效
docker compose exec phorge /opt/phorge/phorge/bin/config get cluster.search
```

MySQL 全文检索不需要重建索引就能立刻回来：Ferret 索引由一个扩展始终维护，与
`cluster.search` 配了什么无关。反过来，之后再切回 gorge 也不必重跑 `index --all`，只要
这期间没有对象被编辑过——被编辑过的那些在 gorge 那边就是旧的，稳妥起见还是重跑一次。

想留一条随时可切的回退路径而不是每次手工改配置，用 `GORGE_SEARCH_KEEP_MYSQL=1`（见上）。

## 用 Gorge 做文件存储（可选）

Phorge 把上传的文件（附件、头像、粘贴的图片、Diffusion 里的二进制 blob）交给一个
**存储引擎**，引擎决定字节实际落在哪儿。自带的三个是 MySQL blob（`type: blob`，
默认只收 1MB 以内）、本地磁盘（默认关闭）和 Amazon S3（`PhabricatorS3FileStorageEngine`，
把 AWS 请求构造全写在 PHP 里）。默认状态下所有文件都挤在 MySQL 里，超过
`storage.mysql-engine.max-size`（默认 1,000,000 字节）的上传直接失败——另外两个引擎都
没配，没人肯收，而分块引擎也帮不上：它要求候选引擎至少能收下一个 4MB 的块。

`docker-compose.gorge.yml` 里的 `gorge-file-storage` 服务换一种分法：Go 服务持有三个
后端（MySQL blob / 本地磁盘 / S3-兼容对象存储）、按 priority 排序并在写入失败时依次
下沉，Phorge 侧只剩一个薄引擎把字节送上 HTTP。Phorge 这边新增的是三个类，走的是原生
扩展点，没有改任何核心代码：

- [`PhabricatorGorgeFileStorageEngine`](src/applications/files/engine/PhabricatorGorgeFileStorageEngine.php)
  —— 存储引擎本体，`identifier` 是 `gorge`、`priority` 是 `2`；
- [`PhabricatorGorgeFileStorageClient`](src/infrastructure/cluster/PhabricatorGorgeFileStorageClient.php)
  —— HTTP 客户端，与另外三个客户端共用
  [`PhabricatorGorgeServiceClient`](src/infrastructure/cluster/PhabricatorGorgeServiceClient.php)
  的请求构建与 `{data, error}` 信封解析；
- [`PhabricatorGorgeFileStorageSetupCheck`](src/applications/config/check/PhabricatorGorgeFileStorageSetupCheck.php)
  —— Config 页面上的三个 setup issue（不可达 / 没后端 / 配了但没接上）。

镜像 `ghcr.io/soulteary/gorge:file-storage-*` 与另外五个服务同源同标签（共用
`GORGE_IMAGE_TAG`），容器内固定监听 `8100`，路由 `/api/file/*`。拉不到时本地构建一条
命令即可。

### 新写入接管与历史文件读取

`GORGE_FILE_URI` 在迁移阶段写入 `deployment.json`，新文件由 Gorge 存储。
历史 `blob`、`local-disk` 和 `amazon-s3` 引擎已经拒绝写入，继续支持读取和删除。
不要清空 `storage.local-disk.path`、`storage.s3.bucket` 或旧凭据；历史文件依赖这些配置。
文件记录上的 `storageEngine` / `storageHandle` 决定使用哪个读取引擎。

需要删除旧读取实现时，先用 `bin/files migrate --engine gorge --all --copy --dry-run`
检查迁移范围，再执行复制迁移，并用 `bin/files integrity --all` 验证。
仅改变配置不能证明历史文件已经迁移。Gorge 不可用时不会恢复旧写入。

### 为什么这个引擎刻意保留 8MB 上限

`PhabricatorFileStorageEngine` 的基类默认 `getFilesizeLimit()` 是 8MB，本引擎**刻意不
覆盖**它。声明「无上限」看着更像是进步——服务端确实是流式写盘 / 流式传 S3，不需要把整个
文件读进内存——但那样会静默关掉 Phorge 自己的分块能力：
[`PhabricatorChunkedFileStorageEngine`](src/applications/files/engine/PhabricatorChunkedFileStorageEngine.php)
只在**没有**任何非分块引擎肯收这个文件时才接手，一个什么都收的引擎意味着 2GB 的上传会
变成一个 2GB 的请求体。代价是可续传上传、两侧有界的内存占用，以及服务端一个有界的
请求体上限（`16M`）。

保留默认值之后，大文件由分块引擎切成 4MB 一块、每块再走这个引擎存进去，服务端照样持有
全部数据，只是一次一个有界请求。8MB 这个数字不是随便取的：分块引擎要求候选引擎的
`getFilesizeLimit()` 不小于它的 4MB 块大小，所以往上调是安全的，调到 4MB 以下会让这个
引擎失去存放分块的资格。

> 旧的 `phorge/src/applications/files/engine/PhabricatorGoFileStorageEngine.php` 把
> `hasFilesizeLimit()` 写成了 `false`，正是上面说的那个反面例子。

### 用叠加文件启动

```bash
# 1) 起服务（拉镜像失败 403 见「本地构建镜像」，命令与前四个服务相同，
#    只是 --build-arg SERVICE=gorge-file-storage；--build 同样不能省）
#    这一步不需要先配后端：本地磁盘后端默认就开着，数据落在新增的 phorge-files 卷里。
docker compose up -d --build

# 2) 确认配置写进去了，且服务已就绪
docker compose exec phorge /opt/phorge/phorge/bin/config get gorge.file.uri
docker compose exec gorge-file-storage wget -qO- http://127.0.0.1:8100/readyz

# 3) 确认只有 Gorge 接受新文件，历史引擎的 Writable 为 No。
#    不需要修改历史存储路径或凭据。
docker compose exec phorge /opt/phorge/phorge/bin/files engines

# 4) 从 Web UI 上传一个小于 8MB 的文件和一个大于 8MB 的文件，在文件详情页（/F123）
#    看 "Storage Engine" 那一行：前者应该是 gorge，后者应该是 chunks（它的每一块
#    再落到 gorge）。
```

叠加文件做了三件事：

- 新增 `gorge-file-storage` 服务，**不声明 `ports`**：它只被 `phorge` 通过 Compose 默认
  网络的服务名调用。这里比另外几个更要紧——不鉴权时它是一个能读、写、删站点全部文件的
  开放接口，而读一个文件只需要一个 handle。
- 新增 `phorge-files` 卷挂到它的 `/var/gorge/files`，给本地磁盘后端用。**它装的是文件
  数据本体**，Phorge 侧只存 handle，所以 `docker compose down -v` 会把那些文件真的删掉。
- 给 `phorge` 补上 `depends_on: gorge-file-storage: service_healthy`，并注入
  `GORGE_FILE_URI` / `GORGE_FILE_TOKEN`。

它和 `gorge-webhook` 是六个 Gorge 服务里**有状态**的两个，也是仅有的两个 `depends_on`
`mysql` 与 `db-init` 的：blob 后端要连库，而 `db-init` 才是给普通账号补上
`phabricator_%` 整组库权限的那一步。这条依赖链不会成环——`phorge` 等它、它等 `mysql`、
`mysql` 不等任何人。（`gorge-webhook` 那边的依赖方向不同，见「用 Gorge 投递 Webhook」。）

它的容器健康检查也与 `gorge-mailer` / `gorge-search` 相反，探的是 `/readyz` 而不是
`/healthz`：

```text
/healthz   进程活着、HTTP 栈在服务
/readyz    至少一个后端已启用（blob 启用时还要能 Ping 通库）  -> 容器健康检查探它
```

两个理由。一是本服务**开箱就有一个可写后端**，所以「healthy 但一个后端都没配」这个会把
站点堵在启动阶段的状态默认不存在，而发信与检索必须你自己提供后端。二是存不了文件比发不
出信可见得多：上传、头像、粘贴图片全都当场失败。代价写在这儿：**如果你把三个后端全部
关掉，`phorge` 就会一直等在 `depends_on` 上**；真要这么做就把探针换成 `/healthz`，或者
把整段 `gorge-file-storage` 与 `phorge` 段里对应的 `depends_on` / 环境变量一起删掉。

`/readyz` 刻意**不检查** `file_storageblob` 表是否存在。那张表是 `phorge` 的
`bin/storage upgrade` 建的，而 `phorge` 又在 `depends_on` 上等这个探针——检查表就成了死锁。
所以全新数据卷首次启动时 blob 后端会短暂写不进去，此时写入按 priority 下沉到本地磁盘，
不会让上传整体失败。

几个细节值得留意：

- **字节不走 JSON 信封。** 写和读文件的请求体 / 响应体都是原始
  `application/octet-stream`；只有「写完返回的 handle」「删除结果」「引擎列表」仍是
  `{data, error}`。失败**永远**是信封，包括读取路由，所以 PHP 侧
  `PhabricatorGorgeServiceClient::parseBinaryResponse()` 是**按状态码分支**而不是按 body
  是否为空——0 字节文件的 200 空 body 是合法的，按 body 判断会让这些文件读不出来。
- **handle 是复合的。** 引擎存进 `file` 表的是 `engine/handle`（例如
  `local-disk/ab/cd/8f1e...`），因为后端是服务端在写入时挑的，读的时候必须再说一遍。
  按第一个斜杠切开：后端标识（`blob` / `local-disk` / `amazon-s3`）里没有斜杠，handle 里
  有。**这三个标识、handle 格式与 S3 的 key 前缀改了都是静默失效**——存量文件读不出来
  且不报错。
- **MIME 类型刻意不发给服务端。** 到达引擎的字节已经过了 storage format，加密过的文件
  并不是那个类型的数据。Phorge 自带的 S3 引擎也不设 Content-Type。
- **不指定 `engine`。** 让服务端按 priority 自己挑，并在写失败时下沉到下一个后端——这和
  `loadStorageEngines()` 在 PHP 侧做的是同一件事。从这边钉死一个后端，只会把一次可恢复
  的写入失败变成一次失败的上传。

相关可选变量（全部见 `.env.example`）：

| 变量 | 默认值 | 说明 |
|------|--------|------|
| `GORGE_FILE_URI` | `http://gorge-file-storage:8100` | Phorge 访问文件存储服务的基础地址，同时是整段配置的总开关（留空即整段跳过）。**结尾不要带斜杠**。 |
| `GORGE_FILE_TOKEN` | 空 | 服务间共享密钥，请求头 `X-Service-Token`，两端必须一致。留空表示不鉴权；能往这个口投请求就等于能读写删站点上的任意文件。 |
| `GORGE_FILE_NAMESPACE` | `phabricator` | blob 后端的库名前缀，**必须**与 Phorge 的 `storage.default-namespace` 一致。留空不等于用默认值（服务端默认是 `phorge`）。填错的表现是静默下沉到本地磁盘。 |
| `GORGE_FILE_MYSQL_BLOB_MAX_SIZE` | `1048576` | 单个 blob 的字节上限，超过就交给下一个后端。设 `0` 表示不启用 blob 后端。 |
| `GORGE_FILE_LOCAL_DISK_PATH` | `/var/gorge/files` | 本地磁盘后端在容器内的路径，对应 `phorge-files` 卷。留空表示不启用。 |
| `GORGE_FILE_S3_BUCKET` / `_S3_REGION` / `_S3_ENDPOINT` / `_S3_ACCESS_KEY` / `_S3_SECRET_KEY` | 空 | S3 / MinIO 后端，五项**全部**填齐才启用。 |

服务端也认无前缀的旧名（`STORAGE_NAMESPACE`、`LOCAL_DISK_PATH`、`S3_BUCKET` …），那是留给
独立服务时期旧部署的兜底。新部署一律用上面这组带前缀的名字：`.env` 是整套编排共用的，
无前缀的名字会和数据库那一节的 `MYSQL_*` 挤在同一个命名空间里。

MySQL 的连库参数**不在这一组里**：blob 后端的 `GORGE_FILE_MYSQL_USER` / `_PASS` 在叠加
文件里直接接到最上面「环境变量」那一节的 `MYSQL_USER` / `MYSQL_PASSWORD` 上，host 与 port
写成字面量 `mysql:3306`。不另开一组是刻意的——连库参数填错的表现不是报错，而是静默下沉到
本地磁盘，所以宁可让它与 `phorge` 自己用的那一份共享同一个来源。

### 本地构建镜像

与另外四个服务是同一个 Dockerfile、同一个上下文，只换 `SERVICE` 构建参数：

```bash
docker build -t ghcr.io/soulteary/gorge:file-storage-latest \
  --build-arg SERVICE=gorge-file-storage \
  --build-arg PORT=8100 \
  /path/to/gorge/go
```

`PORT` 只喂给镜像**内置**的那条 HEALTHCHECK，不影响服务实际监听哪个口（那个由
`GORGE_LISTEN_ADDR` 决定）。它的默认值是 gorge-render 的 8140，漏了这个参数，镜像自带的
探针就会一直去敲并不存在的 8140，`docker run` 直接跑这个镜像会显示 unhealthy。本编排里
看不出来——上面的 service 段显式写了自己的 healthcheck，正好盖掉它。

同样地，`-t` 打出的标签要和编排里 `image:` 完全一致；设了 `GORGE_IMAGE_TAG` 就跟着改。

### 验证与排障

```bash
# 界面上：Applications → Files → Storage Engines 是这一节最有用的一页。表按 priority
# 排序、可写的引擎高亮，所以「gorge 是不是第一个可写引擎」一眼就能看出来。
# 单个文件落在哪个引擎，看它的详情页 /F123 上的 "Storage Engine" 一行。

# 服务端认得哪些后端、各自的 priority 与大小上限（要带 token，留空时可省掉 --header）
docker compose exec gorge-file-storage wget -qO- \
  --header="X-Service-Token: ${GORGE_FILE_TOKEN}" \
  http://127.0.0.1:8100/api/file/engines

# Phorge 侧认得哪些引擎（顺序即 priority 顺序）
docker compose exec phorge /opt/phorge/phorge/bin/files engines

# 可选：把存量文件也搬过来。切换本身不需要这一步（见上），需要的是「想让老文件不再
# 依赖 MySQL / 老磁盘」的时候。先用 --dry-run 看清会动哪些。
docker compose exec phorge /opt/phorge/phorge/bin/files migrate \
  --engine gorge --all --dry-run
```

- **Config 页面报 "Gorge File Storage Service Not In Use"**：配了 URI 但原生引擎还排在
  前面，新文件按大小散落在两套引擎里。这不是故障，是启用只做了一半，做第二步即可（见上）。
- **Config 页面报 "Gorge File Storage Service Not Ready"**：服务活着但一个后端都没启用，
  或 blob 启用了却 Ping 不通库。issue 正文里带着服务端给出的原因。注意本编排里容器健康
  检查探的就是 `/readyz`，所以这条在 Compose 环境下更可能是在你手工关掉后端之后才出现。
- **Config 页面报 "Gorge File Storage Service Unreachable"**：容器没起来，或
  `GORGE_FILE_URI` 指向了这台服务器够不着的地址。注意在 `phorge` 容器里 `127.0.0.1` 指的
  是 phorge 自己。
- **上传大文件失败，但小文件正常**：先看 `/api/file/engines` 返回的 `sizeLimit`，再看
  服务端固定 `16M` 的请求体上限。正常路径下单个请求不会超过 ~8MB（大文件由分块引擎切成
  4MB 一块），所以撞上 `16M` 说明有人把这个引擎的 `getFilesizeLimit()` 调大了。
- **日志里出现 404 `ERR_NOT_FOUND`**：几乎总是 `GORGE_FILE_URI` 结尾多了一个斜杠。
- **401 `ERR_UNAUTHORIZED`**：两端 token 不一致。只改 `.env` 后必须重启**两个**容器。
- **503 `ERR_NO_ENGINE`**：服务端一个可写后端都没有，与 `/readyz` 报的是同一件事。
- **图片全变成破图 / 下载全部失败，但上传正常**：读不出来的是**存量**文件。在文件详情页
  看它的 "Storage Engine"——如果服务端改过后端标识、handle 格式或 S3 key 前缀，存量文件
  就是这个表现：读不出来且不报配置错误。
- **`bin/config set gorge.file.uri` 报 "Configuration key is unknown"**：类映射没重新
  生成，`src/__phutil_library_map__.php` 里缺新增的三个类。改过 PHP 源码后别忘了
  `docker compose ... up -d --build`——源码是烤进镜像的。启动日志里
  `[entrypoint] 下发 Gorge 文件存储配置` 那几行会打出具体原因。

**关于 MySQL blob 的双写，有一件事必须知道：** 服务端的 blob 后端写的是
`{GORGE_FILE_NAMESPACE}_file.file_storageblob`，和 Phorge 原生的
`PhabricatorMySQLFileStorageEngine` 是**同一张表**，同样用自增 id 当 handle。按上面第二步
关掉原生引擎之后不会真的并发双写（原生引擎已经不可写了），但两点仍然成立：

- `bin/storage` 的维护操作、`bin/remove destroy` 与垃圾回收仍然会碰这些行，它们不知道行
  是谁写的；
- 全新数据卷首次启动时，`bin/storage upgrade` 还没建出这张表，此时 blob 写入会失败并
  按 priority 下沉到本地磁盘。这是**刻意**的设计（服务端的 `/readyz` 因此不检查表是否
  存在，否则会与 `phorge` 的 `depends_on` 死锁），表现是最早那几个文件落在
  `local-disk` 而不是 `blob`，之后自动恢复正常，不需要处理。

### 回滚

```bash
# 1) 把原生引擎打开，让新文件重新落 MySQL
docker compose exec phorge /opt/phorge/phorge/bin/config set \
  storage.mysql-engine.max-size 1000000

# 2) 停掉下发：把 GORGE_FILE_URI 从 .env 里清空（或整段删掉），
#    这样 entrypoint.sh 下次启动就不再写 gorge.file.uri。
#    注意：清空它**不会**把已经写进 local.json 的值撤掉，得手工清。
docker compose exec phorge /opt/phorge/phorge/bin/config set gorge.file.uri null

# 3) 确认生效
docker compose exec phorge /opt/phorge/phorge/bin/config get gorge.file.uri
```

**但先想清楚第 2 步。** 清掉 `gorge.file.uri` 会让引擎不再可写，这正是回滚想要的；可是
它同时让引擎**读不出**已经落在服务里的文件——客户端构造时就会因为缺少配置抛异常。所以
只有在确认没有文件落在 `gorge` 引擎里（或者你不在乎它们）时才做第 2 步。想留一条随时可
切、又不丢读取能力的回退路径，只做第 1 步就够了：新文件回到 MySQL，存量的仍从服务里读。

同理，服务容器与 `phorge-files` 卷在这之后**不能删**，除非确认里面没有还被引用的文件。

## 用 Gorge 投递 Webhook（可选）

Herald 的 webhook 是这样跑的：事务编辑器命中规则后，往
`{namespace}_herald.herald_webhookrequest` 插一行 `status = queued`，再给 `phd` 的任务
队列派一个 `HeraldWebhookWorker`；worker 取出那一行，用 hook 自己的 HMAC key 给 payload
签名、`POST` 出去，把结果（`status` / `lastRequestResult` / `lastRequestEpoch` /
`properties.errorType|errorCode`）写回同一行。

`docker-compose.gorge.yml` 里的 `gorge-webhook` 服务把中间那一段换成 Go：**队列表就是
接口**，服务自己轮询 `status='queued'` 的行、抢占、签名投递、回写，phorge 一侧只负责继续
往表里插行。

这让它成为六个服务里唯一一个**不被 phorge 调用**的：

- 前五个服务，PHP 那边配的是「往哪儿发请求」；这里配的 `gorge.webhook.uri` 是「谁接管了
  投递」——一个开关，不是一个地址。PHP 侧真正会去访问这个地址的只有 Config 页面上的
  setup check。
- 因此**它可以在配置完全没写进 phorge 的情况下照样工作**。这不是好事，见下一节。

Phorge 这边新增的是三个类加一个索引，没有改任何核心逻辑，只在两处加了守卫：

- [`PhabricatorGorgeWebhookClient`](src/infrastructure/cluster/PhabricatorGorgeWebhookClient.php)
  —— 读配置、判断投递是否已交接（`isDeliveryDelegated()`），外加两个诊断接口；
- [`PhabricatorGorgeWebhookSetupCheck`](src/applications/config/check/PhabricatorGorgeWebhookSetupCheck.php)
  —— Config 页面上的三个 setup issue（静默模式挡着 / 不可达 / 连不上队列库）；
- [`PhabricatorHeraldConfigOptions`](src/applications/herald/config/PhabricatorHeraldConfigOptions.php)
  —— 新增的 Herald 配置组，声明 `gorge.webhook.uri` 与 `gorge.webhook.token`；
- `resources/sql/autopatches/20260907.herald.01.requeststatuskey.sql`
  —— 给队列表补一个 `key_status (status, id)` 索引，见下面「为什么要加一个索引」。

守卫在
[`HeraldWebhookRequest::queueCall()`](src/applications/herald/storage/HeraldWebhookRequest.php)
（不派任务）和
[`HeraldWebhookWorker::doWork()`](src/applications/herald/worker/HeraldWebhookWorker.php)
（已经派出去的任务跑到时直接返回）两处，条件都是同一个 `isDeliveryDelegated()`。

镜像 `ghcr.io/soulteary/gorge:webhook-*` 与另外五个服务同源同标签（共用 `GORGE_IMAGE_TAG`），
容器内固定监听 `8160`。

### 危险的方向和别的服务相反：配置没写进去会产生重复投递竞态

**这是接这个服务时唯一真正容易踩的地方，请读完再动手。**

文件存储那一节的坑是「配了不生效」——沉默、但无害。这里正好反过来：

| 状态 | 结果 |
|------|------|
| 服务在跑 + `gorge.webhook.uri` 已写入 | 只有 Go 投递。正确。 |
| 服务没跑 + `gorge.webhook.uri` 已写入 | 谁也不投，请求堆在 `queued` 里等服务回来。 |
| **服务在跑 + `gorge.webhook.uri` 没写入** | **`phd` 和 Go 竞争同一批行，部分请求可能重复投递。** |
| 服务没跑 + 没写入 | 原样，`phd` 投递。 |

第三行是唯一一个会造成外部可见错误的组合，而它恰恰是最容易出现的：`gorge.webhook.uri`
是新增的配置项，需要在 `PhabricatorHeraldConfigOptions` 里声明**并重新生成类映射**
（宿主上跑 `arc liberate src/`）。映射没跟上时 `bin/config set` 会报
`Configuration key is unknown`。默认栈不能在所有权不确定时继续启动。

为此 `entrypoint.sh` 在这一项写失败时会让迁移任务退出：

```
[entrypoint] 错误: gorge.webhook.uri 未能写入；拒绝在委派状态不确定时启动。
```

默认栈此时会阻止 Web 与 daemon 启动。应按上面说的重新生成类映射再重建镜像；历史
叠加编排则应先停掉 `gorge-webhook`，再清理 `gorge.webhook.uri`。

同理，`.env` 里的 `GORGE_WEBHOOK_URI` 和叠加文件里的 `gorge-webhook` 段是**一对**，
要停就两边一起停。

### 全局静默是一个例外：开着的时候这一项等于没配

守卫的条件是「`gorge.webhook.uri` 非空 **且** `phabricator.silent` 未开」，两个条件缺一
不可。

`phabricator.silent` 是 phorge 自己的配置，Go 服务读不到它——它能看到的只有每条 request
上记录的 per-request `silent` 标记，那描述的是「这一次事务是不是静默的」，不是「这个站点
是不是静默的」。所以开着静默还把投递让给 Go，webhook 会照常发出去，正好违反静默模式存在
的意义。

于是静默场景**继续走 PHP**：`HeraldWebhookWorker` 用 `ERROR_SILENT` 把每个请求标成
`failed`，而 Go 只取 `queued` 的行，自然碰不到它们。静默模式的行为与接这个服务之前完全
一致。

这个「配了但因为静默没接管」的状态由 `PhabricatorGorgeWebhookSetupCheck` 在 Config 页面
报成 **"Gorge Webhook Service Not In Use"**。关掉静默后投递会自己转到 Go，不需要别的操作。

### 为什么要加一个索引

`herald_webhookrequest` 原本只有 `key_phid`、`key_ratelimit`、`key_collect` 三个索引，
`status` 列上一个都没有——PHP 侧不需要，它是靠任务队列拿到 PHID 再按 PHID 查行的。Go 服务
换了个方向：它每秒扫一遍 `status='queued'`。而这张表的 `sent` 行由垃圾回收保留 7 天，会
持续累积，于是那一秒一次的轮询就是一次持续变大的全表扫。

新增的 autopatch 补上 `key_status (status, id)`：

```sql
ALTER TABLE {$NAMESPACE}_herald.herald_webhookrequest
  ADD KEY `key_status` (`status`, `id`);
```

`resources/sql/autopatches/` 按文件名字典序自动发现，所以 `bin/storage upgrade`（`phorge-migrate`
默认 `PHORGE_AUTO_UPGRADE=1`，每次起栈都跑）会自动应用它，不需要手工执行。

同一个索引也声明在
[`HeraldWebhookRequest::getConfiguration()`](src/applications/herald/storage/HeraldWebhookRequest.php)
的 `CONFIG_KEY_SCHEMA` 里，这一步不能省：数据库里有、而 PHP 的 schema 声明里没有的索引会
被判为 **surplus**，`bin/storage adjust` 会把它删掉——而且是在一个和本次改动毫无关联的时间
点删掉。

### 用叠加文件启动

```bash
# 1) 起服务（拉镜像失败 403 见「本地构建镜像」，命令与前五个服务相同，
#    只是 --build-arg SERVICE=gorge-webhook；--build 同样不能省）
docker compose up -d --build

# 2) 确认配置写进去了。这一步不能省 —— 它没写进去就是上面那张表的第三行。
docker compose exec phorge /opt/phorge/phorge/bin/config get gorge.webhook.uri

# 3) 确认服务能连上队列库
#    首次启动时这里会先失败一小会儿：{namespace}_herald 库是 phorge 容器里的
#    bin/storage upgrade 建的，在那之前 /readyz 不通、容器显示 unhealthy，这是预期的。
docker compose exec gorge-webhook wget -qO- http://127.0.0.1:8160/readyz

# 4) 确认索引已经建上
docker compose exec mysql mysql -uroot -p"$MYSQL_ROOT_PASSWORD" \
  -e "SHOW INDEX FROM phabricator_herald.herald_webhookrequest WHERE Key_name='key_status'"

# 5) 建一个 webhook（Herald → Webhooks → Create Webhook），指向一个能观测到请求的地址，
#    然后制造一次事务（改一个 task 的标题就行），确认接收端**只收到一次**。
```

第 5 步是这一节唯一真正的验收：双投是运行期行为，配置检查看不出来。

### 本地构建镜像

与前五个服务完全相同，只是 `SERVICE` 换成 `gorge-webhook`：

```bash
docker build -t ghcr.io/soulteary/gorge:webhook-latest \
  --build-arg SERVICE=gorge-webhook \
  /path/to/gorge/go
```

构建上下文是 `gorge` 仓库的 `go/` 子目录，不是仓库根目录。

### 验证与排障

```bash
# 队列现状（要带 token，留空时可省掉 --header）
docker compose exec gorge-webhook wget -qO- \
  --header="X-Service-Token: $GORGE_WEBHOOK_TOKEN" \
  http://127.0.0.1:8160/api/webhook/stats

# 服务日志：每次投递与每次失败都在这儿
docker compose logs -f gorge-webhook

# phd 那边应该**不再**出现 HeraldWebhookWorker 的任务
docker compose exec phorge /opt/phorge/phorge/bin/phd status
```

界面上最有用的一页是 Herald → 某个 webhook → **Recent Requests**：那张表直接显示每条
请求的状态图标、错误类型和错误码，接管前后的字段含义完全一样。

几个容易误判的现象：

- **一排蓝色的 "Queued" 停着不动。** 服务没跑、或者连不上队列库。前者看
  `docker compose ps`，后者看 `/readyz` 和 `GORGE_WEBHOOK_NAMESPACE` 是不是等于
  `storage.default-namespace`（默认 `phabricator`）——这一项留空**不等于**用默认值，服务端
  的默认值是 `phorge`，会连到一个不存在的库。Config 页面上的
  **"Gorge Webhook Service Not Ready"** 说的就是这件事。
- **全是 "Failed"，错误码 `In Silent Mode`。** `phabricator.silent` 开着，投递根本没交接，
  见上面那一节。
- **接收端每次收到两份一样的 payload。** 就是上面那张表的第三行，先看
  `bin/config get gorge.webhook.uri`。
- **`bin/webhook call` 不再打印 HTTP 状态码。** 这是接管后的正常输出。前台模式原本靠
  `setRunAllTasksInProcess()` 就地投递再读回状态码，交接之后请求是被 Go 异步取走的，
  命令行拿不到结果，所以改成打印请求的 PHID，让你去 Recent Requests 里看。

### 没有回滚

这一节以前写的是「清空 URI、把 owner 切回 phorge、再停服务」。**那条路已经不存在了**：
承接投递的 `HeraldWebhookWorker` 原生 HTTP 实现已经删除，它现在只是一个兼容壳，
读到「未委派给 Gorge」时把请求置为终态失败（`native-retired` hook error），而不是发出去。

所以照旧操作的后果是：webhook 一条都不再投递，而且失败是静默的——请求仍然被记录，
只是永远发不出去。`PhabricatorGorgeWebhookSetupCheck` 会把这个状态报成
`gorge.webhook.no-consumer`。

需要停 `gorge-webhook` 做维护时，就只是停它：队列表里的行留在 `queued`，服务起回来以后
继续投（前提是没被 `HeraldWebhookRequestGarbageCollector` 按 7 天保留期删掉）。不要顺手去
清 `gorge.webhook.uri` 或把 `gorge.webhook.owner` 改成 `phorge`——那不会让 `phd` 接手，
只会把请求提前判死。

真的不想要 webhook 投递，就删掉 Herald 里的 webhook 本身，而不是把所有权交给一个不存在的
消费者。

新增的 `key_status` 索引留着即可，它不影响 PHP 侧的任何查询。

## 用 Gorge 做数据库诊断（可选）

`gorge-db-api` 把数据库服务器健康、schema 差异、MySQL 环境检查与 storage upgrade
进度收进只读 HTTP API。PHP 侧 `PhabricatorGorgeDBClient` 已接入这些接口；默认栈启动
后会把内部地址和 token 写进 `gorge.db.uri` / `gorge.db.token`，
数据库控制台与相关 setup check 随即切流。URI 未配置时仍走原来的 PHP 直连实现。

> 本节命令统一用下面这个别名，`dc` 就是默认栈的 `docker compose`；多节点另有 `dc_multi`，见后文：
>
> ```bash
> alias dc='docker compose'
> ```

```bash
dc up -d --build

# 容器存活；这个探针不访问数据库。
dc exec gorge-db-api \
  wget -qO- http://127.0.0.1:8080/healthz

# 服务真正可用：至少一台已配置的 master 可以连接。
dc exec gorge-db-api \
  wget -qO- http://127.0.0.1:8080/readyz

# PHP 侧已经拿到 compose 内网地址。
dc exec phorge \
  /opt/phorge/phorge/bin/config get gorge.db.uri
```

服务默认不发布宿主端口，只允许 `phorge` 通过 Compose 网络访问。它复用基础编排的
`MYSQL_USER` / `MYSQL_PASSWORD`，host 与 port 固定为 `mysql:3306`。需要配置的变量如下：

| 变量 | 默认值 | 作用 |
|---|---|---|
| `GORGE_DB_MODE` | `enable` | `enable` 使用 db-api；`disable` 由 entrypoint 清理持久化 URI/token 并恢复 PHP 原生诊断 |
| `GORGE_DB_URL` | `http://gorge-db-api:8080` | PHP 访问 db-api 的内部地址 |
| `GORGE_DB_TOKEN` | 空 | 同时下发给服务端与 PHP 客户端的共享 token。**生产必须非空**：留空会关闭服务端鉴权，任何能连到它端口的客户端都能读到集群拓扑与各节点状态；Config 页会就此报安全警告（`gorge.db.token.missing`） |
| `GORGE_DB_IMAGE_TAG` | 空（继承 `GORGE_IMAGE_TAG`） | 统一 package 中 db-api 的标签后缀；实际标签为 `db-api-<值>` |
| `GORGE_DB_NAMESPACE` | `phabricator` | 库名前缀，必须等于 `storage.default-namespace` |
| `GORGE_DB_CONFIG_FILE` | 空 | 可选的 Phorge `local.json` 容器内路径；空值使用单节点配置 |

Compose 对 `gorge-db-api` 使用 `/healthz` 健康检查，`phorge` 只以 `service_started`
依赖它；数据库可达性由 `/readyz` 和 `PhabricatorGorgeDBSetupCheck` 报告。这个区分要保留：
进程已经启动与数据库诊断已经可用是两种状态，首启迁移期间不应让前者阻塞 Phorge。

默认单节点部署不需要挂配置文件。多节点部署要让 db-api 读到集群拓扑，有两种做法，都要**新建一个 override 文件**——本小节所有命令因此改用 `dc_multi`，它在默认 `docker-compose.yml` 之外再叠一个多节点 override（约定名为 `docker-compose.gorge-multinode.yml`）。缺了这个 `-f`，挂载和环境变量都不会生效，服务会静默退回单节点。

> 从这里起，把下面这行别名记在手边，本小节命令都用它：
>
> ```bash
> alias dc_multi='docker compose -f docker-compose.yml -f docker-compose.gorge-multinode.yml'
> ```

#### 做法 A（推荐）：专用只读配置文件 + 只读 DB 账号

不要长期把整份 `local.json` 挂进 db-api。它含有远超 db-api 所需的东西（所有应用密钥、mailer 凭据、第三方 token……），而 db-api 只需要 `cluster.databases`、命名空间，和一组只读的连接凭据引用。为它单独写一份最小配置文件，例如仓库里的 `deploy/gorge-db-cluster.json`：

```json
{
  "storage.default-namespace": "phabricator",
  "mysql.user": "gorge_dbapi_ro",
  "cluster.databases": [
    {"host": "mysql-master-1", "port": 3306, "role": "master", "partition": ["default"]},
    {"host": "mysql-master-2", "port": 3306, "role": "master", "partition": ["maniphest"]},
    {"host": "mysql-replica-1", "port": 3306, "role": "replica"}
  ]
}
```

这份文件只描述 namespace / 节点 / 角色 / 分区，以及要用哪个账号连（`mysql.user`），密码由环境变量 `GORGE_DB_MYSQL_PASS` 提供而**不写进文件**。它不含任何应用密钥，可以安全地放进部署仓库、以只读方式挂载、长期存在。

db-api 的所有业务路由都是只读的——它只跑 `SHOW` / `SELECT` / `INFORMATION_SCHEMA` 与读 `patch_status` / `hoststate`——所以**不要让它复用 root 或 `MYSQL_USER` 这种 ALL PRIVILEGES 账号**。为它建一个最小权限的只读账号（在每个被诊断的节点上执行；先在 MySQL 8 与 MariaDB 上各验证一遍下面这组权限足够、且不多给）：

```sql
CREATE USER 'gorge_dbapi_ro'@'%' IDENTIFIED BY 'a-strong-secret';
-- 读取库/表/列/索引元信息（schema-diff / schema-issues / charset-info）
GRANT SELECT ON `information_schema`.* TO 'gorge_dbapi_ro'@'%';
-- 读取迁移状态：{namespace}_meta_data.patch_status 与多 master 同步表 hoststate
GRANT SELECT ON `phabricator\_meta\_data`.* TO 'gorge_dbapi_ro'@'%';
-- 读取视图定义（部分 INFORMATION_SCHEMA 查询需要）
GRANT SHOW VIEW ON *.* TO 'gorge_dbapi_ro'@'%';
-- 复制状态：SHOW REPLICA STATUS 需要 REPLICATION CLIENT（MariaDB 亦同名）
GRANT REPLICATION CLIENT ON *.* TO 'gorge_dbapi_ro'@'%';
FLUSH PRIVILEGES;
```

> 权限说明：`REPLICATION CLIENT` 只暴露复制状态，不含数据；没有它时 db-api 会把该节点标成 `replication-client`（一个去授权即可解决的状态，不是故障）。**不要**授予 `PROCESS`、`SUPER`、`RELOAD` 或任何写权限。上面这组请务必在 MySQL 8 与 MariaDB 两种目标上都实测确认后再固化到文档/脚本，两者对 `information_schema` 的可见性与 `SHOW REPLICA/SLAVE STATUS` 的权限判定略有差异。

`docker-compose.gorge-multinode.yml` 把这份专用文件只读挂入，并指向只读账号：

```yaml
services:
  gorge-db-api:
    environment:
      # 指向容器内的专用配置文件（做法 A）。
      GORGE_DB_CONFIG_FILE: /etc/gorge/gorge-db-cluster.json
      # 只读账号的密码单独下发，不写进配置文件。
      GORGE_DB_MYSQL_PASS: ${GORGE_DB_RO_PASSWORD:-}
    volumes:
      # 只读挂载，且只挂这一份最小文件，不是整个 conf 目录。
      - ./deploy/gorge-db-cluster.json:/etc/gorge/gorge-db-cluster.json:ro
```

#### 做法 B（备选）：挂 Phorge 生成的 local.json

若不想维护第二份文件，也可以直接让 db-api 读 Phorge 已生成的 `cluster.databases`。这条路省事，但代价是把整份 `local.json`（含所有密钥）暴露给 db-api 进程——**务必只读挂载，并清楚这份文件的敏感范围**，且仍应把 `mysql.user` 指向上面的只读账号而非 root：

```yaml
services:
  gorge-db-api:
    environment:
      GORGE_DB_CONFIG_FILE: /opt/phorge/phorge/conf/local/local.json
    volumes:
      - phorge-conf:/opt/phorge/phorge/conf/local:ro
```

#### 应用配置与重启

`GORGE_DB_CONFIG_FILE` 非空时 db-api **fail closed**：文件缺失、JSON 非法、字段类型错、或没有任何可用 master，都会让服务启动失败并在日志里说明，而不是悄悄退回单节点。这是刻意的——一份坏的集群描述必须被看见，而不是被降级掩盖。

首次生成配置后，或每次给 `gorge-db-api` 新增挂载 / 环境变量之后，用 `--force-recreate` 重建容器让它带上新配置，**不要用 `restart`**：`restart` 只是重启进程，不会应用新的 volume 或 environment，服务会带着旧配置起来、看起来没报错却读的是老拓扑。

```bash
dc_multi up -d --force-recreate gorge-db-api
# 若同时改了 phorge 侧配置，再让 phorge 重新读一次：
dc_multi up -d --force-recreate phorge
```

重建后确认拓扑与契约都对：

```bash
# 服务能连到某台 master。
dc_multi exec gorge-db-api wget -qO- http://127.0.0.1:8080/readyz

# 契约元信息：contractVersion / namespace / topologySource=file / 能力列表。
# 带 token（留空时可省掉 --header），token 只能走 header，不能放 query。
dc_multi exec gorge-db-api wget -qO- \
  --header="X-Service-Token: $GORGE_DB_TOKEN" \
  http://127.0.0.1:8080/api/db/meta
```

`namespace` 必须与 Phorge 的 `storage.default-namespace` 一致、`contractVersion` 的 major 必须被这套 Phorge 认得——不一致时 `PhabricatorGorgeDBSetupCheck` 会在 Config 页报致命 setup issue，控制台继续走原生 SQL。

### 回滚

在 `.env` 设置 `GORGE_DB_MODE=disable`，再重新创建 Phorge。entrypoint 会清理已经持久化
的 URI/token（单节点用 `dc`，多节点用 `dc_multi`，两者复用同一套 `-f` 组合）：

```bash
dc up -d --build --force-recreate phorge
# 多节点部署改用带第三个 `-f` 的别名：
# dc_multi up -d --build --force-recreate phorge
```

之后数据库控制台恢复 PHP 直连诊断。确认不再需要服务时可以再停掉它；这不会改变数据库，
因为 db-api 的所有业务路由都是只读的。

## 协作模式与 Gitea（可选）

协作模式保留 Maniphest、Projects、Phriction、Calendar、Chat 等协作能力，把代码
托管和评审交给 Gitea。它是配置切换，不删除 PHP 类、数据库表或历史对象。

在 `.env` 中设置：

```dotenv
PHORGE_PRODUCT_PROFILE=collaboration
GITEA_BASE_URI=https://git.example.com/
GORGE_GITEA_WEBHOOK_SECRET=<随机共享密钥>
GORGE_GITEA_CONDUIT_TOKEN=<专用 Conduit bot token>
GORGE_GITEA_GATEWAY_TOKEN=<GORGE_CONDUIT_TOKEN 的值>
```

然后启用默认编排的 Gitea profile（`restart` 不会应用新增服务、环境变量或挂载）：

```bash
docker compose --profile gitea \
  up -d --force-recreate
```

运行时 profile 会停用 Diffusion，而不改管理员的
`phabricator.uninstalled-applications`。Drydock、Harbormaster、Differential、
Audit、Owners、Diviner 与 Paste 已随这一系列清理从发行版中物理移除，任何 profile
下都不存在，因此不再由 profile 开关。部署配置同时在已配置
`GORGE_RENDER_URI` 时把 `syntax-highlighter.engine` 切到
`PhabricatorGorgeSyntaxHighlighterEngine`。配置项是 class 类型，不能填写字面值
`gorge`。

协作模式**不再**关闭 diff：原生 GNU/PHP diff 已经移除，`gorge-render` 的
`/api/diff/*` 在所有 profile 下都必须可用，`GORGE_RENDER_ENABLE_DIFF` 与
`gorge.diff.enabled` 都已退役（见「用 Gorge 生成 diff」）。

`gitea.uri` 会在顶栏增加 Gitea 入口；Maniphest 增加 repository、issue、pull request、
commit 四个内建链接字段。字段沿用旧生成配置的 `std:maniphest:gitea.*` key，因此现有值
不需要数据迁移；管理员的其它自定义字段和停用应用配置保持原样。

在 Gitea 仓库或组织 Webhook 中，将目标设为：

```text
https://<对外桥接地址>/webhooks/gitea
```

密钥与 `GORGE_GITEA_WEBHOOK_SECRET` 相同，只选择 Issues、Pull Request、Push、Release。
事件标题、正文或提交信息中的 `T123` 会被追加到对应任务时间线。桥接不会反向写 Gitea，
不会同步 review/comment，也不会冒充 Gitea 用户；专用 Conduit bot 只需查看任务和添加
评论的权限。用户 SSO、账号绑定和离职回收仍由 Stargate 统一维护。

回到完整模式：

```dotenv
PHORGE_PRODUCT_PROFILE=full
```

重新创建 `phorge-migrate` 后，统一控制面会原子切换到 full：运行时应用门控和内建 Gitea
字段立即撤销，不需要写数据库或做三方合并。任务中已经存储的 Gitea 字段值和历史评论继续
保留。从旧版协作控制面首次升级时，entrypoint 会消费并删除旧的
`collaboration-profile-state.json`，精确恢复其保存的管理员基线后再交给新控制面。

## 参考

- [安装指南](src/docs/user/installation_guide.diviner)
- [配置指南](https://we.phorge.it/book/phorge/article/configuration_guide/)

### Gorge worker 执行协议 v1 升级

新版 `worker.execute` 在 PHP 内独立校验 `X-Service-Token`。启用委派执行时，必须设置非空 `GORGE_CONDUIT_TOKEN`，并与 gateway 和 Go worker 的 conduit token 保持一致；空 token 将拒绝执行任务。新版 PHP、gorge-taskqueue、gorge-worker 需要配套升级。升级期间暂停队列消费者，先更新 PHP 和 taskqueue，再更新 worker，只恢复 Go worker。不要因此停掉仍承担 trigger 或 fact 的其他 PHP 守护进程；本分支已退役仓库拉取 daemon。回滚应同步回滚配套组件。

新版成功执行会返回全部子任务，由 taskqueue 一次提交父任务归档和子任务入队。Feed HTTP 新任务使用完整投递快照，旧 key/uri 任务仍保留兼容处理。PHP 业务副作用在响应丢失或进程崩溃时仍可能重试，需要业务本身保持幂等。

可使用 `GORGE_TEST_ARCANIST_DIR=/path/to/arcanist php tests/contract/worker/execution.php` 检查 PHP 执行协议、子任务、重试策略及认证。

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
GD仍用于内置头像、图标、Meme及SpriteSheet，本轮不得删除GD扩展。
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
没有配置 Gorge queue 的部署仍保留历史生产路径。回滚时关闭新事件生产，
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

此开关仅捕获快照，尚未启用 Go 原生投递或重建调度；生产查询和同步写入
保持现有路径。outbox 捕获失败会让 SearchWorker 重试，不把索引版本标记
为完成。新事务只覆盖 search 数据库，不能代替各业务库事务中的变更捕获。
删除捕获目前只提供显式 helper，未接入对象销毁；`search.export` 的 missing
不能作为删除事件。

真实 MySQL 契约：`GORGE_TEST_ARCANIST_DIR=/path/to/arcanist GORGE_TEST_MYSQL_PORT=3306 GORGE_TEST_MYSQL_PASSWORD=test php tests/contract/search/outbox.php`。
仅在临时测试实例执行；脚本会创建并删除随机命名的测试数据库。

## Gorge 日志与缓存清理

首批清理模块为可选 `maintenance` profile，默认未启动、未移交执行权。
只覆盖通用/Markup/TTL 缓存、Conduit 日志、daemon 事件与锁日志。锁日志默认
仍无限保留，认证、任务归档、文件销毁及 outbox/inbox 不在此范围。

先执行 `bin/storage upgrade` 创建 cache/conduit/daemon 库的
`gorge_gc_control`，再部署本版 PHP。在所有 Web、CLI、daemon 节点执行
`bin/config set phd.gorge-cleanup true`，重启常驻 PHP daemon，确认旧二进制
和在途清理已退出。保护必须同时覆盖 Trigger 和 `bin/garbage collect`；
控制表缺失时，本版登记清理器失败关闭；关闭确认开关不会绕过控制行保护。

导出真实有效配置（不要手工复制默认 TTL）：

```sh
php scripts/setup/export_gorge_cleanup.php > cleanup.json
```

为 `gorge-maintenance` 配置三个目标主库 DSN 和非空服务 token；账号只授予
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

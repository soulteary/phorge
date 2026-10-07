# 搜索、邮件、图片与清理的生产切换

适用：当前 Phorge + Gorge 协作栈。默认栈不自动修改已有搜索后端或移交清理执行权。
`docker-compose.production.yml` 是显式选择的生产覆盖配置，所有启用的核心 Gorge 服务使用本地源码构建，避免新旧发布镜像混用。通知 admin/client 端口只绑定回环地址，浏览器 WebSocket 应经 TLS 反代访问。需要 Compose 2.24.4 或更新版本以支持端口列表 `!override`。
本地构建要求相邻 Gorge checkout 与 Phorge 是同一配对验收的源码对。使用发布镜像
时按 [发布镜像与源码配对](DOCKER.md#发布镜像与源码配对) 在本覆盖之后逐服务覆盖
manifest digest 并清除继承的 build，不能把基础编排的历史 tag 当成本次 Release。

## 切换范围

- 搜索：`cluster.search` 只保留 Gorge 读写适配器，禁用 MySQL/Ferret 并行路径。继续使用已经验收的生产后端和索引。新增投影 generation 仍为影子索引；此覆盖配置不会启用投影或提升 generation。
- 邮件：唯一 Gorge email adapter，PHP 产生原生邮件 outbox，worker 使用同一 metamta 数据库 relay 与投影，mailer 开启持久账本。SMS 与入站邮件配置保留。
- 图片：五种已有 thumbnail 配方使用 `gorge` 模式。Meme、sprite 等不在范围内；已有派生缓存不强制重建。
- 清理：部署启用 PHP 所有权 guard。九个 collector 的数据库 owner 仍须经过 export、import、dry-run、pause、resume 逐项移交；启动容器不会自动删除数据或移交 owner。

## 准备

1. 备份数据库与配置；确认所有 PHP daemon、CLI 和 Web 节点都运行含 cleanup guard 的版本。混用不认识 guard 的旧节点不能被数据库 owner 阻止。
2. 在 `.env` 配置非空 `GORGE_CONDUIT_TOKEN`、`GORGE_FILE_TOKEN`、`GORGE_TASKQUEUE_TOKEN`、`GORGE_MAILER_TOKEN`、`GORGE_SEARCH_TOKEN`、`GORGE_IMAGE_TOKEN`、`GORGE_MAINTENANCE_TOKEN`、`GORGE_RENDER_TOKEN`、`GORGE_WEBHOOK_TOKEN`、`GORGE_DB_TOKEN`；配置独立数据库密码 `MYSQL_ROOT_PASSWORD`、`MYSQL_PASSWORD`。worker token 默认与队列 token 一致。通知保持 Aphlict 协议，以反代和内网边界保护 admin。
3. 配置真实邮件 provider 和已验收的生产搜索后端。不要使用 mailer/search 的 test backend 作为生产验收。
4. 设置 `GORGE_MAILER_DELIVERY_DSN` 为当前 namespace 的 metamta 写库，配置 `GORGE_MAINTENANCE_CACHE_DSN`、`GORGE_MAINTENANCE_CONDUIT_DSN`、`GORGE_MAINTENANCE_DAEMON_DSN`、`GORGE_MAINTENANCE_DIFFERENTIAL_DSN`、`GORGE_MAINTENANCE_MULTIMETER_DSN` 为对应写库。均使用 Go MySQL DSN 格式，如 `user:password@tcp(mysql:3306)/phabricator_metamta`；不能指向副本。
5. 保持原 namespace。已有任务/邮件应先识别并处理旧格式积压；切换不会把既有 legacy 邮件任务自动转换为原生任务。
6. 为 DB API 单独建立只读账号，设置 `GORGE_DB_MYSQL_USER` / `GORGE_DB_MYSQL_PASS`，不能复用 Phorge 普通账号或 root。参考授权如下；把 namespace 和密码替换为部署的真实值，通过受控数据库会话执行，不将密码写入命令行历史：

```sql
CREATE USER 'gorge_dbapi_ro'@'%' IDENTIFIED BY '<independent-password>';
GRANT SELECT, SHOW VIEW ON `phabricator\_%`.* TO 'gorge_dbapi_ro'@'%';
GRANT REPLICATION CLIENT ON *.* TO 'gorge_dbapi_ro'@'%';
```

`information_schema` 的可见性随对应业务库权限自动提供，不向它直接授予权限。不要授予 INSERT、UPDATE、DELETE、DDL、GRANT OPTION、管理动态权限或角色。既有 Phorge 普通账号仍用于迁移；本次只拆分诊断账号。
`db-init` 只为 Phorge 普通账号授权，不会建立上述只读账号。已有更具体的 host
匹配账号时，MySQL 可能选它而非 `%`；配置变量名称和用户名不同都不能证明只读。
新建 namespace、调整授权或恢复数据库后，须重新完成在线授权门禁。

邮件参数分属两个层次：`.env` 的 `GORGE_MAILER_KEY` 是 PHP `cluster.mailers`
条目标识；`GORGE_MAILER_BACKEND_KEY` 是 Go provider 的 key（Compose 将它映射为
容器内 `GORGE_MAILER_KEY`）。单后端使用 `GORGE_MAILER_TYPE`，多后端使用
`GORGE_MAILER_CONFIG` 中各自唯一非空的 key。旧 `MAILER_TYPE/MAILER_KEY` 只是
Compose 升级桥接输入；旧 `MAILER_CONFIG` 必须改为 `GORGE_MAILER_CONFIG`，
不能依赖未实现的别名。直接启动 Go 应使用规范 `GORGE_` 变量。
`GORGE_MAIL_DELIVERY_MODE` 控制 PHP 生产路径；这个覆盖配置强制 native，默认基础栈
的 legacy 仍通过 Gorge 同步发送。它不会重新启用 PHP provider。

以下命令都在 `phorge-fork/` 执行，固定使用同一覆盖配置与 profiles：

```sh
docker compose -f docker-compose.yml -f docker-compose.production.yml \
  --profile mailer --profile search --profile maintenance config --quiet
python3 scripts/operations/preflight.py
docker compose -f docker-compose.yml -f docker-compose.production.yml \
  --profile mailer --profile search --profile maintenance up -d --build
python3 scripts/operations/preflight.py --check-db-grants
```

迁移任务先执行 storage upgrade 并发布图片模式与 guard；邮件配置任务再发布 native 模式；搜索配置任务等邮件配置完成后发布，避免两个文档写者覆盖彼此。Web 等两个任务完成再启动。worker 在 PHP、队列、策略与原生邮件依赖就绪前不领取任务。

配置 preflight 会拒绝空认证、测试后端、空搜索后端、复用数据库账号和公开通知端口；在线选项从 DB API 容器的同一网络来源以实际凭据连接 MySQL，检查真正选中的账户授权及 mandatory roles，避免只审计 `%` 账户而漏掉更具体 host 的写权限。临时检查容器使用正在运行的 Phorge 镜像 ID，凭据仅由标准输入传入，检查结束清理。它不证明供应商投递、搜索覆盖或备份恢复完成，自定义数据库拓扑和挂载的 backend 配置必须另行审计。

Worker 默认停收任务后等待 30 秒，容器 `stop_grace_period` 为 45 秒，为队列归档和关停清理留出余量。调整 `GORGE_WORKER_DRAIN_TIMEOUT_SEC` 时须同步增加容器等待时间；preflight 要求至少再预留 15 秒。关停到期任务的业务结果仍须人工核对，详细归档语义见 Gorge Worker 文档。

备份门禁必须在切换之前完成。`scripts/operations/manage.py` 默认只覆盖 bundled MySQL 与 named volumes，外部搜索必须使用显式原生适配器。新增 Elasticsearch 8 adapter 支持在同一停写窗口捕获预先配置的 repository 快照，再备份数据库；清单保存外部快照引用。所有搜索 backend 和 projection 必须指向完全相同的显式 endpoint，其他拓扑继续拒绝。用法和恢复边界见 [运维说明](scripts/operations/README.md)。不能删除搜索容器或忽略检查来取得“完整备份”。`restore-test.json` 的 `businessIntegrity=not_verified`、`externalSearchRestore=not_verified` 仍表示业务和搜索恢复未验收，不可作为切流通过的证据。

## 搜索与图片的功能验收

已有后端保持现有生产索引；首次启用新后端须使用 `bin/search` 的现有 init/index 工作流重建后再验收。验收至少包含已知对象搜索、更新、删除和不同权限用户的结果过滤。索引存在只能证明技术条件，不能证明数据覆盖和业务权限。

图片使用 `tests/contract/image/runtime.php` 与实际图片服务进行验收；检查五种配方、GIF、WebP、极端长宽和历史派生文件可读性。启动能力检查只验证协议、配方及格式声明，不证明像素行为等价。

邮件使用受控测试收件人确认准备、投递账本终态和 PHP 结果投影；提供方 accepted 不表示最终收件箱送达。确认旧队列中的邮件能够收尾。
同步 legacy 在网络前提交 unknown fence；native 通过持久投递账本记录状态。
两者的超时、坏回执、崩溃或接受后落盘失败都可能留下 unknown，禁止自动重投。
先暂停该邮件的进一步处理，核对 PHP 状态、任务/账本和供应商日志；native 可调用
只读 delivery 查询，legacy 不一定有这条 native 记录。确认结果后按部署的核对流程
处理，不手改 queued、不换后端尝试，也不以重发修复 projectionPending。
目前没有自动供应商结果查询或通用 unknown 解除命令，见 [邮件说明](DOCKER.md)。

## 清理交接

部署启动后先导出有效 PHP 策略。导出读取部署配置和数据库覆盖，不从示例文件推导保留期。

```sh
docker compose -f docker-compose.yml -f docker-compose.production.yml \
  --profile mailer --profile search --profile maintenance exec -T phorge \
  php scripts/setup/export_gorge_cleanup.php > cleanup-policy.json
```

将文件复制到共享配置卷：

```sh
docker compose -f docker-compose.yml -f docker-compose.production.yml \
  --profile mailer --profile search --profile maintenance cp \
  cleanup-policy.json phorge:/opt/phorge/phorge/conf/local/cleanup-policy.json
```

maintenance 只读挂载该卷，但可读取导出文件。确认该策略文件对容器 UID 10001 可读（它只包含清理策略），不要扩大 local.json 或 deployment.json 中凭据的读取权限。执行其镜像中的命令：

```sh
docker compose -f docker-compose.yml -f docker-compose.production.yml \
  --profile mailer --profile search --profile maintenance exec -T gorge-maintenance \
  /usr/local/bin/gorge-maintenance import /etc/phorge/cleanup-policy.json
```

对 `cache.general.ttl`、`cache.general`、`cache.markup`、`conduit.logs`、`daemon.processes`、`daemon.lock-log`、`differential.parse`、`differential.viewstate`、`multimeter.events` 分别执行：

```text
/usr/local/bin/gorge-maintenance dry-run <collector>
/usr/local/bin/gorge-maintenance pause <collector>
/usr/local/bin/gorge-maintenance resume <collector> gorge
```

使用上面的同一 compose 前缀加 `exec -T gorge-maintenance`。先检查 dry-run 样本与保留期，再交接；lock-log 默认 indefinite，可以归属 Gorge 但不删除。跨库 import 不是全局事务，失败后检查已成功项再重试。禁止修改运行中的策略，变更须先 pause、重新导出/import、再 resume。

## 统一只读验收

```sh
docker compose -f docker-compose.yml -f docker-compose.production.yml \
  --profile mailer --profile search --profile maintenance exec -T phorge \
  php scripts/setup/check_gorge_cutover.php conf/local/deployment.json conf/local/local.json
```

检查 required 策略、唯一 Gorge 搜索适配器及 read/write roles、原生邮件模式与账本能力、图片协议和五种配方、worker `/readyz`、清理 schema，以及全部九项 owner=gorge。缺失索引、paused/php owner、能力不兼容或认证错误均返回非零退出码。诊断只显示检查名称，不输出 token、DSN 或响应正文。检查不会发邮件、改索引或移交 owner。

此门禁通过之后，仍须保留上述功能验收记录与至少完整清理周期的运行观察。

## 回退

清理逐项 pause，再 resume 到 php，保留 guard；这不能恢复已删除数据。图片通过专门的部署变更恢复 shadow/legacy。邮件切回 legacy 前暂停产生任务并确认 native outbox、提交中/unknown 账本、结果投影与旧队列状态，恢复后也要保留原生任务消费者直到其收尾；不要只删除 mailer DSN。搜索适配器回退需要保留可用旧索引与相应版本代码，不能把影子 generation 当作已验收替代。

退出覆盖配置会改变生产路径，必须显式重建对应部署文档并重启消费者；不能仅停止容器。队列/worker 与已经退役的原生 taskmaster 不在此次回退范围。

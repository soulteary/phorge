# 搜索、邮件、图片与清理的生产切换

适用：当前 Phorge + Gorge 协作栈。默认栈不自动修改已有搜索后端或移交清理执行权。
`docker-compose.production.yml` 是显式选择的生产覆盖配置，使用本地 Gorge 源码构建 file-storage、queue、worker、mailer、search、image、maintenance，避免旧发布镜像缺少新协议。

## 切换范围

- 搜索：`cluster.search` 只保留 Gorge 读写适配器，禁用 MySQL/Ferret 并行路径。继续使用已经验收的生产后端和索引。新增投影 generation 仍为影子索引；此覆盖配置不会启用投影或提升 generation。
- 邮件：唯一 Gorge email adapter，PHP 产生原生邮件 outbox，worker 使用同一 metamta 数据库 relay 与投影，mailer 开启持久账本。SMS 与入站邮件配置保留。
- 图片：五种已有 thumbnail 配方使用 `gorge` 模式。Meme、sprite 等不在范围内；已有派生缓存不强制重建。
- 清理：部署启用 PHP 所有权 guard。九个 collector 的数据库 owner 仍须经过 export、import、dry-run、pause、resume 逐项移交；启动容器不会自动删除数据或移交 owner。

## 准备

1. 备份数据库与配置；确认所有 PHP daemon、CLI 和 Web 节点都运行含 cleanup guard 的版本。混用不认识 guard 的旧节点不能被数据库 owner 阻止。
2. 在 `.env` 配置非空 `GORGE_CONDUIT_TOKEN`、`GORGE_FILE_TOKEN`、`GORGE_TASKQUEUE_TOKEN`、`GORGE_MAILER_TOKEN`、`GORGE_SEARCH_TOKEN`、`GORGE_IMAGE_TOKEN`、`GORGE_MAINTENANCE_TOKEN`。worker token 默认与队列 token 一致。
3. 配置真实邮件 provider 和已验收的生产搜索后端。不要使用 mailer/search 的 test backend 作为生产验收。
4. 设置 `GORGE_MAILER_DELIVERY_DSN` 为当前 namespace 的 metamta 写库，配置 `GORGE_MAINTENANCE_CACHE_DSN`、`GORGE_MAINTENANCE_CONDUIT_DSN`、`GORGE_MAINTENANCE_DAEMON_DSN`、`GORGE_MAINTENANCE_DIFFERENTIAL_DSN`、`GORGE_MAINTENANCE_MULTIMETER_DSN` 为对应写库。均使用 Go MySQL DSN 格式，如 `user:password@tcp(mysql:3306)/phabricator_metamta`；不能指向副本。
5. 保持原 namespace。已有任务/邮件应先识别并处理旧格式积压；切换不会把既有 legacy 邮件任务自动转换为原生任务。

以下命令都在 `phorge-fork/` 执行，固定使用同一覆盖配置与 profiles：

```sh
docker compose -f docker-compose.yml -f docker-compose.production.yml \
  --profile mailer --profile search --profile maintenance config --quiet
docker compose -f docker-compose.yml -f docker-compose.production.yml \
  --profile mailer --profile search --profile maintenance up -d --build
```

迁移任务先执行 storage upgrade 并发布图片模式与 guard；邮件配置任务再发布 native 模式；搜索配置任务等邮件配置完成后发布，避免两个文档写者覆盖彼此。Web 等两个任务完成再启动。worker 在 PHP、队列、策略与原生邮件依赖就绪前不领取任务。

## 搜索与图片的功能验收

已有后端保持现有生产索引；首次启用新后端须使用 `bin/search` 的现有 init/index 工作流重建后再验收。验收至少包含已知对象搜索、更新、删除和不同权限用户的结果过滤。索引存在只能证明技术条件，不能证明数据覆盖和业务权限。

图片使用 `tests/contract/image/runtime.php` 与实际图片服务进行验收；检查五种配方、GIF、WebP、极端长宽和历史派生文件可读性。启动能力检查只验证协议、配方及格式声明，不证明像素行为等价。

邮件使用受控测试收件人确认准备、投递账本终态和 PHP 结果投影；提供方 accepted 不表示最终收件箱送达。确认旧队列中的邮件能够收尾，unknown 状态按既有账本恢复规则处理，不能手工盲目重发。

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

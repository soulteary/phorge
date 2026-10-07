# Docker 单机栈运维

入口：仓库根目录下 `bin/ops`，需要 Linux/macOS、Python 3.10+ 与 Docker Compose。
本工具不创建定时任务，也不发送外部通知；report 的 JSON 与退出码供现有监控接入。

## 备份边界

支持一个 bundled MySQL、MySQL 队列和 Docker 命名卷。备份所有非系统数据库，
保留 routines/events/triggers 与二进制字段，并捕获全部应用命名卷，包括部署配置、
文件、上传 manifest/lock/墓碑和卷身份。业务数据库所有权记录在 SQL 中原样保留。
备份不包含 MySQL 系统用户/授权、镜像本身、宿主机调度配置或第三方账户。
恢复部署需要原镜像 ID、相同版本 MySQL，以及独立保存的部署环境和 db-init 授权流程。

检测到 Redis、S3、已创建的 integrations/search 服务（即使当前停止）、非 bundled MySQL DSN、
自定义数据库集群、可写 bind mount 或未纳入快照的容器共享卷写入者（包括同一项目的临时容器）时拒绝执行。
这些拓扑必须先建立专用一致备份适配器；不能把拒绝改成跳过再称完整备份。
只读 bind 配置、容器可写层和 tmpfs 不属于持久卷快照，部署者必须确保它们不存业务数据。

`--exclusive-access` 是操作员确认没有宿主机 CLI、外部数据库连接、自动重启编排或
其他外部写入者。工具无法证明这些写入者已停止；这与项目既有停写声明的边界相同。
MySQL event_scheduler=ON 且存在 enabled event 时会被拒绝。内部调度/owner 不变：停止全部当前运行的应用容器，
再备份数据库与卷；finally 逐个恢复原先运行的容器，失败时继续尝试其余容器。
同一操作账号对同一数据库的备份用宿主机文件锁互斥；完成提示必须等到重启命令全部成功。
manifest 记录 originalRunningContainers，进程被强制终止后可据此手动恢复。
重启成功只表示容器已启动，仍需检查应用 readiness；不启动原先停止的服务。
应先暂停反向代理流量，使用维护窗口，并在完成后复查 readiness/backlog。
数据库和字节卷没有跨资源事务，一致性依赖停写成立。

备份包含业务数据、部署配置和可能的凭据，是**未加密**的敏感文件。目录 0700、文件
0600；必须放在仓库之外，并用组织既有加密/访问控制保护离机存储。不要上传到公开附件。

```bash
# 在 phorge-fork 根目录；先确保无外部写入者。
bin/ops backup /private/tmp/phorge-backup-20261007 \
  --compose docker-compose.yml --compose docker-compose.local.yml \
  --exclusive-access
bin/ops verify /private/tmp/phorge-backup-20261007
```

每次备份需要全新目录。快照阶段失败保留 incomplete manifest，不会成为有效恢复点。
快照已完成但原服务重启失败时，备份仍可有效，命令返回失败；需按 manifest 恢复原容器。
SHA256 用于检测损坏，不提供防篡改签名；应保护整个目录及 manifest。
不要删除墓碑、重建 task ID 或单独恢复某个业务数据库来消除积压。

## 隔离恢复演练

```bash
bin/ops restore-test /private/tmp/phorge-backup-20261007
```

使用随机命名的容器与命名卷，无映射端口、`--network none`、禁用事件调度，
不启动 PHP、Worker 或投递消费者。恢复 SQL 和所有卷，逐项比较卷内文件字节、链接、权限和 POSIX 所有权，检查 SQL 导入成功与各库表数量，
写入绑定 manifest SHA256 的 restore-test.json；随后删除演练资源。不会覆盖原数据库/卷。

该演练证明归档完整、tar 可恢复、SQL 可导入和表数匹配，**不等于业务完整性验收**。
`businessIntegrity=not_verified` 必须保留：文件引用/内容、用户登录、任务重放、unknown
核对和原服务版本配对仍需项目 paired acceptance 与文件 integrity 验证。
原镜像 ID 不在本机时失败；跨机器恢复前应从受控发布仓库取得并核对镜像。

## 故障恢复到隔离资源

```bash
bin/ops restore /private/tmp/phorge-backup-20261007 /private/tmp/phorge-recovery
```

与演练执行相同校验，但成功后保留随机命名的独立 MySQL 与应用卷。
输出目录 0700；`restore.json` 给出数据库容器/卷映射，`credentials.json` 保存新 root
密码（0600）。原数据库/卷不改，恢复容器无外网、无端口、无业务消费者。
失败时删除本次新建资源，目录中的未完成信息不能当成功恢复。

这提供了可检查的数据库/文件恢复结果，不自动切换生产。数据库应用账号/授权尚未
重建（applicationAccounts=not_provisioned）；部署前按恢复配置重新创建账号和授权，
用配对版本配置应用卷，隔离校验文件引用、未知投递和任务重放，再逐域交接执行权。
不要通过给恢复容器发布端口或接上生产 Worker 来跳过这些步骤。
用 restore.json 中记录的随机资源名清理不再需要的 staging；不得照源卷名删除。

## 告警与容量

先用既有 `scripts/setup/audit_gorge_status.php` 获取已脱敏的报告；Worker/maintenance
需要传其 runtime-file 才能覆盖，不应拿未知观测当零。

```bash
umask 077
docker compose -f docker-compose.yml -f docker-compose.local.yml \
  exec -T -w /opt/phorge/phorge phorge \
  php scripts/setup/audit_gorge_status.php > /private/tmp/phorge-audit.json
bin/ops report /private/tmp/phorge-audit.json \
  --previous /private/tmp/phorge-audit-previous.json \
  --backup /private/tmp/phorge-backup-20261007
```

首次运行省略 --previous。不要覆盖上一份报告后再拿它计算增长。
退出码：0 无告警；1 warning；2 critical；3 工具/输入错误。
告警包括报告超过 15 分钟/未来时间、unknown effect、超过 300 秒 backlog、缺失观测、
容量扫描 truncated、上传可用空间不足 1 GiB、未观察到已配置队列的 Worker、PHP/queue 物理身份不匹配、备份超过
24 小时、恢复回执缺失/不匹配、未来时间和演练超过 30 天。
上传 meta 已明确 enabled=false 时不把容量接口缺失当故障；其他未知观测仍告警。
部分域没有逾期指标时不会推断成功；报告不是全服务 SLO 监控。

容量列出记录数与数据/索引近似分配字节。只有相同 physicalDatabaseIdentity、双方
observed 且时间增加时计算每日净分配增长；换实例或缺失观测不生成速率。该速率不是
业务插入速率，也不是卷物理耗用量。宿主机/数据库磁盘阈值、7 天耗尽预测及缺失的
领域指标仍应由实际卷监控补齐，不把数据库增长套到上传卷可用空间。

## 归档

```bash
bin/ops archive /private/tmp/phorge-backup-20261007 /secure/offline/phorge-20261007
```

这是经校验的备份包复制归档，原备份保留，不提供自动清理/保留期删除。
归档目的端需要已挂载的受控存储；工具不上传第三方服务，不负责加密。

report 中的 businessArchive 是业务账本归档计划，onlineDeletionAllowed 始终 false。
inbox/finalize、邮件账本、删除终态与搜索墓碑必须保留 identity/digest/终态及查询能力；
只有实现可验证冷存储查询、迟到重放、跨版本和恢复证据后，才能删除在线记录。
当前仅计划这些前置条件，不声称已经实现业务数据库冷归档。

## 验证

```bash
PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s tests/operations -v
# 仅创建随机命名的临时 MySQL/卷，测试结束清理；需要本地 MySQL 镜像或可拉取它。
PYTHONDONTWRITEBYTECODE=1 python3 tests/operations/docker_roundtrip.py
```

## 上线前仍需补齐

- 定时调度、失败通知、备份任务本身失联告警与接收端验收。
- 加密离机副本、保留期、镜像和实际部署环境（含 shell 导出的变量及只读 bind 配置）的独立保存。
- MySQL 应用账号/授权重建，以及业务数据、文件引用和任务重放的恢复验收。
- MySQL/宿主机磁盘、binlog 和日志增长监控；明确 RPO、RTO 并测量演练用时。
- 业务账本冷存储查询和安全清理；现阶段不删除在线账本或原备份。

这些项目没有完成时，当前工具不能视为完整生产运维方案。

二次复核补充：备份额外保存 `container-config.json`（实际镜像、环境变量、启动配置和卷映射，可能含凭据）。
这不会自动保存只读 bind 文件的内容。显式配置的本地文件/上传路径必须落在捕获的命名卷内，否则拒绝备份。
审计报告缺版本、服务或必需 inventory 域时触发 `AUDIT_INCOMPLETE`，不能用残缺报告证明健康。
备份配置、归档文件及目录提交执行 fsync；底层远程存储仍需提供相应持久化保证。

## 故障分支与证据校验

停写后及提交快照前重新检查容器、卷、数据库和 event_scheduler；新增写入者、
容器重启、拓扑变化、OOM 或退出码 137 会拒绝生成完成快照。
Phorge local.json 中的数据库地址、文件和仓库路径也参与范围检查；配置本身必须在命名卷内。
原先停止的 Phorge 通过 docker cp 读取配置，并保持停止。

Docker 命令默认最多等待 1 小时，SQL 探针最多 30 秒；超时返回失败，大型备份需先测量维护窗口。
SIGINT/SIGTERM 会进入 finally 恢复/清理流程；SIGKILL、主机掉电和 Docker 不可用无法由进程保证清理。
恢复前写入 `.phorge-restore-*-resources.json`，记录本次隔离资源；清理失败会保留记录供人工核对。
归档辅助容器使用 `phorge-ops-helper-*` 名称和 `phorge.ops.helper=true` 标签，中断时尝试删除；
强制终止后应核对这些辅助容器，再按 manifest 的 originalRunningContainers 恢复原服务。
不得把原数据库/卷当作演练资源删除。跨用户、跨主机的并发仍由 exclusive-access 声明排除。

恢复回执校验版本、状态、manifest 摘要、时区、先后顺序、数据库/卷数量和验证范围。
未演练与备份损坏分别报告 RESTORE_UNVERIFIED、BACKUP_UNVERIFIED。
容量缺字段或为负数时报告错误，不填零；配置队列却缺物理身份、调度器身份不匹配也告警。
失败诊断不输出任意输入值、Docker stderr 或含凭据的参数。

恢复完成、签发回执前重新校验备份文件和原 manifest 摘要；恢复过程中被改动的源包不签发证据。
归档在目标副本上再次核对恢复回执，中断会清理本次 partial 目录。
运维回归覆盖 48 项单元测试（含 1,000 个固定随机输入），以及隔离 Docker 的成功、导出失败和中断场景。

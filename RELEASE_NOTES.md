# Release notes

## 2026.10.08-r1

配对 Gorge `2026.10.08-r1`，以各自发布 PR 合并后的不可变提交完成验证。
发布步骤见 [详细说明](docs/releases/2026.10.08-r1.md)。Gorge 新流程发布
`release-manifest.json` 中的服务 digest，不再生成 `service-<CalVer>` 或更新
`service-latest`；部署本版须使用 manifest 中的源码配对和逐服务 digest override。

相对已发布的 `2026.10.07-r2`，本版本包含：

- 在同步 Gorge 邮件提交前持久化 `unknown` 状态，阻止结果不明时自动重发或切换后端；保留临时搜索索引错误，让 Worker 按重试协议处理。
- 修复 Compose 邮件后端配置与生产凭据要求，增加数据库只读授权 preflight、Elasticsearch 原生快照备份与隔离恢复验证，并加强配对交付和 PHP 运行库检查。
- 合并上游 Herald 附加数据检查，保留本 fork 的条件/动作类型校验，并补充回归覆盖。
- 修正配置后台运行库、历史 schema 和数据库诊断，将连接健康与复制状态分开显示，避免已退役能力造成误报。
- 将浏览器通知连接状态与 PHP 服务端节点检查分开，跳过禁用节点，并校验管理接口响应。
- 清理重复和未使用的中文翻译及旧工具；保留可恢复的在线 API 翻译流程，增加来源发现、格式校验和离线测试，修正 Moonshot Kimi K2.6 默认参数与永久错误处理。

本次发布准备只更新发布说明和文档入口。基础 Compose 的历史 Gorge 镜像默认值
继续保留 `2026.10.07-r2`，不能用这些标签推断当前源码已经完成配对部署；默认
Compose 的 Gitea profile 仍要求显式设置历史镜像标签，当前版改用 digest override。

### English

This release pairs with Gorge `2026.10.08-r1` using the immutable commits produced by
the two release PRs. Gorge now publishes service digests in `release-manifest.json`
instead of promoting `service-<CalVer>` or `service-latest` tags. Deploy the exact paired
commits with per-service digest overrides.

Since `2026.10.07-r2`, this release fences unknown synchronous mail outcomes, preserves
temporary search failures for worker retries, hardens Compose credentials and operations,
merges upstream Herald attachment checks with fork safeguards, corrects configuration
and database diagnostics, separates browser notification status from server checks, and
streamlines Chinese translation maintenance with resumable API batches, format validation
and corrected Moonshot Kimi K2.6 request defaults. Release preparation updates documentation
only; the base Compose image tags remain historical `2026.10.07-r2` defaults.

## 2026.10.07-r2（已发布 / Released）

配对 Gorge `2026.10.07-r2`。当时的配对源码、验证与发布顺序见 [发布准备记录](docs/releases/2026.10.07-r2.md)。

相对已发布的 `2026.10.07-r1`，本版本包含四个主线提交：

- 整理 Gorge 容器部署、配置来源、生产切换与运维文档。
- 修复历史仓库路径迁移：已退役配置缺失时继续使用原有默认值。
- 用仓库内置的兼容 PHP 运行库替代外部 Arcanist checkout；启动、构建、映射维护与测试入口统一使用内置运行库。
- 修复测试隔离：每条测试结束后恢复 PHP 执行时限。

本次发布准备还将示例配置和 Compose 的公共 Gorge 镜像默认值锁定到 `2026.10.07-r2`。默认 Compose 的 Gitea profile 继续要求显式设置 `GORGE_GITEA_IMAGE_TAG`。

### English

This release pairs with Gorge `2026.10.07-r2`. Its release preparation record documents the source pair, validation and historical publication order.

Since `2026.10.07-r1`, four commits update deployment and operations guidance, restore the fallback in the historical repository path migration, replace the external Arcanist checkout with a bundled PHP runtime, and restore execution time limits after each test. Release preparation also pins the shared Gorge image defaults to `2026.10.07-r2`, while the default Compose Gitea profile keeps its explicit image-tag requirement.

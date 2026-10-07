# Release notes

## 2026.10.07-r2（待发布 / Unreleased）

配对 Gorge `2026.10.07-r2`。先发布 Gorge 并确认全部服务镜像可用，再合并和发布本版本；发布准备见 [详细说明](docs/releases/2026.10.07-r2.md)。

相对已发布的 `2026.10.07-r1`，本版本包含四个主线提交：

- 整理 Gorge 容器部署、配置来源、生产切换与运维文档。
- 修复历史仓库路径迁移：已退役配置缺失时继续使用原有默认值。
- 用仓库内置的兼容 PHP 运行库替代外部 Arcanist checkout；启动、构建、映射维护与测试入口统一使用内置运行库。
- 修复测试隔离：每条测试结束后恢复 PHP 执行时限。

本次发布准备还将示例配置和 Compose 的公共 Gorge 镜像默认值锁定到 `2026.10.07-r2`。默认 Compose 的 Gitea profile 继续要求显式设置 `GORGE_GITEA_IMAGE_TAG`。

### English

This unreleased candidate pairs with Gorge `2026.10.07-r2`. Publish Gorge and verify every service image before merging or releasing Phorge.

Since `2026.10.07-r1`, four commits update deployment and operations guidance, restore the fallback in the historical repository path migration, replace the external Arcanist checkout with a bundled PHP runtime, and restore execution time limits after each test. Release preparation also pins the shared Gorge image defaults to `2026.10.07-r2`, while the default Compose Gitea profile keeps its explicit image-tag requirement.

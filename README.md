**Phorge** is a collection of web applications which help software companies build better software.

Phorge is a community-maintained fork of [Phabricator](http://phabricator.org).

Phorge includes applications for:

  - reviewing and auditing source code;
  - hosting and browsing repositories;
  - tracking bugs;
  - managing projects;
  - conversing with team members;
  - assembling a party to venture forth;
  - writing stuff down and reading it later;
  - hiding stuff from coworkers; and
  - also some other things.


Phorge is developed and maintained by [The Phorge Team](https://phorge.it).

## 版本说明

新版本变更见 [RELEASE_NOTES.md](RELEASE_NOTES.md)，`2026.10.07-r2` 的配对源码、验证与发布顺序见 [发布准备](docs/releases/2026.10.07-r2.md)。

## 部署与运维

容器部署、配置来源与核心服务见 [DOCKER.md](DOCKER.md)，备份、恢复、容量与告警入口见 [运维说明](scripts/operations/README.md)。协议细节以配对 Gorge 的 [模块索引](../gorge/docs/README.md) 为准；跨仓库链接需要相邻 checkout。

## Gorge 生产切换

搜索、原生邮件、图片与九项缓存/日志清理的统一覆盖配置、交接步骤及只读验收见 [PRODUCTION-CUTOVER.md](PRODUCTION-CUTOVER.md)。

## 本地化 / Localization

本仓库内置简体中文（`zh_CN`）界面本地化，默认可选（默认语言仍为英文）。启用方式、运行时链路与翻译维护工具链详见 [I18N-zh_CN.md](I18N-zh_CN.md)。

----------

**LICENSE**

Phorge is released under the Apache 2.0 license except as otherwise noted.

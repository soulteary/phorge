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

版本变更见 [RELEASE_NOTES.md](RELEASE_NOTES.md)，`2026.10.08-r1` 的配对源码、验证与发布顺序见 [发布准备](docs/releases/2026.10.08-r1.md)。当前产物的 digest 和源码配对见 [部署说明](DOCKER.md#发布镜像与源码配对)。

## 部署与运维

维护文档的阅读顺序、验证入口与支持边界见 [文档索引](docs/README.md)。

容器部署、配置来源与核心服务见 [DOCKER.md](DOCKER.md)，备份、恢复、容量与告警入口见 [运维说明](scripts/operations/README.md)。协议细节以配对 Gorge 的 [模块索引](../gorge/docs/README.md) 为准；跨仓库链接需要相邻 checkout。

## Gorge 生产切换

搜索、原生邮件、图片与九项缓存/日志清理的统一覆盖配置、交接步骤及只读验收见 [PRODUCTION-CUTOVER.md](PRODUCTION-CUTOVER.md)。

## 本地化 / Localization

本仓库内置简体中文（`zh_CN`），支持个人语言与全局默认语言设置，未配置时仍使用英文。启用与部署说明见 [I18N-zh_CN.md](I18N-zh_CN.md)，在线模型 API 翻译维护说明见 [scripts/i18n/README.md](scripts/i18n/README.md)。

----------

**LICENSE**

Phorge is released under the Apache 2.0 license except as otherwise noted.

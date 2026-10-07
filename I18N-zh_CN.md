# 简体中文本地化（zh_CN）

本仓库内置简体中文（`zh_CN`），个人用户可以切换语言，管理员可以设置全局默认语言。未配置个人或全局语言时，Web 界面默认使用 `en_US`；命令行默认语言也是 `en_US`，两者分别设置。

## 启用中文

### 个人界面语言

登录后进入 **Settings → Account → Language → Translation**，选择 **中文（简体）** 并保存。也可以访问 `/settings/panel/language/`，页面会跳转到当前用户的语言设置。

个人选择只影响该用户，不会修改其他用户或命令行的语言。

### 全局默认界面语言

管理员进入 **Settings → Global Default Settings**，在 **Language → Translation** 中选择 **中文（简体）**。直接入口为 `/settings/builtin/global/page/language/`；尚未建立全局设置时，可从 Settings 页面选择 **Create Global Defaults**。

全局默认值适用于未单独设置语言的用户。个人显式保存的语言选择优先于全局默认值。设置全局中文无需修改 `PhabricatorTranslationSetting::getSettingDefaultValue()` 的源码。

### 命令行和后台任务语言

在 Phorge 根目录执行：

```bash
bin/config set locale.command zh_CN
```

`locale.command` 控制没有指定 `--locale` 的命令行脚本，不改变 Web 用户设置。支持公共语言参数的命令也可以使用 `--locale zh_CN` 临时覆盖。恢复命令行英文默认值：

```bash
bin/config set locale.command en_US
```

## 翻译维护

维护入口是 [`scripts/i18n/translate_zh_api_batch.py`](scripts/i18n/translate_zh_api_batch.py)，通过在线模型 API 翻译当前源码需要的文案。安装依赖、提供 API 密钥、筛选候选、批量翻译、续跑与校验说明见 [`scripts/i18n/README.md`](scripts/i18n/README.md)。

先查看参数和只读候选预览：

```bash
python3 scripts/i18n/translate_zh_api_batch.py --help
python3 scripts/i18n/translate_zh_api_batch.py --dry-run
```

工具根据当前 PHP AST 提取 `pht()` 的静态键、参数类型和源码位置，处理缺失译文以及待改善的英文或中英混合译文，并保留复数、性别等数组分支。它自动读取 [`resources/i18n-zh-glossary.md`](resources/i18n-zh-glossary.md)，用于统一模型翻译中的术语。

旧的缺失清单、TSV 映射、规则补译脚本和临时进度文件已移除，保留术语表供在线翻译使用。候选与进度以当前源码和实际生效词典为准。

## 运行时组成

- `src/infrastructure/internationalization/translation/PhabricatorChineseTranslation.php` 提供中文映射；值可以是字符串，也可以是复数或性别分支数组。
- `src/infrastructure/internationalization/locale/PhabricatorChineseLocale.php` 定义 `zh_CN`，显示名称为「中文（简体）」，回退语言为 `en_US`。
- `src/__phutil_library_map__.php` 注册这两个类。当前内置运行库的类发现机制能找到它们，`PhutilLocale::loadAllLocales()`、`loadLocale('zh_CN')` 和 `PhutilTranslation::getAllTranslations()` 均支持自动发现。
- `PhabricatorTranslationSetting` 将内置简体中文放入常规语言选项。
- `PhabricatorEnv::setLocaleCode()` 当前保留 `zh_CN` 专门分支，直接加载中文 Locale 和映射。此分支是现有实现，不是因为当前运行库必然无法发现中文类。

Web 请求使用用户语言设置；命令行从 `locale.command` 加载语言。`normalizeLocaleCode()` 先将连字符变为下划线，再将 `zh_*` 归一为 `zh_CN`，所以 `zh-CN`、`zh_Hans`、`zh_Hant` 等当前都会使用这套简体中文译文。`translation.override` 中的自定义映射优先于内置译文。

## 验收与部署

模型译文需要人工复核术语和语境。词典键命中率、译文是否含汉字或静态缺失键数量，都不能直接表示真实界面的汉化完成率：源字符串中也有命令行消息、错误信息、占位模板和产品名，提取范围也不能覆盖所有动态内容或未使用 `pht()` 的文案。

工具在提交翻译文件前执行 PHP 语法和原生格式校验。修改后检查差异，并在启用中文的实际页面中验证；独立语法检查可运行：

```bash
php -l src/infrastructure/internationalization/translation/PhabricatorChineseTranslation.php
```

当前容器的 `opcache.validate_timestamps=0`。翻译文件随源码打包，更新后应按部署流程重新构建镜像并重启 Web 和后台消费者，确保它们加载新文件。如果实际出现超大翻译文件的编译或符号加载异常，再检查 OPcache 日志及黑名单配置；黑名单不是启用中文的必要步骤。

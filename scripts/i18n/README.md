# 在线模型 API 汉化维护

`translate_zh_api_batch.py` 是中文翻译维护入口。它读取当前源码与实际生效的中文词典，调用在线模型 API 翻译文案，再校验并更新 `PhabricatorChineseTranslation.php`。中文界面的个人、全局和命令行启用方式见 [I18N-zh_CN.md](../../I18N-zh_CN.md)。

## 环境准备

需要 Python 3.10 或更新版本、可运行本仓库内置运行库的 PHP，以及已准备好的 PHP-Parser。Python 端只使用标准库，不需要安装 `openai` 包或其他 Python 依赖。

以下示例在 Phorge 根目录执行。PHP-Parser 尚未准备时，先显式构建：

```bash
php scripts/runtime/build.php php-parser
```

该准备步骤可能下载解析器依赖。翻译工具本身不会自动构建依赖或写入源码提取缓存；可以用 `--php /path/to/php` 选择 PHP 可执行文件。

## 先预览，再翻译

查看帮助和当前候选：

```bash
python3 scripts/i18n/translate_zh_api_batch.py --help
python3 scripts/i18n/translate_zh_api_batch.py --dry-run --limit 30
```

`--dry-run` 完全只读：不调用模型 API，不要求 API 密钥，不改翻译文件或进度文件，也不产生提取缓存。`--reset-progress --dry-run` 只按忽略完成记录的方式预览，不重置磁盘上的进度。

默认使用 Moonshot 的兼容 API。通过环境变量设置密钥，正式运行时再提供实际值：

```bash
export MOONSHOT_API_KEY='替换为你的 API 密钥'
python3 scripts/i18n/translate_zh_api_batch.py --limit 500 --batch-size 30
```

默认地址是 `https://api.moonshot.cn/v1`，模型是 `kimi-k2.6`。运行完成后检查差异：

```bash
git diff -- src/infrastructure/internationalization/translation/PhabricatorChineseTranslation.php
```

工具默认不附加服务商专用参数。若所选 Moonshot 模型需要显式关闭 thinking，可传入 JSON 对象：

```bash
python3 scripts/i18n/translate_zh_api_batch.py \
  --extra-body '{"thinking":{"type":"disabled"}}' \
  --limit 100
```

`--extra-body` 默认 `{}`，只添加请求字段，不能覆盖 `model`、`messages`、`temperature`、`top_p`、`max_tokens` 或 `stream`。其他服务商是否接受 thinking 或其他扩展字段，需要按对应接口选择；本工具不会将 Moonshot 参数强加给所有服务。

需要换用其他兼容 Chat Completions 的服务时，显式指定地址、模型和密钥环境变量名：

```bash
export TRANSLATION_API_KEY='替换为你的 API 密钥'
python3 scripts/i18n/translate_zh_api_batch.py \
  --base-url https://your-api.example/v1 \
  --model your-model \
  --api-key-env TRANSLATION_API_KEY \
  --limit 100
```

请求发送待译文案、原文键、当前分支、源码文件与行号上下文及术语表。服务商收到这些内容并按其价格计费；密钥通过请求认证提供，工具不会读取并发送整个源码文件或其他配置。

## 选择翻译范围

候选取自当前 PHP 源码中可求值的 `pht()` 首个参数。内部 PHP AST 辅助程序复用官方文件列表和提取器，读取 `src/`、`support/`、`scripts/` 中的键、参数类型及使用位置，去除空串和重复键，并纳入原生校验器列出的日期翻译月份缩写。它不是单独的翻译入口。

- `--mode untranslated` 是默认值：补充缺失键，翻译无汉字的现有译文，并改善启发式识别出的中英混合译文，包括数组中的待译叶节点。
- `--mode missing` 只补充当前源码使用、但实际词典未提供的键。
- `--mode all` 将当前源码使用的文案纳入重新翻译范围，仍跳过保留项和进度已确认完成的译文，并执行格式校验。
- `--key` 可以重复使用，限定需要翻译的当前源码键。在默认模式下，它允许定向翻译该键的全部字符串叶节点，绕过品牌名称和混合文本筛选；配合 `--mode missing` 时仍只补充缺失键。键必须与 `pht()` 的完整原文一致。

例如，只补充缺失键：

```bash
python3 scripts/i18n/translate_zh_api_batch.py --mode missing --dry-run --limit 100
python3 scripts/i18n/translate_zh_api_batch.py --mode missing --limit 100
```

定向处理文案时，先预览，再使用同样的键正式运行：

```bash
python3 scripts/i18n/translate_zh_api_batch.py --key 'Save Changes' --dry-run
python3 scripts/i18n/translate_zh_api_batch.py --key 'Save Changes'
```

筛选使用源码键，不依赖会随编辑变化的翻译文件行号。未被当前提取范围引用的旧词典键不会自动成为候选。候选识别仍是维护辅助：保留品牌名的译文、技术缩写和特殊文案需要按语境判断。

`--limit` 限制本次处理的待译单元数，默认 500。缺失键计作一个单元，现有数组中的每个待译字符串叶节点分别计数。`--batch-size` 是每个 API 请求的单元数，默认 30。重复执行同样命令可以继续处理剩余候选。

## 术语、占位符与分支

工具自动读取 [resources/i18n-zh-glossary.md](../../resources/i18n-zh-glossary.md)，将整份 Markdown 内容加入模型提示；可用 `--glossary` 指定其他词表文件。

默认筛选跳过只包含内置保留品牌或技术名称的条目，例如 `Phorge`、`PHID`，以及没有自然语言的纯占位模板。正文中的品牌与术语通过模型提示要求保留，仍需人工复核。

`%s`、`%d`、`%2$s`、`%%` 等格式标记、方括号标记、反引号中的代码、URL 和 Remarkup 链接目标在请求前被替换为保护标记，响应恢复后还会检查。链接的显示文本仍可翻译。现有复数、性别等数组结构保留，逐个翻译字符串叶节点，不把整个数组展平成一个中文字符串。缺失键可以写入经过原生校验的中文标量译文。

API 返回不完整、保护标记损坏或格式校验失败的结果不会作为成功译文写入。语法和格式正确不能证明术语与语境正确，仍需查看差异并检查中文页面的实际显示。

## 进度与续跑

默认进度文件为 `src/.cache/i18n/zh-api-progress.json`，可用 `--progress-file` 更换路径。进度按原文键、数组分支与已写入译文的摘要记录，不依赖候选序号或翻译文件行号。旧维护快照与旧进度文件不是这个工具的当前输入。

每批有效结果通过校验并写入翻译文件后，才记录完成状态。中途失败会保留已经完成的批次；再次运行原命令即可续跑。若需要忽略已有完成记录，使用 `--reset-progress`。改变源码、译文或选择范围后，工具重新读取当前候选。

`--mode all` 和 `--key` 也会跳过摘要匹配的已完成译文。需要重新处理这些译文时，可加 `--reset-progress`，并先用 `--dry-run` 确认选择范围。

每批提交前，工具生成临时 PHP 文件并执行语法检查与原生翻译格式校验，确认原翻译文件未被并发修改，再原子替换文件。出现并发编辑、请求或校验错误时，根据错误说明修正后重跑。

## 参数与失败处理

常用参数除上述范围、模型、路径选项外，还包括：

- `--file`：目标中文翻译文件，默认 `src/infrastructure/internationalization/translation/PhabricatorChineseTranslation.php`。
- `--request-timeout`：单次请求超时秒数，默认 120。
- `--retry`：每个请求的总尝试次数，默认 2；`--retry-sleep`：重试间隔秒数，默认 1。
- `--request-interval`：批次请求之间的间隔秒数，默认 0.5。
- `--temperature`、`--top-p`、`--max-tokens`：模型采样与输出参数，默认分别为 0.6、0.95、32768；需要与所选服务及模型支持的参数保持一致。

退出码 `0` 表示成功或没有候选，`2` 表示有模型输出被拒绝，`1` 表示请求、写入或校验错误。遇到失败先查看终端中的具体原因，保留进度文件并重新运行；不需要删除已经完成的译文。

## 复核与部署

正式更新后，检查译文、术语、占位符及分支在实际页面中的表现。可独立执行语法检查：

```bash
php -l src/infrastructure/internationalization/translation/PhabricatorChineseTranslation.php
```

当前容器关闭 OPcache 时间戳检查，翻译文件更新后需按部署流程重新构建镜像并重启 Web 与后台消费者。部署说明见 [I18N-zh_CN.md](../../I18N-zh_CN.md#验收与部署)。

旧的 TXT、TSV 翻译快照、规则补译脚本和临时进度文件已删除，保留在线 API 入口与术语表。当前候选数量、词典键命中率和含汉字率都不是实际界面的汉化完成率；本工具不覆盖所有动态内容或未使用 `pht()` 的文案。

离线回归测试使用本机模拟 API，不需要真实密钥或调用付费服务：

```bash
PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s scripts/i18n -p 'test_*.py' -v
```

# 中文翻译分批维护

处理文件：`src/infrastructure/internationalization/translation/PhabricatorChineseTranslation.php`。
翻译增删会移动行号；不维护固定“总批次/最后一行”清单。优先依据当前缺失检测结果处理，见 [本地化说明](../../I18N-zh_CN.md)。

## 单批预览与写回

在项目根目录执行；其他目录须通过 `--file` 指定绝对路径。先查看当前文件行数：

```bash
wc -l src/infrastructure/internationalization/translation/PhabricatorChineseTranslation.php
python3 scripts/i18n/improve_zh_batch.py --start-line 1 --end-line 1000 --dry-run
python3 scripts/i18n/improve_zh_batch.py --start-line 1 --end-line 1000 --limit 500
```

脚本按匹配到的条目行号过滤，`--limit` 是本次替换上限，不保证一批已全部处理。
每批写回可能改变后续行号，应重新检查文件与缺失清单，再选下一范围。
不要对改动中的文件一次展开固定 28 批命令。

## 依据缺失清单补全

```bash
python3 scripts/i18n/check_zh_missing.py --top 1000 --out resources/i18n-zh-missing-top1000.txt
# 本地规则补全；会写翻译文件和检测清单
BATCH_SIZE=1000 MAX_ROUNDS=20 bash scripts/i18n/run_zh_autofill.sh
php -l src/infrastructure/internationalization/translation/PhabricatorChineseTranslation.php
```

自动补全不等于翻译质量验收。检查 diff 中的占位符、复数/性别分支和术语，
再以实际中文界面核验。`resources/i18n-zh-missing-*` 是检测输出快照，不能代表当前覆盖范围；修改后重新生成需要的清单。

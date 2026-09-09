# 中文翻译批处理任务规划（每批 1000 行）

- **文件**: `src/infrastructure/internationalization/translation/PhabricatorChineseTranslation.php`
- **数据行范围**: 第 22 行～第 27066 行（`return array(...)` 内容）
- **每批行数**: 1000 行
- **总批次数**: 28 批

## 使用方式

在项目根目录执行，或任意目录下用 `--file` 指定翻译文件绝对路径。

每批建议先 `--dry-run` 看会改多少条，再正式写回：

```bash
# 示例：处理第 1 批（22～1021 行），先试跑
python3 scripts/i18n/improve_zh_batch.py --start-line 22 --end-line 1021 --dry-run

# 确认后执行（每批最多替换 500 条，可按需调 --limit）
python3 scripts/i18n/improve_zh_batch.py --start-line 22 --end-line 1021 --limit 500
```

## 分批任务表

| 批次 | 起始行 | 结束行 | 命令 |
|------|--------|--------|------|
| 1 | 22 | 1021 | `--start-line 22 --end-line 1021` |
| 2 | 1022 | 2021 | `--start-line 1022 --end-line 2021` |
| 3 | 2022 | 3021 | `--start-line 2022 --end-line 3021` |
| 4 | 3022 | 4021 | `--start-line 3022 --end-line 4021` |
| 5 | 4022 | 5021 | `--start-line 4022 --end-line 5021` |
| 6 | 5022 | 6021 | `--start-line 5022 --end-line 6021` |
| 7 | 6022 | 7021 | `--start-line 6022 --end-line 7021` |
| 8 | 7022 | 8021 | `--start-line 7022 --end-line 8021` |
| 9 | 8022 | 9021 | `--start-line 8022 --end-line 9021` |
| 10 | 9022 | 10021 | `--start-line 9022 --end-line 10021` |
| 11 | 10022 | 11021 | `--start-line 10022 --end-line 11021` |
| 12 | 11022 | 12021 | `--start-line 11022 --end-line 12021` |
| 13 | 12022 | 13021 | `--start-line 12022 --end-line 13021` |
| 14 | 13022 | 14021 | `--start-line 13022 --end-line 14021` |
| 15 | 14022 | 15021 | `--start-line 14022 --end-line 15021` |
| 16 | 15022 | 16021 | `--start-line 15022 --end-line 16021` |
| 17 | 16022 | 17021 | `--start-line 16022 --end-line 17021` |
| 18 | 17022 | 18021 | `--start-line 17022 --end-line 18021` |
| 19 | 18022 | 19021 | `--start-line 18022 --end-line 19021` |
| 20 | 19022 | 20021 | `--start-line 19022 --end-line 20021` |
| 21 | 20022 | 21021 | `--start-line 20022 --end-line 21021` |
| 22 | 21022 | 22021 | `--start-line 21022 --end-line 22021` |
| 23 | 22022 | 23021 | `--start-line 22022 --end-line 23021` |
| 24 | 23022 | 24021 | `--start-line 23022 --end-line 24021` |
| 25 | 24022 | 25021 | `--start-line 24022 --end-line 25021` |
| 26 | 25022 | 26021 | `--start-line 25022 --end-line 26021` |
| 27 | 26022 | 27021 | `--start-line 26022 --end-line 27021` |
| 28 | 27022 | 27066 | `--start-line 27022 --end-line 27066` |

## 一键循环执行（可选）

在项目根目录下可逐批执行（每批写回后继续下一批）：

```bash
for batch in $(seq 1 28); do
  start=$((21 + (batch - 1) * 1000 + 1))
  end=$((21 + batch * 1000))
  [ $end -gt 27066 ] && end=27066
  echo "=== Batch $batch: lines $start-$end ==="
  python3 scripts/i18n/improve_zh_batch.py --start-line $start --end-line $end --limit 500
done
```

注意：每批的 `--limit 500` 会限制该批内最多替换 500 条；若某批未替换满，可对该批单独再跑一次或调大 `--limit`。

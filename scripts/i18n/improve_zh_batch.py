#!/usr/bin/env python3
"""Replace up to N 'key' => 'key' (self-translated) entries with Chinese.
Reads PhabricatorChineseTranslation.php, finds entries where value equals key,
translates key to Chinese, replaces in place, writes back.

Supports --start-line/--end-line to process only a range of lines (batch mode).
"""

from __future__ import annotations

import argparse
import re
import pathlib

# Single-line entry: 'key' => 'value',
SINGLE_RE = re.compile(
    r"^(\s+)'((?:[^'\\]|\\.)*)'\s*=>\s*'((?:[^'\\]|\\.)*)'\s*,?\s*$", re.M
)

# Prefix patterns (English -> Chinese)
PREFIX_MAP = [
    (r"^No (.+)$", r"无 \1"),
    (r"^Failed to (.+)$", r"无法 \1"),
    (r"^Unable to (.+)$", r"无法 \1"),
    (r"^Could not (.+)$", r"无法 \1"),
    (r"^Expected (.+)$", r"期望 \1"),
    (r"^Configuration (.+)$", r"配置 \1"),
    (r"^Configure (.+)$", r"配置 \1"),
    (r"^Find (.+)$", r"查找 \1"),
    (r"^Create (.+)$", r"创建 \1"),
    (r"^Edit (.+)$", r"编辑 \1"),
    (r"^Delete (.+)$", r"删除 \1"),
    (r"^Add (.+)$", r"添加 \1"),
    (r"^Remove (.+)$", r"移除 \1"),
    (r"^Invalid (.+)$", r"无效的 \1"),
    (r"^Error:?\s*(.*)$", r"错误：\1"),
    (r"^Warning:?\s*(.*)$", r"警告：\1"),
    (r"^Field (.+)$", r"字段 \1"),
    (r"^File (.+)$", r"文件 \1"),
    (r"^Database (.+)$", r"数据库 \1"),
    (r"^Document (.+)$", r"文档 \1"),
    (r"^Content (.+)$", r"内容 \1"),
    (r"^Each (.+)$", r"每个 \1"),
    (r"^Do not (.+)$", r"不要 \1"),
    (r"^You can not (.+)$", r"您无法 \1"),
    (r"^You can only (.+)$", r"您只能 \1"),
    (r"^You do not (.+)$", r"您没有 \1"),
    (r"^The (.+)$", r"该 \1"),
    (r"^This (.+)$", r"此 \1"),
]

# Exact short phrases
EXACT = {
    "ID": "ID",
    "URI": "URI",
    "URL": "URL",
    "Lint": "Lint",
    "Diff": "Diff",
    "I/O": "I/O",
    "MFA": "MFA",
    "PHP": "PHP",
    "PHID": "PHID",
    "PID": "PID",
    "APCu": "APCu",
    "Git": "Git",
    "Conduit": "Conduit",
    "Herald": "Herald",
    "Paste": "Paste",
    "Mocks": "Mocks",
    "Webhook": "Webhook",
    "Webhooks": "Webhooks",
    "Differential": "Differential",
    "Diffusion": "Diffusion",
    "Maniphest": "Maniphest",
    "Subversion": "Subversion",
    "Drydock": "Drydock",
    "Phriction": "Phriction",
    "Phortune": "Phortune",
    "Phurl": "Phurl",
    "Legalpad": "Legalpad",
    "Nuance": "Nuance",
    "Slowvote": "Slowvote",
    "GitHub": "GitHub",
    "Bitbucket": "Bitbucket",
    "Amazon": "亚马逊",
    "Facebook": "Facebook",
    "Chrome": "Chrome",
    "Emacs": "Emacs",
    "Beta": "Beta",
    "Bearer": "Bearer",
    "Almanac": "Almanac",
    "Celerity": "Celerity",
    "Diviner": "Diviner",
    "Doorkeeper": "Doorkeeper",
    "XHProf": "XHProf",
    "Elasticsearch": "Elasticsearch",
    "DarkConsole": "DarkConsole",
    "Finished": "已完成",
    "Flag": "标记",
    "Duplicate Special": "重复专项",
    "Contemplate Infinity": "沉思无限",
    "Eat Paste": "Eat Paste",
    "Copy \"Quack\" to Clipboard": "复制「Quack」到剪贴板",
    "Drop .xhprof Files to Import": "拖放 .xhprof 文件以导入",
    "Drag and drop .xhprof files to import them.": "拖放 .xhprof 文件以导入。",
}


def unesc(s: str) -> str:
    return s.replace("\\\\", "\\").replace("\\'", "'")


def esc(s: str) -> str:
    return s.replace("\\", "\\\\").replace("'", "\\'")


def translate(key: str) -> str | None:
    k = unesc(key)
    if k in EXACT:
        return EXACT[k]
    # Truncated (ends with space or ...)
    if len(k) > 80 or k.endswith(" ") or k.endswith("..."):
        return None
    for pat, repl in PREFIX_MAP:
        m = re.match(pat, k, re.I)
        if m:
            rest = m.group(1)
            if len(rest) > 70:
                return None
            return re.sub(pat, repl, k, count=1, flags=re.I)
    # Short phrase: try word-by-word for known words
    words = k.split()
    if 2 <= len(words) <= 8 and all(len(w) < 25 for w in words):
        t = []
        for w in words:
            if w in EXACT:
                t.append(EXACT[w])
            else:
                t.append(w)
        if t != words:
            return " ".join(t)
    return None


def line_number_at(text: str, pos: int) -> int:
    """Return 1-based line number for character offset pos in text."""
    return text[:pos].count("\n") + 1


def main() -> None:
    default_file = "src/infrastructure/internationalization/translation/PhabricatorChineseTranslation.php"
    ap = argparse.ArgumentParser()
    ap.add_argument("--limit", type=int, default=500, help="Max replacements per run")
    ap.add_argument(
        "--file",
        default=default_file,
        help="Translation PHP file (default: repo-relative path)",
    )
    ap.add_argument(
        "--start-line",
        type=int,
        default=None,
        metavar="N",
        help="Only process entries starting at or after this line (1-based)",
    )
    ap.add_argument(
        "--end-line",
        type=int,
        default=None,
        metavar="N",
        help="Only process entries ending at or before this line (1-based)",
    )
    ap.add_argument("--dry-run", action="store_true", help="Print changes only")
    args = ap.parse_args()

    path = pathlib.Path(args.file)
    if not path.is_absolute():
        # Portable: resolve relative to repo root (script is in scripts/i18n/)
        repo_root = pathlib.Path(__file__).resolve().parent.parent.parent
        path = repo_root / args.file
    text = path.read_text()

    replacements = 0
    for m in SINGLE_RE.finditer(text):
        start_ln = line_number_at(text, m.start())
        if args.start_line is not None and start_ln < args.start_line:
            continue
        if args.end_line is not None and start_ln > args.end_line:
            continue
        indent, key, val = m.group(1), m.group(2), m.group(3)
        if unesc(key) != unesc(val):
            continue
        zh = translate(key)
        if zh is None:
            continue
        new_line = f"{indent}'{key}' => '{esc(zh)}',\n"
        old_line = m.group(0)
        if old_line != new_line:
            text = text.replace(old_line, new_line, 1)
            replacements += 1
            if replacements >= args.limit:
                break
    if args.dry_run:
        print(f"Would replace {replacements} entries")
        return
    path.write_text(text)
    print(f"replaced={replacements}")


if __name__ == "__main__":
    main()

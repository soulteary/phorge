#!/usr/bin/env python3
"""Generate batch zh_CN translations from missing keys.

Supports two modes:
1) map mode:
   - Read missing keys file + key->zh map file, then output PHP lines.
2) auto mode:
   - Build translations heuristically and optionally insert into the
     translation file before a fixed anchor key.
"""

from __future__ import annotations

import argparse
import pathlib
import re
import sys


def php_escape(s: str) -> str:
    s = s.replace("\\", "\\\\")
    # Escape only unescaped single quotes. Missing-key files may already carry
    # source escapes like "\'"; escaping those again would produce "\\'".
    return re.sub(r"(?<!\\)'", r"\\'", s)


def decode_key_from_tsv(s: str) -> str:
    out: list[str] = []
    i = 0
    while i < len(s):
        ch = s[i]
        if ch != "\\":
            out.append(ch)
            i += 1
            continue
        if i + 1 >= len(s):
            out.append("\\")
            break
        nxt = s[i + 1]
        if nxt == "n":
            out.append("\n")
        elif nxt == "t":
            out.append("\t")
        elif nxt == "\\":
            out.append("\\")
        else:
            out.append(nxt)
        i += 2
    return "".join(out)


def php_single_unescape(s: str) -> str:
    return s.replace("\\\\", "\\").replace("\\'", "'")


TOKEN_MAP = {
    "Access": "访问",
    "Account": "账户",
    "Action": "操作",
    "Active": "活跃",
    "Add": "添加",
    "All": "全部",
    "Allow": "允许",
    "Archive": "归档",
    "Build": "构建",
    "Cancel": "取消",
    "Change": "更改",
    "Close": "关闭",
    "Create": "创建",
    "Default": "默认",
    "Delete": "删除",
    "Disable": "禁用",
    "Edit": "编辑",
    "Email": "邮箱",
    "Enable": "启用",
    "Error": "错误",
    "Failed": "失败",
    "File": "文件",
    "Invalid": "无效",
    "Key": "密钥",
    "Login": "登录",
    "Logout": "退出登录",
    "Menu": "菜单",
    "Message": "消息",
    "Name": "名称",
    "New": "新建",
    "No": "无",
    "Open": "打开",
    "Password": "密码",
    "Payment": "支付",
    "Policy": "策略",
    "Project": "项目",
    "Provider": "提供方",
    "Repository": "仓库",
    "Review": "审阅",
    "Rule": "规则",
    "Save": "保存",
    "Search": "搜索",
    "Server": "服务器",
    "Settings": "设置",
    "Status": "状态",
    "Task": "任务",
    "Token": "令牌",
    "Type": "类型",
    "Unknown": "未知",
    "Update": "更新",
    "User": "用户",
    "Users": "用户",
    "View": "查看",
}

EXACT_MAP = {
    "Access Denied": "拒绝访问",
    "Abort Build": "中止构建",
    "Abort Builds": "中止构建",
    "Accepted": "已接受",
    "Active": "活跃",
    "Disabled": "已禁用",
    "Enabled": "已启用",
    "Failed": "失败",
    "Invalid": "无效",
    "No data.": "无数据。",
    "No results found.": "未找到结果。",
    "Unknown": "未知",
}


def load_missing_keys(path: pathlib.Path) -> list[str]:
    keys_in_order: list[str] = []
    for line in path.read_text().splitlines():
        if "\t" not in line:
            continue
        _, key = line.split("\t", 1)
        key = decode_key_from_tsv(key)
        if not key:
            continue
        keys_in_order.append(key)
    return keys_in_order


def load_existing_keys(translation_file: pathlib.Path) -> set[str]:
    text = translation_file.read_text()
    pattern = re.compile(r"^\s+'((?:\\'|[^'])*)'\s*=>", re.M)
    return {php_single_unescape(x) for x in pattern.findall(text)}


def auto_translate(key: str) -> str:
    if key in EXACT_MAP:
        return EXACT_MAP[key]

    # Keep very long or truncated diagnostic strings conservative.
    if len(key) > 90:
        return key
    if key.endswith(" ...") or key.endswith("..."):
        return key

    # Keep placeholder-only patterns as-is.
    if re.fullmatch(r"[%\s,.:;()\-0-9A-Za-z_]+", key) and "%" in key and len(key) < 12:
        return key

    # Basic token-level translation for short UI labels.
    words = key.split(" ")
    if 1 <= len(words) <= 6 and all(len(w) < 24 for w in words):
        translated = []
        changed = False
        for w in words:
            bare = re.sub(r"[^A-Za-z]", "", w)
            if bare in TOKEN_MAP:
                changed = True
                translated.append(w.replace(bare, TOKEN_MAP[bare]))
            else:
                translated.append(w)
        if changed:
            out = " ".join(translated)
            out = out.replace(": ", "：")
            return out

    # Fallback: preserve source to keep placeholder semantics exact.
    return key


def build_php_lines(keys: list[str], key_to_zh: dict[str, str]) -> list[str]:
    out: list[str] = []
    for key in keys:
        zh = key_to_zh.get(key)
        if not zh:
            continue
        k_esc = php_escape(key)
        v_esc = php_escape(zh)
        if "\n" in zh or len(zh) > 70:
            out.append(f"      '{k_esc}' =>\n        '{v_esc}',")
        else:
            out.append(f"      '{k_esc}' => '{v_esc}',")
    return out


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("missing_file", help="Missing keys file (count\\tkey)")
    parser.add_argument(
        "map_file",
        nargs="?",
        default="",
        help="Key-to-zh map file (key\\tzh); optional in --auto mode",
    )
    parser.add_argument(
        "--auto",
        action="store_true",
        help="Auto-generate translations heuristically.",
    )
    parser.add_argument(
        "--translation-file",
        default="src/infrastructure/internationalization/translation/PhabricatorChineseTranslation.php",
        help="Translation PHP file path (used by --auto and --apply).",
    )
    parser.add_argument(
        "--apply",
        action="store_true",
        help="Insert generated entries into translation file before anchor.",
    )
    parser.add_argument(
        "--anchor",
        default="      '%s:' => '%s：',",
        help="Anchor line to insert before when --apply is used.",
    )
    parser.add_argument("--limit", type=int, default=1000, help="Max entries to output")
    args = parser.parse_args()

    missing_path = pathlib.Path(args.missing_file)
    translation_path = pathlib.Path(args.translation_file)
    keys_in_order = load_missing_keys(missing_path)

    if args.auto:
        existing = load_existing_keys(translation_path)
        selected: list[str] = []
        for key in keys_in_order:
            if key in existing:
                continue
            selected.append(key)
            if len(selected) >= args.limit:
                break
        key_to_zh = {k: auto_translate(k) for k in selected}
        out = build_php_lines(selected, key_to_zh)
    else:
        map_path = pathlib.Path(args.map_file)
        key_to_zh: dict[str, str] = {}
        for line in map_path.read_text().splitlines():
            if "\t" not in line:
                continue
            k, v = line.split("\t", 1)
            key_to_zh[k] = v
        out = build_php_lines(keys_in_order[: args.limit], key_to_zh)

    output = "\n".join(out) + ("\n" if out else "")
    if args.apply:
        text = translation_path.read_text()
        idx = text.rfind(args.anchor)
        if idx < 0:
            raise RuntimeError(f"anchor not found: {args.anchor!r}")
        new_text = text[:idx] + output + text[idx:]
        translation_path.write_text(new_text)
        sys.stdout.write(f"applied={len(out)}\n")
    else:
        sys.stdout.write(output)


if __name__ == "__main__":
    main()

#!/usr/bin/env python3
"""Quick static checker for zh_CN translation coverage.

This script is a fallback when `bin/i18n extract` is unavailable.
It scans PHP files for static `pht('...')` strings, compares them with
keys in `PhabricatorChineseTranslation.php`, and prints missing keys by
frequency.
"""

from __future__ import annotations

import argparse
import collections
import pathlib
import re
from typing import Iterable


KEY_PATTERN = re.compile(r"^\s+'((?:\\'|[^'])*)'\s*=>", re.M)

DEFAULT_ROOT = pathlib.Path(__file__).resolve().parents[2]


def php_single_unescape(s: str) -> str:
    return s.replace("\\\\", "\\").replace("\\'", "'")


def encode_key_for_tsv(s: str) -> str:
    return (
        s.replace("\\", "\\\\")
        .replace("\t", "\\t")
        .replace("\n", "\\n")
    )


def decode_php_single_quoted(text: str, start: int) -> tuple[str, int]:
    # Parse a PHP single-quoted string starting at `start`.
    if start >= len(text) or text[start] != "'":
        raise ValueError("not a single-quoted string")

    i = start + 1
    out: list[str] = []
    while i < len(text):
        ch = text[i]
        if ch == "\\":
            if i + 1 >= len(text):
                out.append("\\")
                i += 1
                continue
            nxt = text[i + 1]
            if nxt in ("\\", "'"):
                out.append(nxt)
                i += 2
            else:
                out.append("\\")
                out.append(nxt)
                i += 2
            continue
        if ch == "'":
            return "".join(out), i + 1
        out.append(ch)
        i += 1

    raise ValueError("unterminated single-quoted string")


def skip_ws(text: str, i: int) -> int:
    while i < len(text) and text[i].isspace():
        i += 1
    return i


def parse_static_pht_arg(text: str, arg_start: int) -> tuple[str | None, int]:
    i = skip_ws(text, arg_start)
    if i >= len(text) or text[i] != "'":
        return None, i

    try:
        part, i = decode_php_single_quoted(text, i)
    except ValueError:
        return None, i

    parts = [part]
    while True:
        i = skip_ws(text, i)
        if i < len(text) and text[i] == ".":
            i += 1
            i = skip_ws(text, i)
            if i >= len(text) or text[i] != "'":
                return None, i
            try:
                part, i = decode_php_single_quoted(text, i)
            except ValueError:
                return None, i
            parts.append(part)
            continue
        break

    return "".join(parts), i


def extract_static_pht_keys(text: str) -> list[str]:
    keys: list[str] = []
    for match in re.finditer(r"\bpht\s*\(", text):
        arg_start = match.end()
        key, _ = parse_static_pht_arg(text, arg_start)
        if key is not None:
            keys.append(key)
    return keys


def load_zh_keys(path: pathlib.Path) -> set[str]:
    raw = KEY_PATTERN.findall(path.read_text())
    return {php_single_unescape(x) for x in raw}


def iter_php_files(path: pathlib.Path) -> Iterable[pathlib.Path]:
    yield from path.rglob("*.php")


def collect_pht_keys(src_dir: pathlib.Path, max_len: int) -> collections.Counter[str]:
    counter: collections.Counter[str] = collections.Counter()
    for php_file in iter_php_files(src_dir):
        try:
            text = php_file.read_text()
        except Exception:
            continue
        for key in extract_static_pht_keys(text):
            if len(key) <= max_len:
                counter[key] += 1
    return counter


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "--root",
        default=str(DEFAULT_ROOT),
        help="Repository root path.",
    )
    parser.add_argument(
        "--top",
        type=int,
        default=200,
        help="Show top N missing keys by frequency.",
    )
    parser.add_argument(
        "--max-len",
        type=int,
        default=220,
        help="Ignore very long strings to reduce parser noise.",
    )
    parser.add_argument(
        "--out",
        default="resources/i18n-zh-missing-top200.txt",
        help="Output file path (relative to repo root).",
    )
    args = parser.parse_args()

    root = pathlib.Path(args.root)
    zh_file = root / "src/infrastructure/internationalization/translation/PhabricatorChineseTranslation.php"
    src_dir = root / "src"
    out_file = root / args.out

    zh_keys = load_zh_keys(zh_file)
    pht_counter = collect_pht_keys(src_dir, args.max_len)
    pht_keys = set(pht_counter.keys())

    missing = [key for key in pht_keys if key and key not in zh_keys]
    missing.sort(key=lambda key: (-pht_counter[key], key))

    print(f"zh_keys={len(zh_keys)}")
    print(f"pht_keys={len(pht_keys)}")
    print(f"missing={len(missing)}")

    lines = [f"{pht_counter[key]}\t{encode_key_for_tsv(key)}" for key in missing[: args.top]]
    out_file.write_text("\n".join(lines) + ("\n" if lines else ""))
    print(f"wrote={out_file}")


if __name__ == "__main__":
    main()

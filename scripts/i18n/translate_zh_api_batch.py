#!/usr/bin/env python3
"""Translate self-mapped zh_CN entries via external API in batches.

This script scans `PhabricatorChineseTranslation.php` and only translates entries
where value equals key (untranslated). It supports both:

1) Single-line entries: `'key' => 'value',`
2) Two-line entries:
      'key' =>
        'value',

It updates up to --limit entries per run (default 500).
"""

from __future__ import annotations

import argparse
import json
import os
import pathlib
import re
import time
from dataclasses import dataclass
from difflib import SequenceMatcher
from typing import Any

DEFAULT_FILE = (
    "src/infrastructure/internationalization/translation/"
    "PhabricatorChineseTranslation.php"
)
DEFAULT_MODEL = "kimi-k2.6"
DEFAULT_BASE_URL = "https://api.moonshot.cn/v1"
DEFAULT_TEMPERATURE = 0.6
DEFAULT_TOP_P = 0.95
DEFAULT_MAX_TOKENS = 32768

SINGLE_RE = re.compile(
    r"^(\s+)'((?:[^'\\]|\\.)*)'\s*=>\s*'((?:[^'\\]|\\.)*)'\s*,?\s*$"
)
KEY_LINE_RE = re.compile(r"^(\s+)'((?:[^'\\]|\\.)*)'\s*=>\s*$")
VALUE_LINE_RE = re.compile(r"^(\s+)'((?:[^'\\]|\\.)*)'\s*,?\s*$")

PLACEHOLDER_RE = re.compile(
    r"%(?:\d+\$)?[-+ 0#']*\d*(?:\.\d+)?[bcdeEfFgGosuxX%]"
)
BRACKET_TOKEN_RE = re.compile(r"\[[^\[\]\n]+]")
PROTECTED_TOKEN_RE = re.compile(
    r"%(?:\d+\$)?[-+ 0#']*\d*(?:\.\d+)?[bcdeEfFgGosuxX%]|\[[^\[\]\n]+]"
)


@dataclass
class Candidate:
    key: str
    line_start: int
    line_end: int
    kind: str  # "single" | "split"
    index_a: int
    index_b: int | None
    indent_a: str
    indent_b: str | None


def unesc(s: str) -> str:
    return s.replace("\\\\", "\\").replace("\\'", "'")


def esc(s: str) -> str:
    return s.replace("\\", "\\\\").replace("'", "\\'")


def extract_placeholders(text: str) -> list[str]:
    return PLACEHOLDER_RE.findall(text)


def extract_bracket_tokens(text: str) -> list[str]:
    return BRACKET_TOKEN_RE.findall(text)


def protect_text(text: str) -> tuple[str, dict[str, str]]:
    mapping: dict[str, str] = {}
    index = 0

    def _repl(m: re.Match[str]) -> str:
        nonlocal index
        token = f"__PH_TOKEN_{index}__"
        mapping[token] = m.group(0)
        index += 1
        return token

    return PROTECTED_TOKEN_RE.sub(_repl, text), mapping


def restore_protected_tokens(text: str, mapping: dict[str, str]) -> str:
    out = text
    for token, original in mapping.items():
        out = out.replace(token, original)
    return out


def protected_tokens_intact(text: str, mapping: dict[str, str]) -> bool:
    return all(token in text for token in mapping)


def in_range(line_start: int, line_end: int, start: int | None, end: int | None) -> bool:
    if start is not None and line_start < start:
        return False
    if end is not None and line_end > end:
        return False
    return True


def count_cjk(text: str) -> int:
    return len(re.findall(r"[\u3400-\u4dbf\u4e00-\u9fff\uf900-\ufaff]", text))


def count_latin_words(text: str) -> int:
    return len(re.findall(r"[A-Za-z]{2,}", text))


def should_translate_entry(key_raw: str, val_raw: str) -> bool:
    key = unesc(key_raw)
    val = unesc(val_raw)

    # Fully untranslated.
    if key == val:
        return True

    # Detect partially translated strings like:
    # "该 type of a blueprint can not be changed ..."
    cjk = count_cjk(val)
    latin_words = count_latin_words(val)
    if cjk == 0 or latin_words < 3:
        return False

    similarity = SequenceMatcher(a=key.lower(), b=val.lower()).ratio()
    # High similarity plus mixed language strongly suggests partial translation.
    return similarity >= 0.60


def resolve_path(path_arg: str) -> pathlib.Path:
    path = pathlib.Path(path_arg)
    if path.is_absolute():
        return path
    repo_root = pathlib.Path(__file__).resolve().parent.parent.parent
    return repo_root / path


def collect_candidates(
    lines: list[str], start_line: int | None, end_line: int | None, limit: int
) -> list[Candidate]:
    out: list[Candidate] = []
    i = 0
    total = len(lines)
    while i < total:
        line = lines[i]
        line_no = i + 1

        m_single = SINGLE_RE.match(line)
        if m_single:
            indent, key_raw, val_raw = m_single.group(1), m_single.group(2), m_single.group(3)
            if should_translate_entry(key_raw, val_raw) and in_range(
                line_no, line_no, start_line, end_line
            ):
                out.append(
                    Candidate(
                        key=key_raw,
                        line_start=line_no,
                        line_end=line_no,
                        kind="single",
                        index_a=i,
                        index_b=None,
                        indent_a=indent,
                        indent_b=None,
                    )
                )
                if len(out) >= limit:
                    break
            i += 1
            continue

        m_key = KEY_LINE_RE.match(line)
        if m_key and i + 1 < total:
            next_line = lines[i + 1]
            m_val = VALUE_LINE_RE.match(next_line)
            if m_val:
                key_raw = m_key.group(2)
                val_raw = m_val.group(2)
                if should_translate_entry(key_raw, val_raw):
                    start_no = line_no
                    end_no = i + 2
                    if in_range(start_no, end_no, start_line, end_line):
                        out.append(
                            Candidate(
                                key=key_raw,
                                line_start=start_no,
                                line_end=end_no,
                                kind="split",
                                index_a=i,
                                index_b=i + 1,
                                indent_a=m_key.group(1),
                                indent_b=m_val.group(1),
                            )
                        )
                        if len(out) >= limit:
                            break
            i += 1
            continue

        i += 1

    return out


def build_messages(batch: list[tuple[int, str]]) -> list[dict[str, str]]:
    payload_lines: list[str] = []
    for idx, text in batch:
        payload_lines.append(f"[[[{idx}]]] {text}")
    system_prompt = (
        "你是专业软件本地化翻译助手。将英文翻译为自然、准确的简体中文。"
        "你只能翻译自然语言文本，不要改写格式控制标记。"
        "严格保持占位符与转义不变，例如 %s %d %3$s %% 和 \\'。"
        "方括号占位符必须原样保留，例如 [Calendar] [File] [Paste]。"
        "对于保护标记 __PH_TOKEN_N__，必须逐字原样保留，不可删除、改写或翻译。"
        "如果原文是部分翻译（中英混合），请输出完整、自然的中文翻译。"
        "保留产品名/专有名词（如 PHID、Herald、Differential）风格一致。"
        "只输出纯文本，不要 JSON，不要 Markdown，不要额外解释。"
    )
    user_prompt = (
        "请逐条翻译下列英文句子，并严格按同样格式逐行输出：\n"
        "[[[id]]] 中文翻译\n"
        "要求：\n"
        "1) 每条都保留原 id，不可新增或遗漏；\n"
        "2) 只输出翻译结果行，顺序保持不变；\n"
        "3) 不要输出空行、注释、前后缀。\n"
        "输入：\n"
        + "\n".join(payload_lines)
    )
    return [
        {"role": "system", "content": system_prompt},
        {"role": "user", "content": user_prompt},
    ]


def parse_text_response(content: str) -> dict[int, str]:
    out: dict[int, str] = {}
    for raw in content.splitlines():
        line = raw.strip()
        if not line:
            continue
        m = re.match(r"^\[\[\[(\d+)]]]\s*(.*)$", line)
        if m:
            out[int(m.group(1))] = m.group(2).strip()
    return out


def request_translations(
    client: Any,
    model: str,
    batch: list[tuple[int, str]],
    retries: int,
    retry_sleep: float,
    request_timeout: float,
    temperature: float,
    top_p: float,
    max_tokens: int,
) -> dict[int, str]:
    for attempt in range(1, retries + 1):
        try:
            completion = client.chat.completions.create(
                model=model,
                messages=build_messages(batch),
                temperature=temperature,
                top_p=top_p,
                max_tokens=max_tokens,
                response_format={"type": "text"},
                stream=False,
                timeout=request_timeout,
                extra_body={"thinking": {"type": "disabled"}},
            )
            content = completion.choices[0].message.content or ""
            parsed = parse_text_response(content)
            mapped: dict[int, str] = {}
            for idx, _ in batch:
                if idx in parsed:
                    mapped[idx] = parsed[idx]
            return mapped
        except Exception:
            if attempt >= retries:
                raise
            time.sleep(retry_sleep * attempt)
    return {}


def validate_translation(src: str, translated: str) -> bool:
    if not translated.strip():
        return False
    if extract_placeholders(src) != extract_placeholders(translated):
        return False
    if extract_bracket_tokens(src) != extract_bracket_tokens(translated):
        return False
    return True


def apply_translation(lines: list[str], candidate: Candidate, zh: str) -> None:
    zh_escaped = esc(zh)
    if candidate.kind == "single":
        lines[candidate.index_a] = (
            f"{candidate.indent_a}'{candidate.key}' => '{zh_escaped}',\n"
        )
        return

    if candidate.index_b is None or candidate.indent_b is None:
        raise RuntimeError("invalid split candidate")
    lines[candidate.index_b] = f"{candidate.indent_b}'{zh_escaped}',\n"


def chunked(items: list[tuple[int, str]], size: int) -> list[list[tuple[int, str]]]:
    return [items[i : i + size] for i in range(0, len(items), size)]


def load_progress(progress_path: pathlib.Path) -> dict[str, int]:
    if not progress_path.exists():
        return {"next_line": 1}
    try:
        data = json.loads(progress_path.read_text())
    except Exception:
        return {"next_line": 1}
    next_line = int(data.get("next_line", 1))
    return {"next_line": max(1, next_line)}


def save_progress(progress_path: pathlib.Path, next_line: int) -> None:
    payload = {"next_line": max(1, next_line), "updated_at": int(time.time())}
    progress_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n")


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--file", default=DEFAULT_FILE, help="Translation file path")
    ap.add_argument("--start-line", type=int, default=None, metavar="N")
    ap.add_argument("--end-line", type=int, default=None, metavar="N")
    ap.add_argument(
        "--progress-file",
        default="",
        help="Progress file path. Default: <translation-file>.progress.json",
    )
    ap.add_argument(
        "--reset-progress",
        action="store_true",
        help="Reset progress cursor to line 1 before processing",
    )
    ap.add_argument("--limit", type=int, default=500, help="Max entries per run")
    ap.add_argument("--batch-size", type=int, default=90, help="Entries per API call")
    ap.add_argument("--model", default=DEFAULT_MODEL, help="Model name")
    ap.add_argument("--base-url", default=DEFAULT_BASE_URL, help="API base URL")
    ap.add_argument(
        "--temperature",
        type=float,
        default=DEFAULT_TEMPERATURE,
        help="Sampling temperature",
    )
    ap.add_argument(
        "--top-p",
        type=float,
        default=DEFAULT_TOP_P,
        help="Nucleus sampling top_p",
    )
    ap.add_argument(
        "--max-tokens",
        type=int,
        default=DEFAULT_MAX_TOKENS,
        help="Max output tokens per API call",
    )
    ap.add_argument("--retry", type=int, default=2, help="Retries per API call")
    ap.add_argument(
        "--retry-sleep",
        type=float,
        default=1.0,
        help="Base sleep seconds between retries",
    )
    ap.add_argument(
        "--request-interval",
        type=float,
        default=0.5,
        help="Sleep seconds between successful API calls",
    )
    ap.add_argument(
        "--request-timeout",
        type=float,
        default=120.0,
        help="Timeout seconds per API request",
    )
    ap.add_argument("--dry-run", action="store_true", help="Do not call API/write file")
    args = ap.parse_args()

    if args.batch_size <= 0:
        raise ValueError("--batch-size must be > 0")
    if args.limit <= 0:
        raise ValueError("--limit must be > 0")

    path = resolve_path(args.file)
    text = path.read_text()
    lines = text.splitlines(keepends=True)

    progress_path = (
        resolve_path(args.progress_file)
        if args.progress_file
        else pathlib.Path(str(path) + ".progress.json")
    )
    if args.reset_progress:
        save_progress(progress_path, 1)
    progress = load_progress(progress_path)
    effective_start_line = args.start_line
    if effective_start_line is None:
        effective_start_line = progress.get("next_line", 1)

    candidates = collect_candidates(lines, effective_start_line, args.end_line, args.limit)
    if not candidates and args.start_line is None and effective_start_line > 1:
        # Recheck once from file head to catch any earlier skipped entries.
        candidates = collect_candidates(lines, 1, args.end_line, args.limit)
        if candidates:
            effective_start_line = 1
    if args.dry_run:
        print(f"would_translate={len(candidates)}")
        print(f"effective_start_line={effective_start_line}")
        for c in candidates[:10]:
            preview = unesc(c.key)
            if len(preview) > 96:
                preview = preview[:93] + "..."
            print(f"- line {c.line_start}: {preview}")
        return
    if not candidates:
        print("selected=0")
        print("replaced=0")
        print("skipped_invalid=0")
        print(
            "hint=no untranslated entries in this line range; "
            "run --dry-run without range to find available entries"
        )
        return
    print(
        f"selected={len(candidates)} batch_size={args.batch_size} model={args.model} "
        f"start_line={effective_start_line}",
        flush=True,
    )

    api_key = os.getenv("MOONSHOT_API_KEY")
    if not api_key:
        raise RuntimeError("MOONSHOT_API_KEY is required")

    try:
        from openai import OpenAI
    except Exception as exc:
        raise RuntimeError("openai package is required: pip install openai") from exc

    client = OpenAI(api_key=api_key, base_url=args.base_url)

    pending = [(idx, unesc(c.key)) for idx, c in enumerate(candidates)]
    protected_map_by_idx: dict[int, dict[str, str]] = {}
    protected_pending: list[tuple[int, str]] = []
    for idx, src in pending:
        masked, mapping = protect_text(src)
        protected_pending.append((idx, masked))
        protected_map_by_idx[idx] = mapping
    replaced = 0
    skipped_invalid = 0

    batches = chunked(protected_pending, args.batch_size)
    total_batches = len(batches)
    for batch_idx, batch in enumerate(batches, start=1):
        print(
            f"batch={batch_idx}/{total_batches} items={len(batch)} requesting...",
            flush=True,
        )
        result = request_translations(
            client=client,
            model=args.model,
            batch=batch,
            retries=args.retry,
            retry_sleep=args.retry_sleep,
            request_timeout=args.request_timeout,
            temperature=args.temperature,
            top_p=args.top_p,
            max_tokens=args.max_tokens,
        )
        print(
            f"batch={batch_idx}/{total_batches} received={len(result)}",
            flush=True,
        )
        batch_replaced = 0
        for idx, _masked_src in batch:
            zh_masked = result.get(idx, "")
            mapping = protected_map_by_idx.get(idx, {})
            if not protected_tokens_intact(zh_masked, mapping):
                skipped_invalid += 1
                if zh_masked:
                    print(
                        f"skipped_invalid id={idx} reason=protected_token_changed",
                        flush=True,
                    )
                continue

            zh = restore_protected_tokens(zh_masked, mapping)
            src = pending[idx][1]
            if validate_translation(src, zh):
                apply_translation(lines, candidates[idx], zh)
                replaced += 1
                batch_replaced += 1
                print(f"translated id={idx}", flush=True)
                print(f"  en: {src}", flush=True)
                print(f"  zh: {zh}", flush=True)
            else:
                skipped_invalid += 1
                if zh:
                    print(
                        f"skipped_invalid id={idx} reason=placeholder_mismatch",
                        flush=True,
                    )
        if batch_replaced > 0:
            path.write_text("".join(lines))
            print(
                f"batch={batch_idx}/{total_batches} wrote={batch_replaced}",
                flush=True,
            )
        batch_last_line = max(candidates[idx].line_end for idx, _ in batch)
        save_progress(progress_path, batch_last_line + 1)
        print(
            f"batch={batch_idx}/{total_batches} progress_next_line={batch_last_line + 1}",
            flush=True,
        )
        time.sleep(args.request_interval)

    print(f"selected={len(candidates)}")
    print(f"replaced={replaced}")
    print(f"skipped_invalid={skipped_invalid}")


if __name__ == "__main__":
    main()

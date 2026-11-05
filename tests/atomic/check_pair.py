#!/usr/bin/env python3
"""Fail closed when workflow input drifts from the reviewed paired release."""
import argparse
import json
import os
from pathlib import Path
import re


def validate(revision, expected):
    if not re.fullmatch(r"[0-9a-f]{40}", revision or ""):
        raise ValueError("An immutable lowercase 40-character Gorge revision is required.")
    if revision != expected:
        raise ValueError("Gorge revision differs from tests/atomic/paired-gorge.json; review the pair together.")


def resolve(event, override, expected):
    validate(expected, expected)
    if override:
        if event != "workflow_dispatch":
            raise ValueError("Paired-source overrides require workflow_dispatch.")
        validate(override, expected)
    return expected


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--print-revision", action="store_true")
    args = parser.parse_args()
    expected = json.loads(Path(__file__).with_name("paired-gorge.json").read_text())["revision"]
    revision = resolve(os.environ.get("GITHUB_EVENT_NAME", ""),
                       os.environ.get("GORGE_REF", ""), expected)
    print(revision if args.print_revision else "Reviewed immutable Gorge pair verified.")

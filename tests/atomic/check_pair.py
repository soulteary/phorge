#!/usr/bin/env python3
"""Fail closed when workflow input drifts from the reviewed paired release."""
import json
import os
from pathlib import Path
import re


def validate(revision, expected):
    if not re.fullmatch(r"[0-9a-f]{40}", revision or ""):
        raise ValueError("An immutable lowercase 40-character Gorge revision is required.")
    if revision != expected:
        raise ValueError("Gorge revision differs from tests/atomic/paired-gorge.json; review the pair together.")


if __name__ == "__main__":
    expected = json.loads(Path(__file__).with_name("paired-gorge.json").read_text())["revision"]
    validate(os.environ.get("GORGE_REF", os.environ.get("GORGE_CONTRACT_REF", "")), expected)
    print("Reviewed immutable Gorge pair verified.")

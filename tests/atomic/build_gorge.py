#!/usr/bin/env python3
"""Build atomic fixtures with the immutable paired Gorge source and build bases."""

import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import subprocess
import sys


def inspect_bases(gorge, lock, runner):
    # The paired repository owns its builder/runtime requirements and the
    # multi-platform digest validator. Do not borrow Phorge's older fixture
    # builders or override Gorge's Dockerfile toolchain-version guard.
    spec = importlib.util.spec_from_file_location(
        "atomic_paired_base_images", gorge / "deploy/release/base_images.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module.inspect_locked_images(lock, runner=runner)


def build(gorge, revision, runner=subprocess.run):
    gorge = Path(gorge).resolve()
    if not isinstance(revision, str) or not re.fullmatch(r"[0-9a-f]{40}", revision):
        raise ValueError("The paired Gorge revision must be an immutable commit.")

    def git(*arguments):
        return runner(["git", "-C", str(gorge), *arguments],
                      capture_output=True, text=True, check=True).stdout

    if git("rev-parse", "HEAD").strip() != revision:
        raise ValueError("Paired Gorge checkout differs from the requested commit.")
    if git("status", "--porcelain", "--untracked-files=all").strip():
        raise ValueError("Paired Gorge source must be a clean checkout.")
    # Read the lock from the reviewed commit itself. A missing paired lock is
    # an error, not a reason to silently fall back to Phorge's production lock.
    raw = git("show", revision + ":deploy/release/build-bases.json")
    lock = json.loads(raw)
    images = lock.get("images") if isinstance(lock, dict) else None
    if not isinstance(images, dict) or set(images) != {"go", "alpine"}:
        raise ValueError("Paired build bases must contain exactly go and alpine images.")
    verified = inspect_bases(gorge, lock, runner)
    for service, port in (("gorge-file-storage", "8100"),
                          ("gorge-image", "8190"), ("gorge-render", "8140"),
                          ("gorge-search", "8120")):
        runner(["docker", "build", "--build-arg", "SERVICE=" + service,
                "--build-arg", "PORT=" + port,
                "--build-arg", "GO_BASE_IMAGE=" + images["go"],
                "--build-arg", "RUNTIME_BASE_IMAGE=" + images["alpine"],
                "--label", "phorge.gorge-revision=" + revision,
                "--tag", service + ":atomic", str(gorge / "go")], check=True)
    return {"pairedGorgeRevision": revision,
            "buildBasesSHA256": hashlib.sha256(raw.encode()).hexdigest(),
            "verifiedBases": verified}


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--gorge-dir", required=True, type=Path)
    parser.add_argument("--revision", required=True)
    options = parser.parse_args()
    try:
        print(json.dumps(build(options.gorge_dir, options.revision), indent=2))
    except (OSError, ValueError, subprocess.CalledProcessError) as error:
        print(f"Paired fixture build failed: {error}", file=sys.stderr)
        sys.exit(1)

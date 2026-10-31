#!/usr/bin/env python3
"""Run native packages with explicit disposable backends, never deployed config."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import signal
import subprocess
import sys
import urllib.parse


def configuration(environment):
    if environment.get("ATOMIC_TEST_DISPOSABLE") != "1":
        raise ValueError("Set ATOMIC_TEST_DISPOSABLE=1 for disposable services only.")
    revision = environment.get("GORGE_CONTRACT_REF", "")
    if not re.fullmatch(r"[0-9a-f]{40}", revision):
        raise ValueError("GORGE_CONTRACT_REF must name the paired immutable Gorge commit.")
    if environment.get("PHABRICATOR_ENV"):
        raise ValueError("PHABRICATOR_ENV is set; refusing to load instance configuration.")
    port = environment.get("GORGE_TEST_MYSQL_PORT", "")
    if not port.isdigit() or not 1 <= int(port) <= 65535:
        raise ValueError("Set a valid GORGE_TEST_MYSQL_PORT.")
    password = environment.get("GORGE_TEST_MYSQL_PASSWORD", "")
    if not password:
        raise ValueError("Set the disposable GORGE_TEST_MYSQL_PASSWORD.")
    urls = {}
    for kind in ("FILE", "IMAGE", "RENDER"):
        value = environment.get(f"GORGE_TEST_{kind}_URL", "")
        uri = urllib.parse.urlsplit(value)
        if (uri.scheme not in ("http", "https") or
                uri.hostname not in ("127.0.0.1", "localhost", "::1") or
                uri.username or uri.password or uri.path not in ("", "/") or
                uri.query or uri.fragment or not uri.port):
            raise ValueError(f"GORGE_TEST_{kind}_URL must be an explicit loopback service URL.")
        token = environment.get(f"GORGE_TEST_{kind}_TOKEN", "")
        if not token:
            raise ValueError(f"Set the disposable GORGE_TEST_{kind}_TOKEN.")
        urls[kind] = value.rstrip("/")
    namespace = "atomic_gate_" + os.urandom(8).hex()
    return {
        "mysql.host": "127.0.0.1", "mysql.port": int(port),
        "mysql.user": "root", "mysql.pass": password,
        "storage.default-namespace": namespace,
        "phabricator.cache-namespace": namespace,
        "phabricator.base-uri": "http://phorge-atomic.invalid/",
        "cluster.databases": [], "cluster.instance": None,
        "gorge.file.uri": urls["FILE"],
        "gorge.file.token": environment["GORGE_TEST_FILE_TOKEN"],
        "gorge.image.uri": urls["IMAGE"],
        "gorge.image.token": environment["GORGE_TEST_IMAGE_TOKEN"],
        "gorge.render.uri": urls["RENDER"],
        "gorge.render.token": environment["GORGE_TEST_RENDER_TOKEN"],
        "gorge.service-policy": "required",
        "gorge.image.mode": "gorge", "gorge.image.builtin-mode": "gorge",
        "phabricator.silent": True,
    }


def reserve_configuration(root, config):
    local = root / "conf/local"
    if (root / "conf").is_symlink() or local.is_symlink():
        raise ValueError("Refusing a symlinked instance configuration directory.")
    for name in ("local.json", "deployment.json", "ENVIRONMENT"):
        if (local / name).exists() or (local / name).is_symlink():
            raise ValueError(f"Existing conf/local/{name}; use a fresh test worktree.")
    local.mkdir(parents=True, exist_ok=True)
    path = local / "local.json"
    payload = (json.dumps(config, ensure_ascii=True, indent=2) + "\n").encode()
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(descriptor, "wb") as stream:
        stream.write(payload)
    return path, hashlib.sha256(payload).digest()


def remove_configuration(path, digest):
    if path.is_symlink() or not path.is_file():
        raise RuntimeError("Temporary configuration was replaced; refusing to remove it.")
    if hashlib.sha256(path.read_bytes()).digest() != digest:
        raise RuntimeError("Temporary configuration changed; refusing to remove user changes.")
    path.unlink()


def run(arguments=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--package", action="append", default=[])
    parser.add_argument("--output", required=True, type=Path)
    options = parser.parse_args(arguments)
    root = Path(__file__).resolve().parents[2]
    options.output = options.output.resolve()
    options.output.mkdir(parents=True, exist_ok=True)
    config = configuration(os.environ)
    for package in options.package:
        if not re.fullmatch(r"D[0-9]{2}", package):
            raise ValueError("Invalid package identifier.")
    path, digest = reserve_configuration(root, config)
    result = None
    try:
        child = dict(os.environ, ATOMIC_TEST_PREPARED="1")
        command = ["php", "tests/atomic/run.php", "--report",
                   str(options.output / "native-results.json")]
        for package in options.package:
            command.extend(["--package", package])
        # Native fixtures allocate their own random namespaces, run native
        # storage upgrades, and remove those databases at PHP process shutdown.
        with (options.output / "native.log").open("w") as output:
            result = subprocess.run(command, cwd=root, env=child,
                                    stdout=output, stderr=subprocess.STDOUT,
                                    timeout=1800, check=False)
        report = options.output / "native-results.json"
        if not report.exists():
            raise RuntimeError("Native runner failed without test-result evidence; inspect native.log.")
        evidence = json.loads(report.read_text())
        evidence["gitHead"] = subprocess.check_output(
            ["git", "rev-parse", "HEAD"], cwd=root, text=True).strip()
        evidence["worktreeDirty"] = bool(subprocess.check_output(
            ["git", "status", "--porcelain"], cwd=root, text=True).strip())
        evidence["pairedGorgeRevision"] = os.environ["GORGE_CONTRACT_REF"]
        evidence["scope"] = "Selected native package regressions; not full application compatibility."
        report.write_text(json.dumps(evidence, indent=2) + "\n")
        # Emit redacted native output, including failures, into CI diagnostics.
        log = (options.output / "native.log").read_text()
        for name in ("GORGE_TEST_MYSQL_PASSWORD", "GORGE_TEST_FILE_TOKEN", "GORGE_TEST_IMAGE_TOKEN", "GORGE_TEST_RENDER_TOKEN"):
            log = log.replace(os.environ[name], "[redacted]")
        (options.output / "native.log").write_text(log)
        print(log, end="")
        if result.returncode or not evidence.get("passed"):
            return 1
        return 0
    finally:
        try:
            log_path = options.output / "native.log"
            if log_path.exists():
                log = log_path.read_text()
                for name in ("GORGE_TEST_MYSQL_PASSWORD", "GORGE_TEST_FILE_TOKEN",
                             "GORGE_TEST_IMAGE_TOKEN", "GORGE_TEST_RENDER_TOKEN"):
                    log = log.replace(os.environ[name], "[redacted]")
                log_path.write_text(log)
        finally:
            remove_configuration(path, digest)


if __name__ == "__main__":
    def interrupt(_number, _frame):
        raise KeyboardInterrupt()
    signal.signal(signal.SIGTERM, interrupt)
    try:
        sys.exit(run())
    except (ValueError, RuntimeError, subprocess.TimeoutExpired) as error:
        print(f"Atomic regression gate failed: {error}", file=sys.stderr)
        sys.exit(1)

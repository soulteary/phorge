#!/usr/bin/env python3
"""Run native packages with explicit disposable backends, never deployed config."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import signal
import stat
import subprocess
import sys
import tempfile
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


def capture_source(root):
    """Keep Git's actual status, including all untracked source and submodules."""
    head = subprocess.check_output(
        ["git", "rev-parse", "HEAD"], cwd=root, text=True).strip()
    status = subprocess.check_output(
        ["git", "status", "--porcelain=v1", "--untracked-files=all",
         "--ignore-submodules=none"], cwd=root, text=True)
    return {"gitHead": head, "worktreeDirty": bool(status), "worktreeStatus": status}


def record_source_provenance(evidence, before, after, require_clean):
    latest = after if after is not None else before
    evidence.update({"gitHead": latest["gitHead"],
                     "worktreeDirty": latest["worktreeDirty"],
                     "sourceProvenance": {"requireCleanWorktree": require_clean,
                                          "before": before, "after": after}})
    errors = []
    if require_clean:
        if before["worktreeDirty"]:
            errors.append("Phorge worktree was not clean before testing.")
        if after is not None:
            if after["worktreeDirty"]:
                errors.append("Phorge worktree was not clean after testing.")
            if after["gitHead"] != before["gitHead"]:
                errors.append("Phorge HEAD changed while testing.")
    evidence["sourceErrors"] = errors
    if errors:
        evidence["passed"] = False
    return errors


def publish_report(path, evidence):
    """Publish only at an absent path, without following or replacing a new owner."""
    descriptor, staging = tempfile.mkstemp(prefix=".atomic-report-", dir=path.parent)
    try:
        with os.fdopen(descriptor, "w") as output:
            output.write(json.dumps(evidence, indent=2) + "\n")
        try:
            os.link(staging, path)
        except FileExistsError as error:
            raise RuntimeError("Result evidence was replaced; refusing to overwrite it.") from error
    finally:
        Path(staging).unlink()


def verify_owned_log(path, identity):
    try:
        current = path.lstat()
    except FileNotFoundError as error:
        raise RuntimeError("Native log evidence was replaced; refusing to modify it.") from error
    if (not stat.S_ISREG(current.st_mode) or
            (current.st_dev, current.st_ino) != identity):
        raise RuntimeError("Native log evidence was replaced; refusing to modify it.")


def run(arguments=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--package", action="append", default=[])
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument("--require-clean-worktree", action="store_true",
                        help="Require a clean Phorge worktree before/after testing and unchanged HEAD.")
    options = parser.parse_args(arguments)
    root = Path(__file__).resolve().parents[2]
    options.output = options.output.resolve()
    options.output.mkdir(parents=True, exist_ok=True)
    report = options.output / "native-results.json"
    if report.is_symlink():
        raise ValueError("Refusing symlinked native result evidence.")
    # Invalidate old success before any configuration or provenance refusal.
    report.unlink(missing_ok=True)
    log_path = options.output / "native.log"
    owned_log = None
    log_stream = None
    log_text = None
    before = capture_source(root)
    evidence = {"passed": False, "results": [], "errors": []}
    produced_evidence = False
    path = digest = None
    result = None
    try:
        errors = record_source_provenance(evidence, before, None,
                                          options.require_clean_worktree)
        if not errors:
            config = configuration(os.environ)
            for package in options.package:
                if not re.fullmatch(r"D[0-9]{2}", package):
                    raise ValueError("Invalid package identifier.")
            path, digest = reserve_configuration(root, config)
            child = dict(os.environ, ATOMIC_TEST_PREPARED="1")
            # Each child receives a fresh private path, so an early exit cannot
            # reuse successful native evidence from a previous checkout.
            with tempfile.TemporaryDirectory(prefix=".native-current-", dir=options.output) as current:
                current_report = Path(current) / "results.json"
                command = ["php", "tests/atomic/run.php", "--report", str(current_report)]
                for package in options.package:
                    command.extend(["--package", package])
                # Native fixtures allocate their own random namespaces, run native
                # upgrades, and remove those databases at PHP process shutdown.
                if log_path.is_symlink():
                    raise ValueError("Refusing symlinked native log evidence.")
                descriptor = os.open(log_path, os.O_RDWR | os.O_CREAT | os.O_TRUNC |
                                     os.O_NOFOLLOW, 0o600)
                log_stream = os.fdopen(descriptor, "w+")
                identity = os.fstat(log_stream.fileno())
                owned_log = (identity.st_dev, identity.st_ino)
                result = subprocess.run(command, cwd=root, env=child,
                                        stdout=log_stream, stderr=subprocess.STDOUT,
                                        timeout=1800, check=False)
                if not current_report.is_file():
                    raise RuntimeError("Native runner failed without test-result evidence; inspect native.log.")
                evidence = json.loads(current_report.read_text())
                produced_evidence = True
                if result.returncode:
                    evidence["passed"] = False
    except BaseException:
        evidence["passed"] = False
        raise
    finally:
        try:
            if owned_log is not None:
                verify_owned_log(log_path, owned_log)
                log_stream.seek(0)
                log = log_stream.read()
                for name in ("GORGE_TEST_MYSQL_PASSWORD", "GORGE_TEST_FILE_TOKEN",
                             "GORGE_TEST_IMAGE_TOKEN", "GORGE_TEST_RENDER_TOKEN"):
                    if os.environ.get(name):
                        log = log.replace(os.environ[name], "[redacted]")
                log_stream.seek(0)
                log_stream.write(log)
                log_stream.truncate()
                log_stream.flush()
                verify_owned_log(log_path, owned_log)
                log_text = log
        except BaseException:
            evidence["passed"] = False
            raise
        finally:
            if log_stream is not None:
                log_stream.close()
            if path is not None:
                remove_configuration(path, digest)
            record_source_provenance(evidence, before, capture_source(root),
                                     options.require_clean_worktree)
            evidence["pairedGorgeRevision"] = os.environ.get("GORGE_CONTRACT_REF", "")
            evidence["scope"] = "Selected native package regressions; not full application compatibility."
            if produced_evidence or options.require_clean_worktree:
                publish_report(report, evidence)
    if log_text is not None:
        print(log_text, end="")
    if evidence.get("sourceErrors"):
        print(" ".join(evidence["sourceErrors"]), file=sys.stderr)
    return 0 if result is not None and not result.returncode and evidence.get("passed") else 1


if __name__ == "__main__":
    def interrupt(_number, _frame):
        raise KeyboardInterrupt()
    signal.signal(signal.SIGTERM, interrupt)
    try:
        sys.exit(run())
    except (ValueError, RuntimeError, subprocess.TimeoutExpired) as error:
        print(f"Atomic regression gate failed: {error}", file=sys.stderr)
        sys.exit(1)

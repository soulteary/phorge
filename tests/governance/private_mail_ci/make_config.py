#!/usr/bin/env python3
"""Create a private test config from explicit CI build provenance, not receipts."""
import argparse
import json
import os
from pathlib import Path
import subprocess
from runtime import SOURCE_FILES, digest, private_file, validate_config

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--source-root', required=True)
parser.add_argument('--source-revision', required=True)
parser.add_argument('--binary-manifest', required=True)
parser.add_argument('--gorge-revision', required=True)
parser.add_argument('--php', required=True)
parser.add_argument('--expected-pure-methods', required=True, type=int)
parser.add_argument('--database-port', type=int, default=3306)
parser.add_argument('--database-user', default='root')
parser.add_argument('--database-password-env', default='GORGE_TEST_MYSQL_PASSWORD')
parser.add_argument('--task-wire', action='store_true', help='Pin the additional closed Task harness source set; does not authorize execution.')
parser.add_argument('--task-browser', action='store_true', help='Additional fixed browser hold sources; requires --task-wire.')
parser.add_argument('--task-source-manifest', help='Exclusive private typed Task source sidecar output.')
parser.add_argument('--output', required=True)
args = parser.parse_args()
if args.task_wire != bool(args.task_source_manifest):parser.error('--task-wire requires --task-source-manifest and conversely')
if args.task_browser and not args.task_wire:parser.error('--task-browser requires --task-wire')
source = Path(args.source_root).resolve()
if subprocess.check_output(['git', '-C', str(source), 'rev-parse', 'HEAD'], text=True).strip() != args.source_revision:
    raise SystemExit('Source revision mismatch.')
binaries = json.loads(private_file(args.binary_manifest).read_text())
task_files = frozenset()
if args.task_wire:
    from run_task import TASK_FILES
    from task_source import pure_source_files,declared_files
    task_files = TASK_FILES | pure_source_files(source)
    if args.task_browser:
        from run_task import BROWSER_FILES
        task_files = task_files | BROWSER_FILES
if args.task_wire:task_files=declared_files(source,TASK_FILES,BROWSER_FILES if args.task_browser else frozenset())
config = {'schema': 1, 'engine': 'linux-local', 'source_root': str(source), 'source_revision': args.source_revision,
          'source_sha256': {name: digest(source / name) for name in sorted(SOURCE_FILES | task_files)},
          'binaries': binaries, 'gorge_revision': args.gorge_revision, 'php': str(Path(args.php).resolve()),
          'database': {'host': '127.0.0.1', 'port': args.database_port, 'database': 'governance_fixture_bootstrap',
                       'user': args.database_user, 'password_env': args.database_password_env},
          'expected_pure_methods': args.expected_pure_methods}
validate_config(config)
fd = os.open(args.output, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
with os.fdopen(fd, 'w') as output:
    json.dump(config, output, indent=2)
    output.write('\n')

if args.task_wire:
    from task_source import typed_manifest
    sidecar=typed_manifest(source,args.source_revision,frozenset(task_files))
    side_fd=os.open(args.task_source_manifest,os.O_CREAT|os.O_EXCL|os.O_WRONLY|os.O_NOFOLLOW,0o600)
    with os.fdopen(side_fd,'w')as output:
        json.dump(sidecar,output,sort_keys=True);output.write('\n')

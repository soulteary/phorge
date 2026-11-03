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
parser.add_argument('--output', required=True)
args = parser.parse_args()
source = Path(args.source_root).resolve()
if subprocess.check_output(['git', '-C', str(source), 'rev-parse', 'HEAD'], text=True).strip() != args.source_revision:
    raise SystemExit('Source revision mismatch.')
binaries = json.loads(private_file(args.binary_manifest).read_text())
config = {'schema': 1, 'engine': 'linux-local', 'source_root': str(source), 'source_revision': args.source_revision,
          'source_sha256': {name: digest(source / name) for name in sorted(SOURCE_FILES)},
          'binaries': binaries, 'gorge_revision': args.gorge_revision, 'php': str(Path(args.php).resolve()),
          'database': {'host': '127.0.0.1', 'port': args.database_port, 'database': 'governance_fixture_bootstrap',
                       'user': args.database_user, 'password_env': args.database_password_env},
          'expected_pure_methods': args.expected_pure_methods}
validate_config(config)
fd = os.open(args.output, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
with os.fdopen(fd, 'w') as output:
    json.dump(config, output, indent=2)
    output.write('\n')

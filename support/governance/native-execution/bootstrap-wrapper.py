#!/usr/bin/env python3
"""Capture actual native console writes without publishing console contents.

A trusted owned-lab supervisor may import run_bootstrap(argv, profile, env).
argv is its already authorized exact PHP/podman command; this module never
constructs shell code. The direct CLI executes only the adjacent PHP helper.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys

MARKER = b'\nGOVERNANCE_BOOTSTRAP_RECEIPT:'
ANSI = re.compile(rb'\x1b\[[0-?]*[ -/]*[@-~]')


def run_bootstrap(argv, profile, env=None, timeout=1800):
    if profile not in ('default', 'no-quickstart', 'push-groups-regression'):
        raise ValueError('Unsupported fixed bootstrap profile')
    if not isinstance(argv, list) or not argv or not all(isinstance(x, str) for x in argv):
        raise ValueError('Supervisor must supply exact argument vector')
    try:
        process = subprocess.run(argv, env=env, stdout=subprocess.PIPE,
                                 stderr=subprocess.STDOUT, timeout=timeout,
                                 check=False)
        raw = process.stdout
    except subprocess.TimeoutExpired as error:
        raw = error.stdout or b''
        return {'batch': 'actual-owned-bootstrap-console-proof', 'profile': profile,
                'passed': False, 'failure': 'owned_child_timeout_cleanup_unverified',
                'console_bytes': len(raw), 'console_sha256': hashlib.sha256(raw).hexdigest()}
    receipt = None
    failure = None
    console = raw
    if raw.count(MARKER) != 1:
        failure = 'missing_or_duplicate_child_receipt'
    else:
        console, encoded = raw.split(MARKER, 1)
        try:
            receipt = json.loads(encoded.decode('utf-8'))
            if (not isinstance(receipt, dict) or
                    receipt.get('batch') != 'actual-owned-native-fresh-storage-bootstrap' or
                    receipt.get('profile') != profile or
                    type(receipt.get('passed')) is not bool):
                raise ValueError('Unexpected receipt')
        except (ValueError, UnicodeError):
            receipt = None
            failure = 'invalid_child_receipt'
    clean = ANSI.sub(b'', console)
    epoch = clean.find(b'Applying patch "phabricator:20261103.project.configepoch.sql"')
    historical = clean.find(b'Applying patch "phabricator:20140722.appname.php"')
    applied_markers = re.findall(rb'Applying patch \"(phabricator:[a-zA-Z0-9_.-]+)\"', clean)
    template = b'Loading quickstart template' in clean
    order = epoch >= 0 and historical >= 0 and epoch < historical
    if profile == 'push-groups-regression':
        profile_proved = bool(receipt and receipt.get('native', {}).get('actual_nonempty_group_regression_passed') is True) and not template
    else:
        profile_proved = template if profile == 'default' else (order and not template)
    passed = (process.returncode == 0 and receipt is not None and
              receipt['passed'] is True and profile_proved)
    if not passed and failure is None:
        failure = ('native_child_failed' if process.returncode != 0 or
                   not receipt or receipt['passed'] is not True else
                   'actual_native_console_profile_not_proved')
    return {'batch': 'actual-owned-bootstrap-console-proof', 'profile': profile,
            'passed': passed, 'failure': failure, 'child_exit_code': process.returncode,
            'console_bytes': len(console), 'console_sha256': hashlib.sha256(console).hexdigest(),
            'last_actual_applying_patch': applied_markers[-1].decode('ascii') if applied_markers else None,
            'actual_quickstart_template_loaded': template,
            'actual_epoch_applied_before_historical_config_producer': order,
            'child': receipt, 'production_deployment': False,
            'optional_features_enabled': False}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--profile', choices=('default', 'no-quickstart', 'push-groups-regression'), required=True)
    arguments = parser.parse_args()
    php = shutil.which('php')
    if php is None:
        raise SystemExit('PHP executable required')
    helper = Path(__file__).resolve().with_name('bootstrap-cases.php')
    result = run_bootstrap([php, str(helper), '--' + arguments.profile],
                           arguments.profile, env=os.environ.copy())
    print(json.dumps(result, indent=2))
    return 0 if result['passed'] else 1


if __name__ == '__main__':
    sys.exit(main())

#!/usr/bin/env python3
"""Hosted orchestration; artifacts contain final sanitized receipts only."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys


def validate_matrix_counts(receipt):
    for key, expected in (('security_cases_passed', 16), ('large_security_cases_passed', 4),
                          ('invite_capability_cases_passed', 2), ('check_count', 180)):
        if type(receipt.get(key)) is not int or receipt[key] != expected:
            raise RuntimeError('hosted_wire_case_counts_refused')
    checks = receipt.get('checks')
    if type(checks) is not list or len(checks) != 180 or any(type(x) is not str for x in checks) or len(set(checks)) != 180:
        raise RuntimeError('hosted_wire_case_counts_refused')


def run(source, binaries, output, gorge_revision, owlmail_revision):
    source = Path(source).resolve(strict=True)
    binaries = Path(binaries).resolve(strict=True)
    output = Path(output)
    if not output.is_absolute() or output.exists() or output.is_symlink():
        raise ValueError('new_receipt_directory_required')
    for revision in (gorge_revision, owlmail_revision):
        if not re.fullmatch('[a-f0-9]{40}', revision):
            raise ValueError('published_immutable_dependency_revision_required')
    output.mkdir(mode=0o700)
    private = output.parent / (output.name + '-private')
    private.mkdir(mode=0o700)
    revision = subprocess.check_output(['git', '-C', str(source), 'rev-parse', 'HEAD'], text=True).strip()
    def digest(path):
        return hashlib.sha256(path.read_bytes()).hexdigest()
    manifest = {}
    for role in ('file', 'taskqueue', 'mailer', 'observer', 'owlmail'):
        path = binaries / role
        manifest[role] = {'path': str(path), 'sha256': digest(path),
            'source_revision': revision if role == 'observer' else owlmail_revision if role == 'owlmail' else gorge_revision}
    def save(path, value):
        fd = os.open(path, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
        with os.fdopen(fd, 'w') as stream:
            json.dump(value, stream)
    save(private / 'binaries.json', manifest)
    save(private / 'fault.json', {'path': str(binaries / 'fault'), 'sha256': digest(binaries / 'fault'),
        'source_revision': revision, 'source_sha256': digest(source / 'support/governance/notification/smtp-fault.go')})
    harness = source / 'tests/governance/private_mail_ci'
    def invoke(arguments):
        # Raw subprocess streams can contain private fixture details. Keep them
        # off Actions stdout and artifact paths, including on failure.
        result = subprocess.run(arguments, capture_output=True)
        if result.returncode:
            raise RuntimeError('hosted_wire_command_failed')
    invoke([sys.executable, str(harness / 'make_config.py'), '--source-root', str(source),
        '--source-revision', revision, '--binary-manifest', str(private / 'binaries.json'),
        '--gorge-revision', gorge_revision, '--php', subprocess.check_output(['which', 'php'], text=True).strip(),
        '--expected-pure-methods', '226', '--output', str(private / 'config.json')])
    for case in ('commit', 'rollback', 'recipient-revoked', 'admin-revoked'):
        invoke([sys.executable, str(harness / 'run_pending.py'), '--config', str(private / 'config.json'),
            '--case', case, '--output', str(output / (case + '.json'))])
    invoke([sys.executable, str(harness / 'run_matrix.py'), '--config', str(private / 'config.json'),
        '--fault-manifest', str(private / 'fault.json'), '--output', str(output / 'matrix.json')])
    # Drivers fail closed, and this independent envelope rejects absent cases.
    for name in ('commit', 'rollback', 'recipient-revoked', 'admin-revoked', 'matrix'):
        receipt = json.loads((output / (name + '.json')).read_text())
        if receipt.get('passed') is not True or receipt.get('source_revision') != revision:
            raise RuntimeError('hosted_wire_receipt_refused')
        if name == 'matrix':
            validate_matrix_counts(receipt)
    print('Five current-source private-mail wire receipts passed; no browser or deployment proof.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    for key in ('source', 'binaries', 'output', 'gorge-revision', 'owlmail-revision'):
        parser.add_argument('--' + key, required=True)
    args = parser.parse_args()
    run(args.source, args.binaries, args.output, args.gorge_revision, args.owlmail_revision)


if __name__ == '__main__':
    main()

#!/usr/bin/env python3
"""Prepare a private standalone committed clone; no runtime/config copying.

This is host-only preparation. It starts no container, process service or DB.
"""
import argparse
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile


def git(arguments, **kwargs):
    env = {'PATH': os.environ.get('PATH', '/usr/bin:/bin'), 'LC_ALL': 'C',
           'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null', 'GIT_OPTIONAL_LOCKS': '0'}
    return subprocess.run(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'credential.helper=', *arguments],
                          env=env, check=True, capture_output=True, **kwargs)


def prepare(repository, revision, output):
    if not re.fullmatch('[a-f0-9]{40}', revision):
        raise ValueError('committed_revision_required')
    repository = Path(repository).resolve(strict=True)
    output = Path(output)
    if output.is_symlink() or output.exists() or not output.is_absolute() or output.parent.resolve() != output.parent:
        raise ValueError('new_canonical_clone_path_required')
    # A parent registry can own/remove the resulting clone. This routine only
    # removes its own successful mkdir inode on preparation failure.
    output.mkdir(mode=0o700)
    identity = output.stat()
    template = None
    try:
        output.chmod(0o700)
        template = Path(tempfile.mkdtemp(prefix='mail-ci-git-template-'))
        template.chmod(0o700)
        env = {'PATH': os.environ.get('PATH', '/usr/bin:/bin'), 'LC_ALL': 'C',
               'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null',
               'GIT_OPTIONAL_LOCKS': '0', 'GIT_TEMPLATE_DIR': str(template)}
        subprocess.run(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'credential.helper=',
                        '-c', 'pack.threads=1', '-c', 'pack.windowMemory=16m',
                        'clone', '--no-local', '--no-hardlinks', '--no-checkout', '--no-tags',
                        str(repository), str(output)], env=env, check=True, capture_output=True)
        git(['-C', str(output), 'checkout', '--detach', revision])
        git(['-C', str(output), 'remote', 'remove', 'origin'])
        actual = git(['-C', str(output), 'rev-parse', 'HEAD'], text=True).stdout.strip()
        common = Path(git(['-C', str(output), 'rev-parse', '--path-format=absolute', '--git-common-dir'], text=True).stdout.strip())
        if actual != revision or not (output / '.git').is_dir() or (output / '.git').is_symlink() or common != output / '.git' or (output / '.git/objects/info/alternates').exists():
            raise ValueError('independent_git_metadata_required')
        for name in ('local.json', 'deployment.json', 'ENVIRONMENT'):
            if (output / 'conf/local' / name).exists():
                raise ValueError('committed_deployment_config_refused')
        if git(['-C', str(output), 'status', '--porcelain', '--untracked-files=no']).stdout:
            raise ValueError('committed_clean_source_required')
        output.chmod(0o700)
        return {'schema': 1, 'revision': actual, 'standalone_git': True, 'alternates': False,
                'origin_removed': True, 'private_mode': '0700', 'runtime_config_copied': False,
                'readonly_mount_required': True, 'image_built': False, 'wire_executed': False}
    except BaseException:
        current = output.lstat()
        if current.st_dev == identity.st_dev and current.st_ino == identity.st_ino and not output.is_symlink():
            shutil.rmtree(output)
        raise
    finally:
        if template is not None:
            shutil.rmtree(template)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--repository', required=True)
    parser.add_argument('--revision', required=True)
    parser.add_argument('--output', required=True)
    args = parser.parse_args()
    print(json.dumps(prepare(args.repository, args.revision, args.output)))


if __name__ == '__main__':
    main()

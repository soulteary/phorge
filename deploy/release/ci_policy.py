#!/usr/bin/env python3
"""Fail-closed source identity for the publication/full-CI boundary."""
import argparse
import json
import subprocess
from pathlib import Path

from image_metadata import identity


def require_release_identity(repository, tag, sha, ref, workflow_ref, workflow_sha,
                             checkout, tag_commit):
    identity(repository, tag, sha, ref)
    expected_workflow = repository + '/.github/workflows/container-image.yml@' + ref
    if workflow_ref != expected_workflow or workflow_sha != sha:
        raise ValueError('Publication workflow must come from the exact release tag commit.')
    if checkout != sha or tag_commit != sha:
        raise ValueError('Release tag, event and checked source must be the same commit.')
    return {'sourceRevision': sha, 'tag': tag, 'ref': ref,
            'workflowRef': workflow_ref, 'workflowRevision': workflow_sha}


def require_full_result(source, qualified, result):
    if result != 'success' or qualified != source:
        raise ValueError('Successful full release CI for this exact source is required.')


def main():
    parser = argparse.ArgumentParser()
    for name in ('repository', 'tag', 'sha', 'ref', 'workflow-ref', 'workflow-sha'):
        parser.add_argument('--' + name, required=True)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--full-head')
    parser.add_argument('--full-result')
    args = parser.parse_args()
    git = lambda *parts: subprocess.check_output(['git', *parts], text=True).strip()
    value = require_release_identity(
        args.repository, args.tag, args.sha, args.ref, args.workflow_ref,
        args.workflow_sha, git('rev-parse', 'HEAD'),
        git('rev-parse', 'refs/tags/' + args.tag + '^{commit}'))
    if args.full_head is not None or args.full_result is not None:
        require_full_result(args.sha, args.full_head, args.full_result)
        value['fullReleaseCI'] = 'success'
    args.output.write_text(json.dumps(value, indent=2) + '\n')


if __name__ == '__main__':
    main()

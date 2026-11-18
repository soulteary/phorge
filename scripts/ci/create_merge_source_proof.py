#!/usr/bin/env python3
"""Create, but never publish, an exact-merge proof with the existing signer.

Run only after the supplied PR head's checks and final review have passed.
The proof qualifies source identity only; CI still executes every required check.
"""
import argparse
import json
import os
import re
import subprocess
import tempfile

SIGNER = 'CD85ED7465AFF2FFA2FFB686DBCDD8CBF440F8DE'


def create(root, commit, pr_head, ci_head, pr_number):
    if not all(re.fullmatch('[0-9a-f]{40}', x) for x in (commit, pr_head, ci_head)):
        raise ValueError('explicit full commit identifiers required')
    if ci_head != pr_head or pr_number < 1:
        raise ValueError('reviewed PR and CI head must match')

    env = {k: v for k, v in os.environ.items() if not k.startswith("GIT_")}
    env["GIT_NO_REPLACE_OBJECTS"] = "1"

    def git(*args):
        return subprocess.check_output(
            ['git', '--no-replace-objects', '-C', root, *args],
            text=True, timeout=30, env=env).strip()

    tree, *parents = git('show', '--no-show-signature', '-s', '--format=%T %P', commit).split()
    if len(parents) != 2 or parents[1] != pr_head:
        raise ValueError('expected an exact two-parent PR merge')
    if git('rev-parse', pr_head + '^{tree}') != tree:
        raise ValueError('merge tree differs from the reviewed PR tree')
    git('merge-base', '--is-ancestor', parents[0], pr_head)
    verified = subprocess.run(
        ['git', '--no-replace-objects', '-C', root, 'verify-commit', '--raw', pr_head],
        check=True, capture_output=True, text=True, timeout=30, env=env)
    if re.search(r"^\[GNUPG:\] (?:BADSIG|ERRSIG|EXPSIG|EXPKEYSIG|REVKEYSIG|KEYREVOKED|SIGEXPIRED|KEYEXPIRED)(?: |$)", verified.stderr, re.M):
        raise ValueError("invalid or expired PR signature")
    if re.findall(r'^\[GNUPG:\] VALIDSIG ([A-F0-9]{40}) ', verified.stderr, re.M) != [SIGNER]:
        raise ValueError('PR head is not signed by the existing project signer')
    tag = 'source-proof/' + commit
    if git('tag', '--list', tag):
        raise ValueError('proof tag already exists; never overwrite it')
    payload = dict(schema='phorge-merge-source-proof-v1', commit=commit, tree=tree,
                   parents=parents, pr_head=pr_head, ci_head=ci_head, pr_number=pr_number)
    with tempfile.NamedTemporaryFile(mode='w', encoding='utf-8') as f:
        json.dump(payload, f, sort_keys=True, separators=(',', ':'))
        f.write('\n')
        f.flush()
        git('tag', '-s', '-u', SIGNER, '-F', f.name, tag, commit)
    return dict(tag=tag, published=False, private_key_exported=False)


if __name__ == '__main__':
    p = argparse.ArgumentParser()
    p.add_argument('--root', required=True)
    p.add_argument('--commit', required=True)
    p.add_argument('--pr-head', required=True)
    p.add_argument('--ci-head', required=True)
    p.add_argument('--pr-number', required=True, type=int)
    a = p.parse_args()
    print(json.dumps(create(a.root, a.commit, a.pr_head, a.ci_head, a.pr_number)))

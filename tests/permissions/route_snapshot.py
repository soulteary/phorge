"""Validate and remap database-selection statements of a private approved dump."""
import hashlib
import re


def remap_snapshot(raw, manifest, namespace):
    source = manifest['namespace']
    if not re.fullmatch(r'[a-zA-Z0-9_]+', source):
        raise ValueError('Invalid private source namespace.')
    if not re.fullmatch(r'atomic_routes_[a-f0-9]{16}', namespace):
        raise ValueError('Invalid disposable target namespace.')
    if hashlib.sha256(raw).hexdigest() != manifest['snapshotSHA256']:
        raise ValueError('Base snapshot hash mismatch.')
    sql = raw.decode()
    selection = r'^(?:CREATE DATABASE[^`]*|USE\s+)`([^`]+)`'
    names = re.findall(selection, sql, re.M)
    permitted = {source+'_'+app for app in ('project','user','spaces','policy','config')}
    if set(names) != permitted:
        raise ValueError('Base snapshot schema inventory mismatch.')
    for old in permitted:
        target = namespace+old[len(source):]
        sql = re.sub(r'(?m)^((?:CREATE DATABASE[^`]*|USE\s+)`)'+re.escape(old)+r'(`)',
                     lambda m: m[1]+target+m[2], sql)
    # mysqldump comments may retain source names. These comments and stored
    # string values must not be rewritten. Executable qualified references to
    # a source schema are refused instead of globally replacing identifiers.
    executable = '\n'.join(line for line in sql.splitlines() if not line.lstrip().startswith('--'))
    if any(re.search(r'`'+re.escape(old)+r'`\s*\.', executable) for old in permitted):
        raise ValueError('Qualified source schema refused.')
    return sql

"""Identify the exact accepted worktrees, including uncommitted source files."""
import hashlib
import subprocess


def source_identity(root):
    marker = root / '.source-revision'
    if marker.is_file() and not (root / '.git').exists():
        revision = marker.read_text().strip()
        import re
        if not re.fullmatch(r'[0-9a-f]{40}', revision):
            raise ValueError('Invalid source revision marker')
        names = [str(p.relative_to(root)).encode() for p in root.rglob('*') if p.is_file()]
        dirty = None  # Archive contents are hashed; Git cleanliness is unknown.
    else:
        revision=subprocess.check_output(['git','-C',str(root),'rev-parse','HEAD'],text=True).strip()
        names=subprocess.check_output(['git','-C',str(root),'ls-files','-z','--cached','--others','--exclude-standard']).split(b'\0')
        dirty=bool(subprocess.check_output(['git','-C',str(root),'status','--porcelain']))
    digest=hashlib.sha256()
    for name in sorted(set(names)):
        if not name:continue
        path=root/name.decode()
        if '__pycache__' in path.parts or path.suffix=='.pyc':continue
        if not path.is_file():continue
        digest.update(len(name).to_bytes(8,'big'));digest.update(name)
        raw=path.read_bytes();digest.update(len(raw).to_bytes(8,'big'));digest.update(raw)
    return {'commit':revision,'dirty':dirty,'sourceSHA256':digest.hexdigest()}


def runtime_identity(manifest_path):
    """Verify and identify our package, separately from its upstream snapshot."""
    import fnmatch
    import json
    from pathlib import Path
    import re

    manifest_path = manifest_path.resolve()
    root = manifest_path.parent
    raw = manifest_path.read_bytes()
    manifest = json.loads(raw)
    if not isinstance(manifest, dict):
        raise ValueError('Invalid runtime manifest')
    if manifest.get('schemaVersion') != 1 or not isinstance(manifest.get('runtimeVersion'), str):
        raise ValueError('Invalid runtime manifest version')
    upstream_revision = manifest.get('upstreamRevision')
    if upstream_revision is not None and (not isinstance(upstream_revision, str) or
                                         not re.fullmatch(r'[0-9a-f]{40}', upstream_revision)):
        raise ValueError('Invalid upstream runtime revision')
    snapshot_digest = manifest.get('upstreamSnapshotSHA256')
    if not isinstance(snapshot_digest, str) or not re.fullmatch(r'[0-9a-f]{64}', snapshot_digest):
        raise ValueError('Invalid upstream snapshot digest')
    files = manifest.get('files')
    if not isinstance(files, list) or not files:
        raise ValueError('Runtime manifest must list managed files')
    if any(not isinstance(record, dict) or not isinstance(record.get('path'), str) for record in files):
        raise ValueError('Invalid runtime manifest file record')
    digest = hashlib.sha256()
    seen = set()
    for record in sorted(files, key=lambda record: record['path']):
        name = record['path']
        if not isinstance(name, str):
            raise ValueError('Invalid runtime manifest path')
        relative = Path(name)
        path = root / relative
        if (not name or name in seen or relative.is_absolute() or relative.as_posix() != name or
                '..' in relative.parts or root not in path.resolve().parents or
                name == manifest_path.name or path.is_symlink()):
            raise ValueError('Invalid runtime manifest path')
        seen.add(name)
        expected = record.get('sha256')
        if not isinstance(expected, str) or not re.fullmatch(r'[0-9a-f]{64}', expected):
            raise ValueError('Invalid runtime file digest')
        actual = hashlib.sha256(path.read_bytes()).hexdigest()
        if actual != expected:
            raise ValueError('Runtime file differs from manifest: ' + name)
        digest.update(name.encode() + b'\0' + actual.encode() + b'\n')
    generated = manifest.get('generatedPaths', [])
    if not isinstance(generated, list) or any(not isinstance(pattern, str) for pattern in generated):
        raise ValueError('Invalid generated runtime paths')
    for path in root.rglob('*'):
        if path.is_symlink():
            raise ValueError('Runtime paths may not be symlinks: ' + path.relative_to(root).as_posix())
        if not path.is_file() and not path.is_symlink():
            continue
        name = path.relative_to(root).as_posix()
        if name == manifest_path.name or name in seen:
            continue
        if any(fnmatch.fnmatchcase(name, pattern) for pattern in generated):
            continue
        raise ValueError('Runtime file is not in manifest: ' + name)
    actual_digest = digest.hexdigest()
    if actual_digest != manifest.get('contentSHA256'):
        raise ValueError('Runtime content digest does not match manifest')
    return {'runtimeVersion': manifest['runtimeVersion'],
            'upstreamRevision': upstream_revision,
            'upstreamSnapshotSHA256': snapshot_digest,
            'sourceSHA256': actual_digest,
            'manifestSHA256': hashlib.sha256(raw).hexdigest(),
            'patches': manifest.get('patches', [])}

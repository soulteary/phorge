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

"""Complete synthetic source roots for exact six-file cohort guard controls."""
from pathlib import Path
import hashlib,sys
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import recovery_oauth_code_source_delta as delta
import project_content_grant_source_delta as n05_delta
import recovery_oauth_authorization_source_delta as authorization_delta
import recovery_partial_current_schema_delta as partial_delta

def complete_source_cohort(root):
    root=Path(root)
    # This helper only supplies missing real source files. It never replaces a
    # test's existing or deliberately corrupted input.
    for path,pin in {**delta.SOURCE_INPUTS,**partial_delta.SOURCE_INPUTS,**{p:authorization_delta.BASE_PINS[p]for p in authorization_delta.PRODUCTION_PATHS}}.items():
        source=(ROOT/path).read_bytes()
        if hashlib.sha256(authorization_delta.reduce_if_selected(path,source)).hexdigest()!=pin:
            raise ValueError('test source cohort changed')
        dst=root/path
        if not dst.exists():
            dst.parent.mkdir(parents=True,exist_ok=True)
            dst.write_bytes(source)

def normalized_source_tree(root):
    """Hash actual tracked src bytes after the exact six-file inverse to9bc."""
    import os,subprocess
    root=Path(root)
    if subprocess.check_output(['git','-C',str(root),'ls-files','--others','--exclude-standard','--','src']):
        raise ValueError('unexpected native source leaf')
    n05_delta.assert_production_cohort(root)
    tree={}
    paths=subprocess.check_output(['git','-C',str(root),'ls-files','-z','--','src']).split(b'\0')
    for item in paths:
        if not item:continue
        path=item.decode();target=root/path
        if target.is_symlink():raise ValueError('unexpected source alias')
        raw=n05_delta.reduce_production(path,target.read_bytes())
        if raw is None:continue
        raw=delta.reduce_if_selected(path,raw)
        oid=hashlib.sha1(b'blob '+str(len(raw)).encode()+b'\0'+raw).digest()
        cursor=tree
        parts=path.split('/')[1:]
        for part in parts[:-1]:cursor=cursor.setdefault(part,{})
        cursor[parts[-1]]=('100755' if target.stat().st_mode&0o111 else '100644',oid)
    def node(value):
        body=b''
        for name,entry in sorted(value.items(),key=lambda x:x[0]+('/' if isinstance(x[1],dict)else'')):
            mode,oid=('40000',node(entry))if isinstance(entry,dict)else entry
            body+=mode.encode()+b' '+name.encode()+b'\0'+oid
        return hashlib.sha1(b'tree '+str(len(body)).encode()+b'\0'+body).digest()
    return node(tree).hex()

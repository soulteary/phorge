"""Complete synthetic source roots for exact six-file cohort guard controls."""
from pathlib import Path
import hashlib,sys
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import recovery_oauth_code_source_delta as delta
import project_description_admission_delta as n05_delta
import recovery_oauth_authorization_source_delta as authorization_delta
import recovery_partial_current_schema_delta as partial_delta

def complete_source_cohort(root):
    root=Path(root).resolve()
    n05_delta.assert_current_recipe(ROOT)
    required=set(n05_delta.description.CURRENT_PINS)|set(n05_delta.CURRENT_METADATA)|{n05_delta.MANIFEST,n05_delta.BRIDGE,n05_delta.PREPARATION,n05_delta.description.LEGACY_REDUCER['path'],'scripts/governance/project_content_grant_ci_source_delta.py'}
    for name in required:
        dst=root/name
        if not dst.exists():
            dst.parent.mkdir(parents=True,exist_ok=True);dst.write_bytes((ROOT/name).read_bytes())
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
    n05_delta.assert_current_recipe(root)
    snapshot=n05_delta.description.current_snapshot(root)
    historical=n05_delta.description.reconstruct_historical(snapshot,root)
    # Exact committed inverse modules are batch inputs, not fake runtime readers.
    for name in ('recovery_oauth_code_source_delta.py','recovery_oauth_authorization_source_delta.py','recovery_oauth_client_lifecycle_delta.py'):
        path='scripts/governance/'+name
        if (root/path).read_bytes()!=subprocess.check_output(['git','--no-replace-objects','-C',str(root),'show','432bd0166880b4a6ab4820cfebcd4482cf89bb27:'+path]):raise ValueError('unknown batch source inverse')
    def literal_inverse(module,path,raw):
        if path not in module.SOURCE_INPUTS or hashlib.sha256(raw).hexdigest()!=module.SOURCE_INPUTS[path]:return raw
        for added,removed in module.EDITS[path]:
            if raw.count(added)!=1:raise ValueError('batch source inverse changed')
            raw=raw.replace(added,removed,1)
        if hashlib.sha256(raw).hexdigest()!=module.BASE_PINS[path]:raise ValueError('batch source predecessor changed')
        return raw
    documents={'src/infrastructure/governance/content/README.description-executor.md': 'f3bd3f62cb0d743d6b228d0b066f0eb34b7cb691601ec761ce0d581ebd0b2364', 'src/infrastructure/governance/content/README.description-main-integration.md': '0e5143a5c4354ace055e6adb17e752f19b99fbacb752525832ab918c6d40e7f2', 'src/infrastructure/governance/content/README.description-source-inverse.md': '7993e97f144b1af281f2fdee46a077aacfdc5a4f61da5a7551dd6598e2d340d6'}
    for name,pin in documents.items():
        raw=n05_delta.description.checked_bytes(root/name)
        if hashlib.sha256(raw).hexdigest()!=pin or raw!=subprocess.check_output(['git','--no-replace-objects','-C',str(root),'show','432bd0166880b4a6ab4820cfebcd4482cf89bb27:'+name]):raise ValueError('unknown new source document')
        if subprocess.run(['git','--no-replace-objects','-C',str(root),'cat-file','-e','9bc725ea3643cf24b6df0e806738b55496d8b388:'+name],capture_output=True).returncode==0:raise ValueError('unexpected old source document')
    tree={}
    paths=subprocess.check_output(['git','-C',str(root),'ls-files','-z','--','src']).split(b'\0')
    for item in paths:
        if not item:continue
        path=item.decode();target=root/path
        if target.is_symlink():raise ValueError('unexpected source alias')
        raw=target.read_bytes()
        if path in documents:
            if hashlib.sha256(raw).hexdigest()!=documents[path]or target.stat().st_mode&0o777!=0o644:raise ValueError("source document bytes or mode changed")
            continue
        if path in snapshot:
            if raw!=snapshot[path]:raise ValueError('selected source changed after batch capture')
            if path not in historical:continue
            raw=historical[path]
        raw=authorization_delta.lifecycle_delta.reduce_if_selected(path,raw)
        raw=literal_inverse(authorization_delta,path,raw)
        raw=literal_inverse(delta,path,raw)
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

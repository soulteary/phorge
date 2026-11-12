"""Complete synthetic source roots for exact six-file cohort guard controls."""
from pathlib import Path
import shutil,hashlib,sys
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
        if not dst.exists() and not dst.is_symlink():
            dst.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(ROOT/name,dst,follow_symlinks=False)
    # This helper only supplies missing real source files. It never replaces a
    # test's existing or deliberately corrupted input.
    for path,pin in {**delta.SOURCE_INPUTS,**partial_delta.SOURCE_INPUTS,**{p:authorization_delta.BASE_PINS[p]for p in authorization_delta.PRODUCTION_PATHS}}.items():
        source=(ROOT/path).read_bytes()
        if hashlib.sha256(authorization_delta.reduce_if_selected(path,source)).hexdigest()!=pin:
            raise ValueError('test source cohort changed')
        dst=root/path
        if not dst.exists() and not dst.is_symlink():
            dst.parent.mkdir(parents=True,exist_ok=True)
            shutil.copy2(ROOT/path,dst,follow_symlinks=False)


I18N_HISTORICAL_REVISION='9bc725ea3643cf24b6df0e806738b55496d8b388'
I18N_HISTORICAL_SRC_TREE='3944b8003bb59a9b67f685df67acbd2354820b1c'
I18N_HISTORICAL_PINS={'src/__tests__/PhorgeI18nTestCase.php': {'bytes': 6736, 'sha256': '06e578c69ccf9392ee9b5cb12a180f2e126ab9481354062e06b8da0ad7e7c7ad', 'mode': '100644', 'blob': '059a4d1131563cde9f283c50c49355ec5b0f240b'}, 'src/infrastructure/internationalization/management/PhabricatorInternationalizationManagementExtractWorkflow.php': {'bytes': 9437, 'sha256': '3db40d48f67ed43a9cf6b8a00becb1efae28ad156b4ab436a01b3f29af1e8c8b', 'mode': '100644', 'blob': '75168efb31bbfbcfa1b693cf181bb398155a8890'}, 'src/infrastructure/internationalization/management/PhorgeInternationalizationValidator.php': {'bytes': 9732, 'sha256': 'd3a54aca642ddfa36570f64e687cb2bbf3423a10dd7490766f3dc12b9bc518a4', 'mode': '100644', 'blob': 'c2bfe8a81a1928b67d01937760ead89a21c0c617'}}
def reviewed_i18n_historical_inputs(root):
    # Test-only expected reference, never a runtime source-reader exception.
    import subprocess
    from test_project_description_source_inverse import reviewed_i18n_sources
    current=reviewed_i18n_sources(root)
    git=lambda *args:subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),*args])
    if git('rev-parse',I18N_HISTORICAL_REVISION+':src').decode().strip()!=I18N_HISTORICAL_SRC_TREE:raise ValueError('unknown historical i18n tree')
    if set(current)!=set(I18N_HISTORICAL_PINS):raise ValueError('unknown historical i18n set')
    historical={}
    for name,pin in I18N_HISTORICAL_PINS.items():
        entry=git('ls-tree','-z',I18N_HISTORICAL_REVISION,'--',name)
        if entry!=('100644 blob '+pin['blob']+'\t'+name+'\0').encode():raise ValueError('unknown historical i18n mode/blob')
        raw=git('show',I18N_HISTORICAL_REVISION+':'+name)
        if len(raw)!=pin['bytes']or hashlib.sha256(raw).hexdigest()!=pin['sha256']:raise ValueError('unknown historical i18n bytes')
        historical[name]=raw
    return current,historical

def normalized_source_tree(root):
    """Hash actual tracked src bytes after the exact six-file inverse to9bc."""
    import os,subprocess
    root=Path(root)
    if subprocess.check_output(['git','-C',str(root),'ls-files','--others','--exclude-standard','--','src']):
        raise ValueError('unexpected native source leaf')
    n05_delta.assert_current_recipe(root)
    snapshot=n05_delta.description.current_snapshot(root)
    historical=n05_delta.description.reconstruct_historical(snapshot,root)
    i18n_current,i18n_historical=reviewed_i18n_historical_inputs(root)
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
            if path==n05_delta.description.NATIVE_EDITOR_BASELINE['path']:
                raw=n05_delta.description.reconstruct_native_editor(snapshot)
            elif path==n05_delta.description.NATIVE_STATE_BASELINE['path']:
                raw=n05_delta.description.reconstruct_native_state(snapshot)
            else:
                if path not in historical:continue
                raw=historical[path]
        if path in i18n_current:
            import stat
            physical=target.lstat()
            if not stat.S_ISREG(physical.st_mode)or stat.S_IMODE(physical.st_mode)!=0o644:raise ValueError('current i18n physical mode changed after capture')
            mode='100644'
            blob=hashlib.sha1(b'blob '+str(len(raw)).encode()+b'\0'+raw).hexdigest()
            if (mode,blob)!=i18n_current[path]:raise ValueError('current i18n changed after capture')
            raw=i18n_historical[path]
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

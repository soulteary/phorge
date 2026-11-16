"""Explicit Task-source test cohort. No default legacy admission is changed."""
from pathlib import Path
import hashlib,sys,os,re,stat,ast,subprocess
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import source_operation_snapshot as source

def _checkpoint(value):
 if type(value)is not str or re.fullmatch('[a-f0-9]{40}',value)is None or value=='0'*40:
  raise ValueError('explicit_Task_checkpoint_required')
 return value

def _git(root,*args,check=True):
 env={'PATH':'/usr/bin:/bin','LANG':'C','LC_ALL':'C','GIT_CONFIG_NOSYSTEM':'1','GIT_CONFIG_GLOBAL':'/dev/null','GIT_NO_REPLACE_OBJECTS':'1','GIT_NO_LAZY_FETCH':'1','GIT_TERMINAL_PROMPT':'0'}
 return subprocess.run(['git','--no-replace-objects','-C',str(root),*args],env=env,stdin=subprocess.DEVNULL,capture_output=True,timeout=10,check=check)

def _literal_constants(operation,root,relative,names):
 raw=operation._task_contexts[root].parent(relative)
 if type(raw)is not bytes:raise ValueError('captured_test_reference_missing')
 result={}
 for node in ast.parse(raw).body:
  if isinstance(node,ast.Assign)and len(node.targets)==1 and isinstance(node.targets[0],ast.Name)and node.targets[0].id in names:
   name=node.targets[0].id
   if name in result:raise ValueError('duplicate_test_reference')
   result[name]=ast.literal_eval(node.value)
 if set(result)!=set(names):raise ValueError('missing_test_reference')
 return result

def _reviewed_i18n_historical_inputs(operation,root):
 context=operation._task_contexts[root]
 now=_literal_constants(operation,root,'tests/governance/test_project_description_source_inverse.py',{'REVIEWED_I18N_NATIVE_REVISION','REVIEWED_I18N_NATIVE_TREE','REVIEWED_I18N_NATIVE_PINS'})
 old=_literal_constants(operation,root,'tests/governance/recovery_oauth_code_test_cohort.py',{'I18N_HISTORICAL_REVISION','I18N_HISTORICAL_SRC_TREE','I18N_HISTORICAL_PINS'})
 if _git(root,'rev-parse',now['REVIEWED_I18N_NATIVE_REVISION']+'^{tree}').stdout.decode().strip()!=now['REVIEWED_I18N_NATIVE_TREE']or _git(root,'rev-parse',old['I18N_HISTORICAL_REVISION']+':src').stdout.decode().strip()!=old['I18N_HISTORICAL_SRC_TREE']:
  raise ValueError('unknown_i18n_reference_tree')
 if set(now['REVIEWED_I18N_NATIVE_PINS'])!=set(old['I18N_HISTORICAL_PINS']):raise ValueError('unknown_i18n_reference_set')
 result={};historical={}
 for current,revision,pins in ((True,now['REVIEWED_I18N_NATIVE_REVISION'],now['REVIEWED_I18N_NATIVE_PINS']),(False,old['I18N_HISTORICAL_REVISION'],old['I18N_HISTORICAL_PINS'])):
  for name,pin in pins.items():
   entry=_git(root,'ls-tree','-z',revision,'--',name).stdout
   if entry!=('100644 blob '+pin['blob']+'\t'+name+'\0').encode():raise ValueError('unknown_i18n_reference_blob')
   raw=_git(root,'show',revision+':'+name).stdout
   if len(raw)!=pin['bytes']or hashlib.sha256(raw).hexdigest()!=pin['sha256']:raise ValueError('unknown_i18n_reference_bytes')
   if current:
    row=context.parent_pins[name]
    if row['kind']!='file'or row['mode']!=0o644 or context.parent(name)!=raw:raise ValueError('captured_i18n_reference_changed')
    result[name]=(pin['mode'],pin['blob'])
   else:historical[name]=raw
 return result,historical

def _supply_missing(root,name,raw,mode):
 """Only supply absent files; preserve deliberate bad inputs and reject parent aliases."""
 root=Path(root)
 if root.resolve()!=root or not root.is_dir()or type(name)is not str or name.startswith('/')or '..'in name.split('/')or type(raw)is not bytes or mode not in(0o644,0o755):
  raise ValueError('Task_test_destination_refused')
 dst=root/name
 if dst.exists()or dst.is_symlink():return
 current=root
 for part in Path(name).parts[:-1]:
  current=current/part
  if current.is_symlink():raise ValueError('Task_test_parent_alias_refused')
  if not current.exists():current.mkdir()
  if not current.is_dir()or current.resolve()!=current:raise ValueError('Task_test_parent_refused')
 fd=os.open(dst,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,mode)
 with os.fdopen(fd,'wb')as stream:stream.write(raw)
 dst.chmod(mode)

def normalized_source_tree(root,expected_checkpoint):
 expected_checkpoint=_checkpoint(expected_checkpoint)
 root=Path(root)
 with source.open_task_source_operation(root,expected_checkpoint=expected_checkpoint)as operation:
  source.require_task_operation(operation,root)
  result=_normalized_source_tree(operation,root)
 return result

def complete_source_cohort(root,expected_checkpoint):
 expected_checkpoint=_checkpoint(expected_checkpoint)
 root=Path(root)
 if not root.is_absolute()or root.resolve()!=root:raise ValueError('Task_test_destination_alias_refused')
 with source.open_task_source_operation(ROOT,expected_checkpoint=expected_checkpoint)as operation:
  source.require_task_operation(operation,ROOT);context=operation._task_contexts[ROOT]
  n05=operation._modules['project_description_admission_delta'];operation._assert_current_recipe(ROOT)
  delta=operation._modules['recovery_oauth_code_source_delta'];partial=operation._modules['recovery_partial_current_schema_delta'];authorization=operation._modules['recovery_oauth_authorization_source_delta']
  required=set(n05.description.CURRENT_PINS)|set(n05.CURRENT_METADATA)|{n05.MANIFEST,n05.BRIDGE,n05.PREPARATION,n05.description.LEGACY_REDUCER['path'],'scripts/governance/project_content_grant_ci_source_delta.py'}
  extras={**delta.SOURCE_INPUTS,**partial.SOURCE_INPUTS,**{p:authorization.BASE_PINS[p]for p in authorization.PRODUCTION_PATHS}}
  required|=set(extras)
  root.mkdir(parents=True,exist_ok=True)
  for name in sorted(required):
   raw=context.parent(name)
   if type(raw)is not bytes:raise ValueError('Task_test_parent_missing')
   if name in extras and hashlib.sha256(authorization.reduce_if_selected(name,raw)).hexdigest()!=extras[name]:raise ValueError('Task_test_source_cohort_changed')
   _supply_missing(root,name,raw,context.parent_pins[name]['mode'])

def _normalized_source_tree(operation,root):
    """Hash actual tracked src bytes after the exact six-file inverse to9bc."""
    root=Path(root)
    if _git(root,'ls-files','--others','--exclude-standard','--','src').stdout:
        raise ValueError('unexpected native source leaf')
    n05_delta=operation._modules["project_description_admission_delta"]
    delta=operation._modules["recovery_oauth_code_source_delta"]
    authorization_delta=operation._modules["recovery_oauth_authorization_source_delta"]
    context=operation._task_contexts[root]
    operation._assert_current_recipe(root)
    snapshot=n05_delta.description.current_snapshot(root)
    historical=n05_delta.description.reconstruct_historical(snapshot,root)
    i18n_current,i18n_historical=_reviewed_i18n_historical_inputs(operation,root)
    for path,(mode,blob)in i18n_current.items():
        raw=context.parent(path)
        pin=context.parent_pins[path]
        if type(raw)is not bytes or mode!='100644'or pin['kind']!='file'or pin['mode']!=0o644 or hashlib.sha1(b'blob '+str(len(raw)).encode()+b'\0'+raw).hexdigest()!=blob:
            raise ValueError('captured current i18n mismatch')
    # Exact committed inverse modules are batch inputs, not fake runtime readers.
    for name in ('recovery_oauth_code_source_delta.py','recovery_oauth_authorization_source_delta.py','recovery_oauth_client_lifecycle_delta.py'):
        path='scripts/governance/'+name
        if context.parent(path)!=_git(root,'show','432bd0166880b4a6ab4820cfebcd4482cf89bb27:'+path).stdout:raise ValueError('unknown batch source inverse')
    def literal_inverse(module,path,raw):
        if path not in module.SOURCE_INPUTS or hashlib.sha256(raw).hexdigest()!=module.SOURCE_INPUTS[path]:return raw
        for added,removed in module.EDITS[path]:
            if raw.count(added)!=1:raise ValueError('batch source inverse changed')
            raw=raw.replace(added,removed,1)
        if hashlib.sha256(raw).hexdigest()!=module.BASE_PINS[path]:raise ValueError('batch source predecessor changed')
        return raw
    documents={'src/infrastructure/governance/content/README.description-executor.md': 'f3bd3f62cb0d743d6b228d0b066f0eb34b7cb691601ec761ce0d581ebd0b2364', 'src/infrastructure/governance/content/README.description-main-integration.md': '0e5143a5c4354ace055e6adb17e752f19b99fbacb752525832ab918c6d40e7f2', 'src/infrastructure/governance/content/README.description-source-inverse.md': '7993e97f144b1af281f2fdee46a077aacfdc5a4f61da5a7551dd6598e2d340d6'}
    for name,pin in documents.items():
        raw=context.parent(name)
        if hashlib.sha256(raw).hexdigest()!=pin or raw!=_git(root,'show','432bd0166880b4a6ab4820cfebcd4482cf89bb27:'+name).stdout:raise ValueError('unknown new source document')
        if _git(root,'cat-file','-e','9bc725ea3643cf24b6df0e806738b55496d8b388:'+name,check=False).returncode==0:raise ValueError('unexpected old source document')
    tree={}
    paths=_git(root,'ls-files','-z','--','src').stdout.split(b'\0')
    for item in paths:
        if not item:continue
        path=item.decode();target=root/path
        if target.is_symlink():raise ValueError('unexpected source alias')
        context.current(path)
        raw=context.parent(path)
        if raw is None:continue
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

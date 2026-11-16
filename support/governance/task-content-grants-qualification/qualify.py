# Root-bound, exact-source one-action runner. Imports never execute PHP or SQL.
import argparse,hashlib,json,os,re,stat,subprocess,sys,time,types
from pathlib import Path
ROOT_PROFILE_SHA256=None
ROOT_SOURCE_MANIFEST_SHA256=None
ROOT_REVIEW_SHA256=None
ROOT_SOURCE_GATE_SHA256=None
ACTIONS=('discover','before-upgrade','schema','target','submit-grant','submit-revoke','approve','deny-self','deny-replay','readback')
ACTION_SECONDS=120
MAX_RESULT=1048576

def need(v):
    if v is not True:raise ValueError('task_qualification_Root_admission_refused')
def sha(raw):return hashlib.sha256(raw).hexdigest()
def hex64(v):return type(v)is str and re.fullmatch('[a-f0-9]{64}',v)is not None
def decode(raw):
    def pairs(items):
        out={}
        for k,v in items:need(k not in out);out[k]=v
        return out
    return json.loads(raw,object_pairs_hook=pairs,parse_constant=lambda _:need(False))
def private(path):
    p=Path(path);need(p.is_absolute() and p.resolve()==p);a=p.lstat()
    need(stat.S_ISREG(a.st_mode)and a.st_uid==os.geteuid()and stat.S_IMODE(a.st_mode)==0o600 and a.st_nlink==1 and 0<a.st_size<=65536)
    fd=os.open(p,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    try:
        raw=os.read(fd,65537);key=lambda x:(x.st_dev,x.st_ino,x.st_mode,x.st_uid,x.st_gid,x.st_nlink,x.st_size,x.st_mtime_ns,x.st_ctime_ns)
        need(len(raw)==a.st_size and key(a)==key(os.fstat(fd))==key(p.lstat()));return raw
    finally:os.close(fd)
def write(path,raw):
    fd=os.open(path,os.O_CREAT|os.O_EXCL|os.O_WRONLY|os.O_NOFOLLOW,0o600)
    with os.fdopen(fd,'wb')as f:f.write(raw);f.flush();os.fsync(f.fileno())
def encoded(value):return(json.dumps(value,sort_keys=True,separators=(',',':'))+'\n').encode()
def bind(profile,review,manifest):
    need(all(hex64(v)for v in(ROOT_PROFILE_SHA256,ROOT_SOURCE_MANIFEST_SHA256,ROOT_REVIEW_SHA256,ROOT_SOURCE_GATE_SHA256)))
    raw=private(profile);rr=private(review);need(sha(raw)==ROOT_PROFILE_SHA256 and sha(rr)==ROOT_REVIEW_SHA256);p=decode(raw)
    need(decode(rr)=={'schema':'N05-Task-Root-run-admission-v1','profile_sha256':ROOT_PROFILE_SHA256,'source_manifest_sha256':ROOT_SOURCE_MANIFEST_SHA256,'source_gate_sha256':ROOT_SOURCE_GATE_SHA256,'isolated_fixture_authorized':True})
    need(p.get('runtime_authorized')is True and p.get('schema')=='N05-Task-metadata-native-qualification-v1')
    root=Path(p['source_root']);need(root.is_absolute()and root.resolve()==root and not root.is_symlink())
    gate_path=root/'support/governance/task-content-grants-qualification/source_gate.py';a=gate_path.lstat();need(stat.S_ISREG(a.st_mode)and a.st_nlink==1 and not(a.st_mode&0o022));gate_raw=gate_path.read_bytes();need(sha(gate_raw)==ROOT_SOURCE_GATE_SHA256)
    # Execute captured, separately pinned verifier bytes, never a mutable import path.
    gate=types.ModuleType('Root_reviewed_Task_source_gate');gate.__file__=str(gate_path);exec(compile(gate_raw,str(gate_path),'exec'),gate.__dict__)
    before=gate.verify(root,manifest,ROOT_SOURCE_MANIFEST_SHA256,p['configuration_sha256'])
    need(before['source_window_sha256']==p['source_window_sha256'])
    need(sys.platform=='linux'and p['php']in('/usr/bin/php','/usr/local/bin/php'))
    php=Path(p['php']);need(php.is_file()and sha(php.read_bytes())==p['php_sha256'])
    return p,root,gate,before

def capture_programs(root,gate,manifest):
    # Do not adopt bytes observed after the qualified source window. The
    # expected hashes are from the externally Root-pinned manifest, not new IO.
    fixed=gate.private_manifest(manifest,ROOT_SOURCE_MANIFEST_SHA256)['entries']
    captured={}
    for role,relative in [('template','support/governance/task-content-grants-qualification/native.php'),('helper','support/governance/project-content-grants-qualification/native.php')]:
        pin=fixed[relative];need(pin['kind']=='file');path=root/relative;a=path.lstat()
        need(stat.S_IMODE(a.st_mode)==pin['mode'])
        raw=gate.read_stable(path,gate.MAX_FILE_BYTES)
        need(gate.stamp(a)==gate.stamp(path.lstat()) and len(raw)==pin['bytes'] and sha(raw)==pin['sha256'])
        captured[role]={'raw':raw,'expected_sha256':pin['sha256']}
    return captured

def run(profile,review,manifest,action,input_path,kind,output):
    need(action in ACTIONS and kind in('user','business-team'))
    p,root,gate,before=bind(profile,review,manifest);private(input_path)
    captured=capture_programs(root,gate,manifest)
    out=Path(output);need(out.is_absolute()and out.parent.resolve()==out.parent);out.mkdir(mode=0o700)
    write(out/'intent.json',encoded({'action':action,'kind':kind,'source_before':before,'signals_retry_cleanup':False,'Task_body_execution_authorized':False}))
    template=captured['template']['raw'];helper_sha=captured['helper']['expected_sha256']
    helper=out/'captured-project-helper.php';write(helper,captured['helper']['raw'])
    bindings={'N05_TASK_PROFILE_SHA256':ROOT_PROFILE_SHA256,'N05_TASK_CONFIGURATION_SHA256':p['configuration_sha256'],'N05_TASK_SOURCE_WINDOW_SHA256':p['source_window_sha256'],'N05_TASK_HELPER_ROOT':str(root),'N05_TASK_HELPER_SHA256':helper_sha,'N05_TASK_CAPTURED_HELPER_PATH':str(helper)}
    bound=template
    for key,value in bindings.items():
        needle=('const '+key+'=null;').encode();need(bound.count(needle)==1)
        # Fixed ASCII hashes/canonical path have no PHP quote/control characters.
        need(type(value)is str and not any(c in value for c in("'",'\\','\x00','\n','\r')))
        bound=bound.replace(needle,('const '+key+"='"+value+"';").encode(),1)
    native=out/'bound-native.php';write(native,bound)
    write(out/'executed-helper-binding.json',encoded({'template_sha256':sha(template),'bound_sha256':sha(bound),'borrowed_native_helper_sha256':helper_sha,'changed_literals':list(bindings),'source_template_mutated':False}))
    argv=[p['php'],'-d','opcache.jit=disable','-d','opcache.jit_buffer_size=0','-d','opcache.enable_cli=0',str(native),str(root),str(profile),action,str(input_path),kind]
    stdout=out/'native.stdout.private';stderr=out/'native.stderr.private';started=time.monotonic();child=None
    with stdout.open('xb')as so,stderr.open('xb')as se:
        os.chmod(stdout,0o600);os.chmod(stderr,0o600)
        child=subprocess.Popen(argv,cwd=root,stdin=subprocess.DEVNULL,stdout=so,stderr=se,env={'PATH':str(Path(p['php']).parent)+':/usr/bin:/bin','LANG':'C.UTF-8','TZ':'UTC'},umask=0o077)
        write(out/'started.json',encoded({'pid':child.pid,'signals_retry_cleanup':False}))
        try:code=child.wait(timeout=ACTION_SECONDS)
        except subprocess.TimeoutExpired:
            write(out/'unknown-terminal.json',encoded({'known_terminal':False,'signals_retry_cleanup':False,'SQL_execution_extent':'unknown','retained':True}));raise
    # Do not convert an exception or a source mismatch into a successful receipt.
    write(out/'terminal.json',encoded({'known_terminal':True,'exit_code':code,'seconds':time.monotonic()-started,'signals_retry_cleanup':False}))
    after=gate.verify(root,manifest,ROOT_SOURCE_MANIFEST_SHA256,p['configuration_sha256']);need(after==before)
    need(code==0 and stdout.stat().st_size<=MAX_RESULT and stderr.stat().st_size==0)
    result=decode(stdout.read_bytes());need(type(result)is dict and result.get('execution_authorized')is False)
    receipt={'schema':'N05-Task-isolated-action-receipt-v1','action':action,'source_before':before,'source_after':after,'known_native_exit':True,'native_exit':code,'native_result_sha256':sha(stdout.read_bytes()),'metadata_action_requested':action not in('discover','before-upgrade','schema','target','readback'),'all_SQL_concurrency_or_faults_qualified':False,'Task_body_execution_proved':False,'owned_SQL_cleanup_proved_by_this_runner':False,'production_activation':False}
    write(out/'receipt.json',encoded(receipt));return receipt

if __name__=='__main__':
    parser=argparse.ArgumentParser()
    for name in('profile','review','manifest','input','output'):parser.add_argument('--'+name,required=True,type=Path)
    parser.add_argument('--action',choices=ACTIONS,required=True);parser.add_argument('--kind',choices=('user','business-team'),default='user')
    args=parser.parse_args()
    try:result=run(args.profile,args.review,args.manifest,args.action,args.input,args.kind,args.output)
    except Exception:
        print('{"passed":false,"retained":true,"signals_retry_cleanup":false,"SQL_execution_extent":"unknown"}');raise SystemExit(1)
    print(json.dumps(result,sort_keys=True))

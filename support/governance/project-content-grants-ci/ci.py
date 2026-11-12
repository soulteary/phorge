"""Reviewed-recipe CI orchestration; never substitutes native authorization."""
import argparse,dis,hashlib,json,os,re,stat,subprocess,sys,types,uuid
from pathlib import Path

BASE='4c2245300dc1d78d042b95190dd537eb6b1f9861'
BASE_TREE='69180e43fba7476257cc534c1934d544995a4443'
TARGET='70bb0c1026b237d5e8c95c327d851eae719a29ee'
TREE='a9f71d3ac0878c2b9c83c57b2358135348f16b1d'
MYSQL='mysql:8.4.13@sha256:a0225d1ea6631c8bc2385dc4c2faba17bbf1834ecf369e1659a9a382bd8d5dd7'
REVIEWED_PINS={'prerequisites': {'native.php': {'bytes': 9741, 'sha256': '3fb91c6e8f51444c90305fa3ebddb607b36f52afa55df44a6e6f07a428a8fb6e'}, 'provision.py': {'bytes': 11688, 'sha256': '103f043fa7970fa7ce9a7e4c82bd19c421433fdc02239dde103b6a6caa065cc3'}}, 'qualification': {'native.php': {'bytes': 17158, 'sha256': '68fc4a94a86140c7c0a6ef01af0d6daa3e5d5982c20c89061c8d9b64650d869d'}, 'qualify.py': {'bytes': 9451, 'sha256': 'c6e3cb75103b9f619684f3c8c6826f8d6c09a4d4fe7faf23b2a4c3686e819f4b'}}}
REVIEWED_INVENTORIES={'qualification': 'addd20d24c85ad1149591e8a29184a799f3cb45105c2562df88b645ccc05078c', 'prerequisites': 'dd641e2b38de82e6cf829ff6a71f891887ab2815b9171d1ba0e9c96d2259e896'}
sha=lambda b:hashlib.sha256(b).hexdigest()
canon=lambda d:json.dumps(d,sort_keys=True,separators=(',',':')).encode()+b'\n'

def need(v):
    if not v:raise ValueError('N05_CI_refused')

def private_new(p,b):
    fd=os.open(p,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    with os.fdopen(fd,'wb')as f:f.write(b);f.flush();os.fsync(f.fileno())

def module(name,path,pins):
    raw=path.read_bytes();need(sha(raw)==pins[path.name]['sha256'])
    m=types.ModuleType(name);m.__file__=str(path);exec(compile(raw,str(path),'exec'),m.__dict__);return m

def select_service(rows,cid):
    need(type(rows)is list and len(rows)==1)
    v=rows[0];need(v['Id']==cid and v['State']['Running']is True and v['Config']['Image']==MYSQL)
    ports=v['HostConfig']['PortBindings'];need(set(ports)=={'3306/tcp'})
    bindings=ports['3306/tcp'];need(len(bindings)==1 and bindings[0]['HostIp']=='127.0.0.1')
    published={k:v for k,v in v['NetworkSettings']['Ports'].items() if v}
    need(published=={'3306/tcp':bindings})
    port=int(bindings[0]['HostPort']);need(1<=port<=65535);return port

def runtime(v):
    need(set(v)=={'php_version','mysql_version','server_uuid','opcache_jit','opcache_jit_buffer_size','opcache_enable_cli'})
    need(v['php_version']=='8.5.12' and v['mysql_version']=='8.4.13')
    need(v['opcache_jit']=='disable' and v['opcache_jit_buffer_size']=='0' and v['opcache_enable_cli']=='0')
    need(type(v['server_uuid'])is str and re.fullmatch('[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}',v['server_uuid']))
    return v

# Includes every tracked production runtime location; support/governance contains
# the new offline recipe, so its exact helpers are bound separately below.
RUNTIME_PATHS=('src','resources','bin','scripts','externals','webroot','support/runtime','support/startup','support/phame','support/preamble.proxy-https.php','conf')
OFFLINE_METADATA=('scripts/governance/check_recovery_oauth_authorization_producer_v1.py','scripts/governance/project_content_grant_source_delta.py','scripts/governance/project_content_grant_ci_source_delta.py','scripts/governance/project_description_admission_delta.py','scripts/governance/recovery_oauth_authorization_source_delta.py','scripts/governance/check_directory_transports_v1.py')
def git(root,*args):
    return subprocess.check_output(['/usr/bin/git','--no-replace-objects','-C',str(root),*args],stderr=subprocess.DEVNULL).strip().decode()
def clean(root):
    need(root.is_absolute() and root.resolve()==root)
    need(git(root,'diff','--name-only','HEAD')=='')
    need(git(root,'ls-files','--others','--exclude-standard','--',*RUNTIME_PATHS)=='')
def source(root,commit):
    clean(root);need(git(root,'rev-parse','HEAD')==commit)
    if commit==TARGET:need(git(root,'rev-parse','HEAD^{tree}')==TREE)
    return git(root,'rev-parse','HEAD^{tree}')
def current_source(root,revision):
    clean(root)
    import sys
    folder=str(root/'scripts/governance')
    if folder not in sys.path:sys.path.insert(0,folder)
    import project_description_admission_delta as admission
    admission.assert_current_recipe(root)
    need(re.fullmatch('[a-f0-9]{40}',revision) and git(root,'rev-parse','HEAD')==revision)
    subprocess.run(['/usr/bin/git','--no-replace-objects','-C',str(root),'diff','--exit-code',TARGET,'HEAD','--',*RUNTIME_PATHS,*(':(exclude)'+p for p in OFFLINE_METADATA)],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    return revision

def bind_manifest(template,folder,kind,root):
    need(kind in REVIEWED_PINS)
    f=json.loads(template.read_bytes())
    key='native_public_source_dependencies' if kind=='prerequisites' else 'native_source_inventory'
    need(set(f)=={'schema','kind','source_sha256',key,'native_git_revision','native_git_tree'} and f['schema']=='N05-CI-reviewed-source-template-v2' and f['kind']==kind)
    revision,tree=(BASE,BASE_TREE)if kind=='prerequisites'else(TARGET,TREE)
    need(f['native_git_revision']==revision and f['native_git_tree']==tree and git(root,'rev-parse','HEAD')==revision and git(root,'rev-parse','HEAD^{tree}')==tree)
    # Independently reviewed constants anchor the template before any module exec.
    need(f['source_sha256']==REVIEWED_PINS[kind] and sha(canon(f[key]))==REVIEWED_INVENTORIES[kind])
    for n,p in REVIEWED_PINS[kind].items():
        raw=(folder/n).read_bytes();need(len(raw)==p['bytes'] and sha(raw)==p['sha256'])
    for n,p in f[key].items():
        path=root/n
        links={'bin/storage':'../scripts/sql/manage_storage.php','bin/user':'../scripts/setup/manage_user.php'}
        if n in links:
            need(kind=='prerequisites' or n=='bin/storage')
            need(path.is_symlink()and os.readlink(path)==links[n])
            target=root/('scripts/sql/manage_storage.php' if n=='bin/storage' else 'scripts/setup/manage_user.php')
            need(target.resolve()==target and path.resolve()==target and target.is_file())
            raw=os.readlink(path).encode()if kind=='prerequisites'else target.read_bytes()
        else:
            need(path.resolve()==path and path.is_file());raw=path.read_bytes()
        need(len(raw)==p['bytes']and sha(raw)==p['sha256'])
    return {**f,'schema':'N05-CI-bound-reviewed-source-v2','source_directory':str(folder)}

# Password is read from the private owned file, never a command-line argument.
PROBE=r'''$d=json_decode(file_get_contents($argv[1]),true,8,JSON_THROW_ON_ERROR);mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$c=new mysqli('127.0.0.1',$d['user'],$d['password'],'',(int)$argv[2]);$r=$c->query('SELECT VERSION() version,@@server_uuid uuid')->fetch_assoc();echo json_encode(['php_version'=>PHP_VERSION,'mysql_version'=>$r['version'],'server_uuid'=>$r['uuid'],'opcache_jit'=>ini_get('opcache.jit'),'opcache_jit_buffer_size'=>ini_get('opcache.jit_buffer_size'),'opcache_enable_cli'=>ini_get('opcache.enable_cli')]);$c->close();'''

# Public diagnostics contain only fixed source labels and bounded integers.
# No journal files, argv, exception text, native output or private identities enter it.
DIAGNOSTIC={'stage':'entry','child_ordinal':None,'child_outcome':'not-observed','child_exit_code':None,'native_diagnostic':None}
def stage(name):
    DIAGNOSTIC.update(stage=name,child_ordinal=None,child_outcome='not-observed',child_exit_code=None,native_diagnostic=None)
def diagnostic_json(raw):
    def unique(pairs):
        value={}
        for k,v in pairs:need(k not in value);value[k]=v
        return value
    return json.loads(raw,object_pairs_hook=unique)

# Fixed original-Journal records only; no argv, intent or raw diagnostics export.
def prepare_child_observation(journal,phase,ordinal):
    fd=None
    try:
        need(phase in ('qualification','prerequisites')and type(ordinal)is int and 1<=ordinal<=256)
        counter='i' if phase=='qualification'else'n';need(type(getattr(journal,counter,None))is int and getattr(journal,counter)==ordinal-1)
        path=journal.path;need(isinstance(path,Path)and path.is_absolute()and path.resolve()==path)
        d=path.lstat();need(stat.S_ISDIR(d.st_mode)and stat.S_IMODE(d.st_mode)==0o700 and d.st_uid==os.geteuid())
        identity=lambda x:(x.st_dev,x.st_ino,x.st_mode,x.st_uid,x.st_gid)
        fd=os.open(path,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW|os.O_CLOEXEC);need(identity(os.fstat(fd))==identity(d))
        for suffix in ('-intent.json','-started.json','-terminal.json','-unknown.json','.stdout.private','.stderr.private'):
            try:os.stat(f'{ordinal:02d}'+suffix,dir_fd=fd,follow_symlinks=False)
            except FileNotFoundError:continue
            raise ValueError('preexisting child record')
        return {'path':path,'fd':fd,'identity':identity(d),'phase':phase,'ordinal':ordinal}
    except Exception:
        if fd is not None:
            try:os.close(fd)
            except OSError:pass
        return None

def original_result_refusal(error,original,phase):
    # Only the actual final need() call, not an arbitrary failure before launch.
    expected='N05_qualification_refused_retain_no_retry' if phase=='qualification'else'N05_prerequisites_refused_retain_no_retry'
    if type(error)is not ValueError or str(error)!=expected:return None
    try:
        frames=[];tb=error.__traceback__
        while tb is not None:frames.append(tb);tb=tb.tb_next
        need(len(frames)>=2)
        caller,guard=frames[-2:];guard_function=original.__globals__['need']
        last_line=max(line for _,line in dis.findlinestarts(original.__code__) if line is not None)
        need(caller.tb_frame.f_code is original.__code__ and caller.tb_lineno==last_line and guard.tb_frame.f_code is guard_function.__code__)
        code=caller.tb_frame.f_locals['code'];need(type(code)is int and -255<=code<=255);return code
    except Exception:return None


def observe_failed_child(journal,phase,ordinal,label,token=None,error=None,original=None):
    try:
        need(token is not None and token['phase']==phase and token['ordinal']==ordinal)
        actual_code=original_result_refusal(error,original,phase);need(actual_code is not None)
        need(phase in ('qualification','prerequisites') and type(ordinal)is int and 1<=ordinal<=256)
        counter='i' if phase=='qualification' else 'n'
        need(type(getattr(journal,counter,None))is int and getattr(journal,counter)==ordinal)
        path=journal.path;need(isinstance(path,Path) and path==token['path']and path.is_absolute()and path.resolve()==path)
        identity=lambda x:(x.st_dev,x.st_ino,x.st_mode,x.st_uid,x.st_gid)
        need(identity(os.fstat(token['fd']))==token['identity']and identity(path.lstat())==token['identity'])
        stamp=lambda v:(v.st_dev,v.st_ino,v.st_mode,v.st_uid,v.st_gid,v.st_nlink,v.st_size,v.st_mtime_ns,v.st_ctime_ns)
        d=path.lstat();need(stat.S_ISDIR(d.st_mode)and stat.S_IMODE(d.st_mode)==0o700 and d.st_uid==os.geteuid())
        fd=os.open(path,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
        seen=[]
        try:
            need(stamp(os.fstat(fd))==stamp(d));prefix=f'{ordinal:02d}'
            def absent(name):
                try:os.stat(name,dir_fd=fd,follow_symlinks=False)
                except FileNotFoundError:return True
                return False
            need(absent(prefix+'-unknown.json'))
            def read(name,limit):
                a=os.stat(name,dir_fd=fd,follow_symlinks=False)
                need(stat.S_ISREG(a.st_mode)and stat.S_IMODE(a.st_mode)==0o600 and a.st_uid==os.geteuid()and a.st_nlink==1 and 0<=a.st_size<=limit)
                f=os.open(name,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK,dir_fd=fd)
                try:
                    need(stamp(os.fstat(f))==stamp(a));raw=b''
                    while len(raw)<a.st_size:
                        part=os.read(f,a.st_size-len(raw));need(bool(part));raw+=part
                    need(os.read(f,1)==b''and stamp(os.fstat(f))==stamp(a));seen.append((name,stamp(a)));return raw
                finally:os.close(f)
            def object_record(name):
                raw=read(name,4096)
                v=diagnostic_json(raw);need(type(v)is dict and canon(v)==raw);return v
            started=object_record(prefix+'-started.json');need(set(started)=={'pid'}and type(started['pid'])is int and 1<=started['pid']<=2147483647)
            terminal=object_record(prefix+'-terminal.json');known='terminal_known' if phase=='qualification' else 'known_terminal'
            need(set(terminal)=={known,'exit_code'}and terminal[known]is True and type(terminal['exit_code'])is int and -255<=terminal['exit_code']<=255 and terminal['exit_code']==actual_code)
            native=None
            if phase=='qualification' and label.startswith('native-'):
                try:native=validate_native_failure(diagnostic_json(read(prefix+'.stdout.private',4096)))
                except Exception:native=None
            need(absent(prefix+'-unknown.json'))
            for name,s in seen:need(stamp(os.stat(name,dir_fd=fd,follow_symlinks=False))==s)
            need(stamp(os.fstat(fd))==stamp(d)and stamp(path.lstat())==stamp(d))
        finally:os.close(fd)
        DIAGNOSTIC.update(child_outcome='known-zero' if terminal['exit_code']==0 else 'known-nonzero',child_exit_code=terminal['exit_code'],native_diagnostic=native)
    except Exception:
        # Observation refusal preserves the original child exception and unknown.
        return

NATIVE_PHASES={'entry','discover','profile','native-bootstrap','before-upgrade','schema','target','service-preflight','credential-profile','native-user','native-api-context','submit-request','submit-service','readback','approve-service','deny-service'}
NATIVE_FAMILIES={'runtime','argument','query','policy','other'}
NATIVE_COMPONENTS={'qualification-helper','project-scope','identity-source','configuration-source','configuration-epochs','content-service','content-store','content-source','content-request','content-plan','query-driver','runtime-profile','other'}
NATIVE_REASONS={'other','native_grant_qualification_refused','qualification_action_refused','content_grant_disabled','content_grant_target_unavailable','content_grant_native_scope_required','content_grant_admin_required','content_grant_schema_required','content_grant_identity_plan_required','content_grant_subject_ineligible','content_grant_target_refused','project_execution_profile_unsupported','project_execution_existing_scope_refused','project_execution_scope_cleanup_failed','project_execution_configuration_stack_unsupported','project_execution_configuration_unknown','project_execution_configuration_cache_stale','project_execution_configuration_control_type_refused','project_execution_configuration_scope_required','project_execution_configuration_database_not_loaded','project_execution_configuration_database_stale','project_execution_configuration_precedence_unsupported','project_execution_guard_required','project_execution_hierarchy_unsupported','project_execution_identity_plan_changed','project_execution_materialized_members_inconsistent','project_execution_source_budget','governance_native_credential_ineligible','native_runtime_library_profile_refused','native_runtime_root_or_extension_refused'}
def validate_native_failure(v):
    need(type(v)is dict and set(v)=={'schema','passed','phase','exceptions','execution_authorized','SQL_execution_status'})
    need(v['schema']=='N05-native-failure-v1'and v['passed']is False and v['execution_authorized']is False and v['SQL_execution_status']=='unknown'and v['phase']in NATIVE_PHASES)
    need(type(v['exceptions'])is list and 1<=len(v['exceptions'])<=2)
    line=lambda n:n is None or type(n)is int and 1<=n<=100000
    for e in v['exceptions']:
        need(type(e)is dict and set(e)=={'family','component','line','reason','query_code','trace'})
        need(e['family']in NATIVE_FAMILIES and e['component']in NATIVE_COMPONENTS and e['reason']in NATIVE_REASONS and line(e['line']))
        need(e['query_code']is None or e['family']=='query'and type(e['query_code'])is int and 0<=e['query_code']<=65535)
        need(type(e['trace'])is list and len(e['trace'])<=2)
        for f in e['trace']:need(type(f)is dict and set(f)=={'component','line'}and f['component']in NATIVE_COMPONENTS and line(f['line']))
    return v

def instrument_journal(cls,phase):
    need(phase in ('qualification','prerequisites'))
    original=cls.run
    def run(self,argv,cwd,timeout=60):
        label='child'
        if len(argv)>4 and argv[1].endswith('/native.php'):
            allowed={'empty','users','tokens','structure','readback','discover','before-upgrade','schema','target','submit-grant','submit-revoke','approve','deny-self','deny-replay'}
            if argv[4] in allowed:label='native-'+argv[4]
        elif len(argv)>2 and argv[1].endswith('/bin/storage') and argv[2]=='upgrade':label='standard-upgrade'
        elif len(argv)>2 and argv[1].endswith('/bin/user') and argv[2]in('approve','empower'):label='user-'+argv[2]
        stage(phase+'-'+label)
        # Independent ordinal: real prerequisite Journal uses n, qualifier uses i.
        ordinal=getattr(self,'_n05_diagnostic_ordinal',0)+1
        self._n05_diagnostic_ordinal=ordinal
        DIAGNOSTIC['child_ordinal']=ordinal
        DIAGNOSTIC['child_outcome']='unknown'
        token=prepare_child_observation(self,phase,ordinal)
        try:
            try:result=original(self,argv,cwd,timeout=timeout)
            except Exception as error:
                observe_failed_child(self,phase,ordinal,label,token,error,original)
                raise
            DIAGNOSTIC['child_outcome']='known-zero';DIAGNOSTIC['child_exit_code']=0
            return result
        finally:
            if token is not None:
                try:os.close(token['fd'])
                except OSError:pass
    cls.run=run

def failure_summary(error):
    allowed={'ValueError':'gate-refused','TimeoutExpired':'child-terminal-unknown','CalledProcessError':'source-command-failed','OSError':'local-operation-failed','KeyError':'required-field-missing','JSONDecodeError':'invalid-json'}
    return {'passed':False,'error_code':allowed.get(type(error).__name__,'unclassified-failure'),**DIAGNOSTIC,'retained':True,'automatic_retry_or_cleanup':False,'SQL_execution_status':'unknown','execution_authorized':False}

def execute(a,*,description_fixture=False):
    need(type(description_fixture)is bool)
    stage("entry")
    need(os.environ.get('GITHUB_ACTIONS')=='true' and os.environ.get('RUNNER_OS')=='Linux')
    need(re.fullmatch('[a-f0-9]{64}',a.mysql_container))
    for p in (a.prerequisites,a.qualification,a.base,a.target):need(p.is_absolute()and p.resolve()==p)
    need(not a.output.exists() and a.output.parent.resolve()==a.output.parent)
    a.output.mkdir(mode=0o700);public=a.output/'public';public.mkdir(mode=0o700)
    stage('source-binding')
    base_tree=source(a.base,BASE);source(a.target,TARGET)
    recipe_revision=current_source(a.current,os.environ.get('GITHUB_SHA',''))
    stage('reviewed-manifests')
    pt=bind_manifest(a.prerequisite_template,a.prerequisites,'prerequisites',a.base)
    qt=bind_manifest(a.qualification_template,a.qualification,'qualification',a.target)
    pmod=module('N05_actual_prerequisites',a.prerequisites/'provision.py',pt['source_sha256'])
    qmod=module('N05_actual_qualification',a.qualification/'qualify.py',qt['source_sha256'])
    # Reuse the real journal: native child outcomes and unknowns remain retained.
    journal=pmod.Journal(a.output/'observer-private')
    # Preserve observer binding before instrumenting native-only journals.
    journal.run=journal.run
    stage('actual-service-inspect')
    rows=json.loads(journal.run(['/usr/bin/docker','inspect',a.mysql_container],a.output))
    port=select_service(rows,a.mysql_container)
    password=os.environ['N05_MYSQL_PASSWORD'];need(0<len(password)<=256 and '\x00'not in password)
    owner=uuid.uuid4().hex;cred=canon({'owner':owner,'user':'root','password':password})
    cp=a.output/'db-credential.private.json';private_new(cp,cred)
    need(str(a.php)in('/usr/bin/php','/usr/local/bin/php','/opt/homebrew/bin/php'))
    stage('runtime-before')
    observed=runtime(json.loads(journal.run([str(a.php),'-r',PROBE,str(cp),str(port)],a.output)))
    stage('private-profile-publication')
    receipt=canon({'schema':'Root-owned-local-MySQL-binding-v1','owner':owner,'container_id':a.mysql_container,'host_ip':'127.0.0.1','host_port':port,'server_uuid':observed['server_uuid'],'source':'actual-Root-Docker-and-SQL-observation'})
    rp=a.output/'owned-db.private.json';private_new(rp,receipt)
    pf=canon(pt);qf=canon(qt);pfr=a.output/'prerequisite-source.private.json';qfr=a.output/'qualification-source.private.json';private_new(pfr,pf);private_new(qfr,qf)
    profile={'schema':'N05-native-prerequisites-v1','owner':owner,'namespace':'n05_grants_'+owner,'base_root':str(a.base),'base_commit':BASE,'base_tree':base_tree,'target_root':str(a.target),'target_commit':TARGET,'target_tree':TREE,'php':str(a.php),'php_sha256':sha(a.php.read_bytes()),'db_port':port,'server_uuid':observed['server_uuid'],'db_credentials_path':str(cp),'db_credentials_sha256':sha(cred),'owned_db_receipt_path':str(rp),'owned_db_receipt_sha256':sha(receipt),'isolated_features_authorized':True}
    pp=a.output/'prerequisite-profile.private.json';pr=a.output/'prerequisite-review.private.json';raw=canon(profile);review=canon({'schema':'N05-prerequisites-Root-review-v1','profile_sha256':sha(raw),'scope':'new-owned-native-namespace-offline-bootstrap-only'});private_new(pp,raw);private_new(pr,review)
    pmod.ROOT_PROFILE_SHA=sha(raw);pmod.ROOT_REVIEW_SHA=sha(review);pmod.ROOT_SOURCE_FREEZE_SHA=sha(pf)
    instrument_journal(pmod.Journal,'prerequisites')
    stage('prerequisites-bind')
    ready=pmod.execute(pp,pr,pfr,a.output/'native-prerequisites-private',description_fixture=description_fixture)
    need(ready['native_prerequisites_observed']is True and ready['execution_authorized']is False)
    stage('qualification-profile-publication')
    q=json.loads((a.output/'native-prerequisites-private/qualification-profile-template.private.json').read_bytes())
    q['runtime_authorized']=True;q['source_inventory_sha256']=sha(canon(qt['native_source_inventory']))
    qp=a.output/'qualification-profile.private.json';qr=a.output/'qualification-review.private.json';raw=canon(q);review=canon({'schema':'N05-native-SQL-qualification-review-v1','profile_sha256':sha(raw),'scope':'owned-native-standard-upgrade-and-grant-metadata-only'});private_new(qp,raw);private_new(qr,review)
    qmod.ROOT_PROFILE_SHA=sha(raw);qmod.ROOT_REVIEW_SHA=sha(review);qmod.FREEZE_PIN=sha(qf)
    instrument_journal(qmod.Journal,'qualification')
    stage('qualification-bind')
    result=qmod.execute(qp,qr,qfr,a.output/'native-qualification-private')
    need(result['executed_SQL']is True and result['execution_authorized']is False)
    stage('runtime-after')
    after=runtime(json.loads(journal.run([str(a.php),'-r',PROBE,str(cp),str(port)],a.output)))
    need(after==observed)
    # Only this allowlisted summary directory is intended for artifact upload.
    stage('public-summary')
    summary={'schema':'N05-CI-native-grant-qualification-v1','recipe_revision':recipe_revision,'recipe_sha256':sha(Path(__file__).read_bytes()),'baseline_revision':BASE,'target_revision':TARGET,'target_tree':TREE,'php_version':observed['php_version'],'mysql_version':observed['mysql_version'],'same_database_at_bracket_observations':True,'whole_window_identity_proved':False,'productionSchemaSpec':result['productionSchemaSpec'],'standard_native_upgrade':result['standard_native_upgrade'],'native_Project_unchanged':result['native_Project_unchanged'],'cases':result['cases'],'execution_authorized':False,'online_activation':False,'Web_MFA_qualified':False}
    private_new(public/'summary.json',canon(summary));return summary

if __name__=='__main__':
    p=argparse.ArgumentParser()
    for n in ('prerequisites','qualification','base','target','current','prerequisite-template','qualification-template','php','output'):p.add_argument('--'+n,required=True,type=Path)
    p.add_argument('--mysql-container',required=True);a=p.parse_args()
    try:print(json.dumps(execute(a),sort_keys=True))
    except Exception as e:
        # Deliberately exclude exception text, native diagnostics and private inputs.
        print(json.dumps(failure_summary(e),sort_keys=True));raise SystemExit(1)

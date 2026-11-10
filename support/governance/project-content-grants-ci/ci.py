"""Reviewed-recipe CI orchestration; never substitutes native authorization."""
import argparse,hashlib,json,os,re,subprocess,sys,types,uuid
from pathlib import Path

BASE='4c2245300dc1d78d042b95190dd537eb6b1f9861'
BASE_TREE='69180e43fba7476257cc534c1934d544995a4443'
TARGET='08629b78bc8b05f3b4e378bab591c303c053185a'
TREE='ae817bb0bbb423467088588965750c73827019f8'
MYSQL='mysql:8.4.13@sha256:a0225d1ea6631c8bc2385dc4c2faba17bbf1834ecf369e1659a9a382bd8d5dd7'
REVIEWED_PINS={'prerequisites': {'native.php': {'bytes': 9741, 'sha256': '3fb91c6e8f51444c90305fa3ebddb607b36f52afa55df44a6e6f07a428a8fb6e'}, 'provision.py': {'bytes': 10580, 'sha256': '4f72cfaf65f70762c2525fd71b6ac066d859e6e8214c3cea108a31327f081d9d'}}, 'qualification': {'native.php': {'bytes': 12077, 'sha256': '5990cfa0fcaed15ba49659bb145650a06ffa51f51e88d0f39a6f3d67fb4b096b'}, 'qualify.py': {'bytes': 9451, 'sha256': 'b3b81f5cf3878d05284f81654ca18827e54835a3d78823282699660bf4a24988'}}}
REVIEWED_INVENTORIES={'prerequisites': 'dd641e2b38de82e6cf829ff6a71f891887ab2815b9171d1ba0e9c96d2259e896', 'qualification': '029ebfaabe0096b53f73445d003ddd7252d339d4ae5d078f51d835f779909038'}
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
OFFLINE_METADATA=('scripts/governance/project_content_grant_source_delta.py','scripts/governance/project_content_grant_ci_source_delta.py')
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
DIAGNOSTIC={'stage':'entry','child_ordinal':None,'child_outcome':'not-observed','child_exit_code':None}
def stage(name):
    DIAGNOSTIC.update(stage=name,child_ordinal=None,child_outcome='not-observed',child_exit_code=None)
def instrument_journal(cls,phase):
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
        try:
            result=original(self,argv,cwd,timeout=timeout)
        except Exception:
            # The journal itself remains private; do not read terminal/intent/stdout.
            raise
        DIAGNOSTIC['child_outcome']='known-zero';DIAGNOSTIC['child_exit_code']=0
        return result
    cls.run=run

def failure_summary(error):
    allowed={'ValueError':'gate-refused','TimeoutExpired':'child-terminal-unknown','CalledProcessError':'source-command-failed','OSError':'local-operation-failed','KeyError':'required-field-missing','JSONDecodeError':'invalid-json'}
    return {'passed':False,'error_code':allowed.get(type(error).__name__,'unclassified-failure'),**DIAGNOSTIC,'retained':True,'automatic_retry_or_cleanup':False,'SQL_execution_status':'unknown','execution_authorized':False}

def execute(a):
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
    ready=pmod.execute(pp,pr,pfr,a.output/'native-prerequisites-private')
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

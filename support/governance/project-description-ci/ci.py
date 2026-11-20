"""Real native description qualification; imports/pure controls never open SQL.

The shared reviewed metadata recipe owns fresh bootstrap and standard migrations.
A private exact successor admits one generated profile; tracked PHP stays null.
"""
import argparse,hashlib,json,os,re,subprocess,types,stat
from contextlib import contextmanager
from pathlib import Path
HERE=Path(__file__).resolve().parent
TARGET='c7e5b9927d5c2fee5d4d9766d613e51ec400d11b'
TREE='a8294f30cda758d289d142a2949981fb425043aa'
sha=lambda b:hashlib.sha256(b).hexdigest()
canon=lambda d:json.dumps(d,sort_keys=True,separators=(',',':')).encode()+b'\n'
PHASE='entry'
NATIVE_FAILURE=None
METADATA_FAILURE=None
CASES={'no-grant','user-positive','audit-fault-rollback','direct-modular-no-scope','revoked','team-positive','expired','credential-revoked','account-disabled'}
def need(v):
 if not v:raise ValueError('description_ci_refused')
def git(root,*args):return subprocess.check_output(['/usr/bin/git','--no-replace-objects','-C',str(root),*args],stderr=subprocess.DEVNULL)
def tracked(root,path,revision):
 p=root/path;need(p.resolve()==p and p.is_file());b=p.read_bytes();need(b==git(root,'show',revision+':'+path));return b
def load_shared(root,revision):
 lock=json.loads(tracked(root,'support/governance/project-description-ci/metadata-lock.json',revision))
 path='support/governance/project-content-grants-ci/ci.py';b=tracked(root,path,revision);need(sha(b)==lock['ci_sha256'])
 m=types.ModuleType('description_reviewed_metadata_recipe');m.__file__=str(root/path);exec(compile(b,m.__file__,'exec'),m.__dict__)
 need(m.TARGET==TARGET and m.TREE==TREE)
 # Each helper/template must be the independently locked reviewed recipe.
 need(set(lock)=={'ci_sha256','files','description_template_sha256'})
 need(sha(tracked(root,'support/governance/project-description-qualification/native.php',revision))==lock['description_template_sha256'])
 need(set(lock['files'])=={'support/governance/project-content-grants-ci/prerequisites/provision.py','support/governance/project-content-grants-ci/prerequisites/native.php','support/governance/project-content-grants-qualification/qualify.py','support/governance/project-content-grants-qualification/native.php','support/governance/project-content-grants-ci/prerequisites-template.json','support/governance/project-content-grants-ci/qualification-template.json'})
 for p,h in lock['files'].items():need(sha(tracked(root,p,revision))==h)
 return m

# Task-v2 only: exact PHP security continuation, never a general version reducer.
TASK_PHP13_CONTINUATIONS={
 'support/governance/project-content-grants-ci/ci.py':(
  'da1f1c6db0cf51ccfc4dba9d630233458d6f684add64ff14e6924faf8c536a9a',
  '0db5be8fef363406c6dce16d0eb2dcc5a33ac2aea6ee9e6e083759654d92901c',
  b"need(v['php_version']=='8.5.13' and v['mysql_version']=='8.4.13')"),
 'support/governance/project-description-qualification/native.php':(
  '0f377110eb5186ec8f56efe70ff57b4b2a87f775fb0561d73ff9b21ca64cb9e3',
  '0451da98e73b118cb066d5ef8a73bface226b8469dc199062521271d43834434',
  b"qneed(PHP_VERSION==='8.5.13'&&in_array(ini_get('opcache.jit'),array('','0','disable','off'),true)&&(string)ini_get('opcache.jit_buffer_size')==='0'&&(string)ini_get('opcache.enable_cli')==='0');"),
}
def task_php13_continuation(path,raw,parent_pin):
 need(type(path)is str and path in TASK_PHP13_CONTINUATIONS and type(raw)is bytes)
 current,parent,anchor=TASK_PHP13_CONTINUATIONS[path]
 need(parent_pin==parent and sha(raw)==current and raw.count(anchor)==1)
 old=anchor.replace(b'8.5.13',b'8.5.12')
 restored=raw.replace(anchor,old,1)
 need(sha(restored)==parent and restored.replace(old,anchor,1)==raw)
 return raw

def load_shared_task_v2(root,revision):
 import sys
 folder=str(root/'scripts/governance')
 if folder not in sys.path:sys.path.insert(0,folder)
 import source_operation_snapshot as source
 with source.open_task_source_operation(root,expected_checkpoint=revision)as operation:
  source.require_task_operation(operation,root)
  relative='support/governance/project-content-grants-ci/ci.py';raw=operation._task_contexts[root].current(relative)
  lock=json.loads(tracked(root,'support/governance/project-description-ci/task-metadata-lock-v2.json',revision))
  need(set(lock)=={'schema','parent_lock_sha256','current_ci_sha256'}and lock['schema']=='Task-CI-shared-metadata-lock-v2')
  need(sha(tracked(root,'support/governance/project-description-ci/metadata-lock.json',revision))==lock['parent_lock_sha256'])
  task_php13_continuation(relative,raw,lock['current_ci_sha256'])
  old=json.loads(tracked(root,'support/governance/project-description-ci/metadata-lock.json',revision))
  need(set(old)=={'ci_sha256','files','description_template_sha256'})
  for path,pin in old['files'].items():need(sha(tracked(root,path,revision))==pin)
  template_path='support/governance/project-description-qualification/native.php'
  task_php13_continuation(template_path,tracked(root,template_path,revision),old['description_template_sha256'])
  module=types.ModuleType('Task_actual_CURRENT_shared_CI');module.__file__=str(root/relative);exec(compile(raw,module.__file__,'exec'),module.__dict__)
  need(module.TARGET==TARGET and module.TREE==TREE)
 return module

def successor(template,pin,kind):
 need(type(pin)is str and re.fullmatch('[a-f0-9]{64}',pin))
 name={'fixture':'N05_DESCRIPTION_FIXTURE_PROFILE_SHA256','cases':'N05_DESCRIPTION_ROOT_PROFILE_SHA256'}[kind]
 marker=('const '+name+'=null;').encode();need(template.count(marker)==1)
 result=template.replace(marker,('const '+name+"='"+pin+"';").encode())
 need(result.replace(('const '+name+"='"+pin+"';").encode(),marker)==template)
 return result

def result_ok(v):
 need(type(v)is dict and set(v)=={'schema','cases','initial_capabilities','execution_authorized','HTTP_verified','publish_worker_executed','mail_feed_search_delivery_verified','online_activation'} and v.get('schema')=='N05-native-description-SQL-cases-v1')
 need(v.get('execution_authorized')is False and v.get('HTTP_verified')is False and v.get('publish_worker_executed')is False and v.get('mail_feed_search_delivery_verified')is False and v.get('online_activation')is False)
 need(type(v.get('initial_capabilities'))is dict and set(v['initial_capabilities'])=={'native_VIEW','native_EDIT','ordinary_account'})
 need(v['initial_capabilities']['native_VIEW']is True and v['initial_capabilities']['native_EDIT']is False and v['initial_capabilities']['ordinary_account']is True)
 need(type(v.get('cases'))is dict and set(v['cases'])==CASES)
 for k,r in v['cases'].items():
  need(type(r)is dict)
  if k in ('user-positive','team-positive'):
   need(set(r)=={'native_applyTransactions_committed','description_and_use_audit_persisted','native_EDIT_granted','security_and_other_fields_unchanged','native_publish_task_persisted','native_mail_and_feed_intent_persisted','publish_worker_executed','mail_feed_search_delivery_verified'})
   for field in ('native_applyTransactions_committed','description_and_use_audit_persisted','security_and_other_fields_unchanged','native_publish_task_persisted','native_mail_and_feed_intent_persisted'):need(r.get(field)is True)
   for field in ('native_EDIT_granted','publish_worker_executed','mail_feed_search_delivery_verified'):need(r.get(field)is False)
  else:
   need(set(r)==({'refused','all_target_bytes_unchanged','real_SQL_trigger_rejected_use_audit','description_transaction_and_queue_rolled_back'}if k=='audit-fault-rollback'else {'refused','all_target_bytes_unchanged'}))
   need(r.get('refused')is True and r.get('all_target_bytes_unchanged')is True)
 need(v['cases']['audit-fault-rollback'].get('real_SQL_trigger_rejected_use_audit')is True and v['cases']['audit-fault-rollback'].get('description_transaction_and_queue_rolled_back')is True)
 return v


PHASES={'entry','profile','source','database-binding','native-bootstrap','runtime-profile','admin-actors','recipient','recipient-credential','native-cases','result'}
SOURCES={'helper','cases','metadata-helper','executor','description-transaction','scope','store','service','identity','editor','query-driver','other'}
ERRNOS={'access-denied','database-missing','unknown-column','duplicate-key','table-missing','lock-timeout','deadlock','audit-trigger','connection-refused','connection-lost','connection-read-lost'}
def decode_exact(raw):
 def pairs(items):
  d={}
  for k,v in items:need(k not in d);d[k]=v
  return d
 return json.loads(raw,object_pairs_hook=pairs,parse_constant=lambda _:need(False))
def diagnostic(raw):
 need(type(raw)is bytes and len(raw)<=4096)
 marker=b'description_qualification_refused_retain_no_retry\n'
 need(raw.startswith(marker)and raw.endswith(b'\n')and raw.count(b'\n')==2)
 v=decode_exact(raw[len(marker):-1]);need(type(v)is dict and set(v)=={'schema','phase','family','errno','locations','execution_authorized','SQL_execution_status'})
 need(v['schema']=='N05-description-native-failure-v1'and type(v['phase'])is str and v['phase']in PHASES)
 need(type(v['family'])is str and v['family']in {'runtime','argument','json','query','other'})
 need(v['errno']is None or(type(v['errno'])is str and v['errno']in ERRNOS and v['family']=='query'))
 need(v['execution_authorized']is False and v['SQL_execution_status']=='unknown')
 need(type(v['locations'])is list and 1<=len(v['locations'])<=4)
 for f in v['locations']:
  need(type(f)is dict and set(f)=={'source','line'}and type(f['source'])is str and f['source']in SOURCES and type(f['line'])is int and 0<=f['line']<=100000)
  need((f['source']=='other'and f['line']==0)or(f['source']!='other'and f['line']>=1))
 return v

def journal_bytes(path,limit):
 # Read even empty private stdout, binding one stable regular inode throughout.
 need(path.is_absolute()and path.resolve()==path)
 a=path.lstat();need(stat.S_ISREG(a.st_mode)and stat.S_IMODE(a.st_mode)==0o600 and a.st_uid==os.geteuid()and a.st_nlink==1 and 0<=a.st_size<=limit)
 fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW)
 try:
  raw=os.read(fd,limit+1);stamp=lambda t:(t.st_dev,t.st_ino,t.st_mode,t.st_uid,t.st_gid,t.st_nlink,t.st_size,t.st_mtime_ns,t.st_ctime_ns)
  need(len(raw)==a.st_size and stamp(a)==stamp(os.fstat(fd))==stamp(path.lstat()));return raw
 finally:os.close(fd)

def observed_failure(journal,ordinal,argv):
 # Exact invocation ordinal, intent and known nonzero terminal; no fallback to a
 # previous child, unrelated file, guessed active PID or unknown outcome.
 need(type(ordinal)is int and ordinal>=1 and type(journal.n)is int and journal.n==ordinal and type(argv)is list and len(argv)==4 and all(type(x)is str for x in argv))
 prefix=f'{ordinal:02d}';read=lambda suffix,limit=65536:journal_bytes(journal.path/(prefix+suffix),limit)
 unknown=journal.path/(prefix+'-unknown.json');need(not unknown.exists()and not unknown.is_symlink())
 intent=decode_exact(read('-intent.json'));need(type(intent)is dict and set(intent)=={'argv','signals_retry_cleanup'}and intent['argv']==argv and intent['signals_retry_cleanup']is False)
 started=decode_exact(read('-started.json'));need(type(started)is dict and set(started)=={'pid'}and type(started['pid'])is int and started['pid']>0)
 terminal=decode_exact(read('-terminal.json'));need(type(terminal)is dict and set(terminal)=={'known_terminal','exit_code'}and terminal['known_terminal']is True and type(terminal['exit_code'])is int and 1<=terminal['exit_code']<=255)
 need(read('.stdout.private',1048576)==b'')
 return diagnostic(read('.stderr.private',4096))

def native_cases(journal,argv,cwd):
 global NATIVE_FAILURE
 NATIVE_FAILURE=None;ordinal=journal.n+1
 try:return journal.run(argv,cwd,timeout=180)
 except Exception:
  try:NATIVE_FAILURE=observed_failure(journal,ordinal,argv)
  except Exception:NATIVE_FAILURE=None
  raise

DESCRIPTION_FIXTURE_CURRENT_SHA='7a9c28521c551e43bea04f74d5f52e4586f7bc0eb99b5f5a2f323e091c1ab696'
DESCRIPTION_FIXTURE_PARENT_SHA='754546ac5223a5631fcec55d354293564e0dc8e89a8b0ff616bbd55551fe1955'
def task_fixture_continuation(raw):
 anchor=b"nneed(PHP_VERSION==='8.5.13' && ini_get('opcache.jit')==='disable' && ini_get('opcache.jit_buffer_size')==='0' && ini_get('opcache.enable_cli')==='0');"
 need(type(raw)is bytes and sha(raw)==DESCRIPTION_FIXTURE_CURRENT_SHA and raw.count(anchor)==1)
 prior=anchor.replace(b'8.5.13',b'8.5.12');restored=raw.replace(anchor,prior,1)
 need(sha(restored)==DESCRIPTION_FIXTURE_PARENT_SHA and restored.replace(prior,anchor,1)==raw)
 return raw

@contextmanager
def task_description_fixture_factory(shared,prerequisites,fixture):
 # Only the first prerequisites module calls sequence(description_fixture=True).
 # The later description_prerequisites module only decodes private data/Journal.
 task_fixture_continuation(fixture)
 expected=prerequisites/'provision.py';pins=shared.REVIEWED_PINS['prerequisites']
 need(pins=={'native.php':{'bytes':9741,'sha256':'3fb91c6e8f51444c90305fa3ebddb607b36f52afa55df44a6e6f07a428a8fb6e'},'provision.py':{'bytes':11688,'sha256':'a71ea657dbf8bffde7e292f634664d196b3dc02f86b423ed892b8c89bd4d0b35'}})
 original=shared.module
 def factory(name,path,provided):
  if name=='N05_actual_prerequisites' or path==expected:
   need(name=='N05_actual_prerequisites'and path==expected and provided==pins)
   module=original(name,path,provided)
   need(type(module)is types.ModuleType and module.__file__==str(expected)and module.DESCRIPTION_FIXTURE_SHA==DESCRIPTION_FIXTURE_PARENT_SHA)
   before=dict(vars(module));module.DESCRIPTION_FIXTURE_SHA=DESCRIPTION_FIXTURE_CURRENT_SHA
   after=dict(vars(module));after['DESCRIPTION_FIXTURE_SHA']=DESCRIPTION_FIXTURE_PARENT_SHA
   need(before==after)
   return module
  return original(name,path,provided)
 shared.module=factory
 try:yield
 finally:shared.module=original

METADATA_STAGES={'entry','source-binding','reviewed-manifests','actual-service-inspect','runtime-before','private-profile-publication','prerequisites-bind','qualification-profile-publication','qualification-bind','runtime-after','public-summary'}
METADATA_CHILD_LABELS={'child','standard-upgrade','user-approve','user-empower'}|{'native-'+x for x in {'empty','users','tokens','structure','readback','discover','before-upgrade','schema','target','submit-grant','submit-revoke','approve','deny-self','deny-replay'}}
METADATA_STAGES|={phase+'-'+label for phase in ('prerequisites','qualification')for label in METADATA_CHILD_LABELS}
def metadata_failure(shared,error):
 # The shared recipe already projects exceptions. Revalidate a smaller fixed
 # subset; no exception text, argv, path, credential or raw child output escapes.
 value=shared.failure_summary(error)
 need(type(value)is dict and value.get('passed')is False and value.get('retained')is True and value.get('automatic_retry_or_cleanup')is False and value.get('SQL_execution_status')=='unknown'and value.get('execution_authorized')is False)
 code=value.get('error_code');phase=value.get('stage');ordinal=value.get('child_ordinal');outcome=value.get('child_outcome');exit_code=value.get('child_exit_code')
 need(type(code)is str and code in {'gate-refused','child-terminal-unknown','source-command-failed','local-operation-failed','required-field-missing','invalid-json','unclassified-failure'})
 need(type(phase)is str and phase in METADATA_STAGES)
 need(ordinal is None or type(ordinal)is int and 1<=ordinal<=256)
 need(type(outcome)is str and outcome in {'not-observed','unknown','known-zero','known-nonzero'})
 need(exit_code is None or type(exit_code)is int and -255<=exit_code<=255)
 need((outcome=='known-zero'and exit_code==0)or(outcome=='known-nonzero'and exit_code is not None and exit_code!=0)or(outcome in {'not-observed','unknown'}and exit_code is None))
 return {'stage':phase,'error_code':code,'child_ordinal':ordinal,'child_outcome':outcome,'child_exit_code':exit_code}

def execute(a):
 global PHASE,NATIVE_FAILURE,METADATA_FAILURE
 NATIVE_FAILURE=None
 METADATA_FAILURE=None
 need(a.current.is_absolute()and a.current.resolve()==a.current)
 revision=a.expected_current_revision if a.task_source_v2 else os.environ.get('GITHUB_SHA','');need(type(revision)is str and re.fullmatch('[a-f0-9]{40}',revision))
 for p in ('ci.py','fixture.php','metadata-lock.json'):tracked(a.current,'support/governance/project-description-ci/'+p,revision)
 m=load_shared_task_v2(a.current,revision) if a.task_source_v2 else load_shared(a.current,revision);(m.current_source_task_v2 if a.task_source_v2 else m.current_source)(a.current,revision)
 # The real shared recipe guards the exact service, runtime, fresh owned DB and
 # all original current-source/provisioning/native standard-upgrade boundaries.
 PHASE='metadata-provision-and-qualification'
 try:
  if a.task_source_v2:
   fixture=tracked(a.current,'support/governance/project-description-ci/fixture.php',revision)
   with task_description_fixture_factory(m,a.prerequisites,fixture):meta=m.execute(a,description_fixture=True)
  else:meta=m.execute(a,description_fixture=True)
 except Exception as error:
  try:METADATA_FAILURE=metadata_failure(m,error)
  except Exception:METADATA_FAILURE=None
  raise
 need(meta['target_revision']==TARGET and meta['target_tree']==TREE and meta['standard_native_upgrade']is True and meta['productionSchemaSpec']is True and meta['execution_authorized']is False)
 PHASE='private-description-profile'
 pmod=m.module('description_prerequisites',a.prerequisites/'provision.py',m.REVIEWED_PINS['prerequisites'])
 q=pmod.decode(pmod.private(a.output/'qualification-profile.private.json'))
 receipt=pmod.private(a.output/'owned-db.private.json');db=pmod.decode(receipt)
 cfg=pmod.private(a.target/'conf/local/local.json')
 need(q['source_root']==str(a.target)and q['namespace']=='n05_grants_'+q['owner'] and q['server_uuid']==db['server_uuid'])
 need(sha(cfg)==q['local_configuration_sha256'])
 inventory=json.loads(a.qualification_template.read_bytes())['native_source_inventory']
 sourcepins={p:v['sha256']for p,v in inventory.items()if p.startswith(('src/','support/','resources/'))}
 for path in ('support/governance/project-description-qualification/native.php','support/governance/project-description-qualification/cases.php'):
  # Actual runtime root stays signed T3; diagnostic helper is a separately
  # reviewed current-recipe successor, not a fictitious mutation of T3 bytes.
  sourcepins[path]=sha(tracked(a.target,path,TARGET))
 profile={'schema':'N05-native-description-qualification-v1','owner':q['owner'],'namespace':q['namespace'],'source_root':str(a.target),'isolated_features_authorized':True,'HTTP_authorized':False,'worker_delivery_authorized':False,'source_sha256':sourcepins,'owned_db_receipt_path':str(a.output/'owned-db.private.json'),'owned_db_receipt_sha256':sha(receipt),'server_uuid':db['server_uuid'],'admin_actors_path':q['actors_path'],'admin_actors_sha256':q['actors_sha256'],'target_phid':q['target_phid'],'subjects':q['subjects'],'recipient_actors_output':str(a.output/'description-recipient.private.json')}
 helper=a.output/'description-helpers-private';helper.mkdir(mode=0o700)
 library=helper/'project-content-grants-qualification';library.mkdir(mode=0o700)
 lib=tracked(a.current,'support/governance/project-content-grants-qualification/native.php',revision);m.private_new(library/'native.php',lib)
 # cases live in this exact sibling for the preparation template's decoder only.
 dc=helper/'project-description-qualification';dc.mkdir(mode=0o700)
 rawcases=tracked(a.current,'support/governance/project-description-qualification/cases.php',revision);need(sha(rawcases)=='b4f884f9aa06cd4a656b8df175972481d933b9d6221e471df8b468aaa41081db');m.private_new(dc/'cases.php',rawcases)
 journal=pmod.Journal(a.output/'description-journal-private')
 prepared=pmod.decode(pmod.private(a.output/'native-prerequisites-private/description-fixture.private.json'))
 need(set(prepared)=={'schema','target_phid','owner','namespace','source_revision','native_creation','recipient_VIEW','recipient_EDIT','target_flags_enabled','execution_authorized'})
 need(prepared.get('schema')=='N05-description-early-native-fixture-v1'and prepared.get('owner')==q['owner']and prepared.get('namespace')==q['namespace']and prepared.get('source_revision')==m.BASE and prepared.get('native_creation')is True and prepared.get('recipient_VIEW')is True and prepared.get('recipient_EDIT')is False and prepared.get('target_flags_enabled')is False and prepared.get('execution_authorized')is False)
 need(re.fullmatch('PHID-PROJ-[A-Za-z0-9]{20}',prepared.get('target_phid',''))and prepared['target_phid']!=q['target_phid'])
 profile['target_phid']=prepared['target_phid'];pp=a.output/'description-profile.private.json';raw=canon(profile);m.private_new(pp,raw)
 # This one private copied helper changes ONLY the null profile pin, not source.
 native=dc/'native.php';template_path='support/governance/project-description-qualification/native.php';template=tracked(a.current,template_path,revision)
 if a.task_source_v2:task_php13_continuation(template_path,template,'0451da98e73b118cb066d5ef8a73bface226b8469dc199062521271d43834434')
 else:need(sha(template)=='0451da98e73b118cb066d5ef8a73bface226b8469dc199062521271d43834434')
 m.private_new(native,successor(template,sha(raw),'cases'))
 (m.current_source_task_v2 if a.task_source_v2 else m.current_source)(a.current,revision)
 PHASE='native-nine-cases'
 result=result_ok(pmod.decode(native_cases(journal,[str(a.php),str(native),str(a.target),str(pp)],a.target)))
 PHASE='bracket-after'
 after=m.runtime(json.loads(journal.run([str(a.php),'-r',m.PROBE,str(a.output/'db-credential.private.json'),str(db['host_port'])],a.output)))
 need(after['server_uuid']==db['server_uuid']);(m.current_source_task_v2 if a.task_source_v2 else m.current_source)(a.current,revision)
 summary={'schema':'N05-description-CI-real-native-v1','passed':True,'recipe_revision':revision,'target_revision':TARGET,'target_tree':TREE,'php':'8.5.13','mysql':'8.4.13','native_standard_upgrade':True,'fixture_source_revision':m.BASE,'fixture_created_before_target_flags':True,'metadata_qualification':True,'description':result,'description_template_sha256':sha(template),'same_database_at_bracket_observations':True,'whole_window_identity_proved':False,'cleanup_executed':False,'owned_namespace_retained':True,'automatic_retry':False,'HTTP_verified':False,'worker_delivery_verified':False,'execution_authorized':False,'online_activation':False}
 m.private_new(a.output/'public/description-summary.json',canon(summary));return summary

def failure():
 result={'schema':'N05-description-CI-failure-v1','passed':False,'phase':PHASE,'SQL_execution_status':'unknown','retained':True,'automatic_retry_or_cleanup':False,'execution_authorized':False}
 if NATIVE_FAILURE is not None:result['native_failure']=NATIVE_FAILURE
 if METADATA_FAILURE is not None:result['metadata_failure']=METADATA_FAILURE
 return result
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--task-source-v2',action='store_true');p.add_argument('--expected-current-revision')
 for n in ('prerequisites','qualification','base','target','current','prerequisite-template','qualification-template','php','output'):p.add_argument('--'+n,required=True,type=Path)
 p.add_argument('--mysql-container',required=True);a=p.parse_args()
 try:print(json.dumps(execute(a),sort_keys=True))
 except Exception:print(json.dumps(failure(),sort_keys=True));raise SystemExit(1)

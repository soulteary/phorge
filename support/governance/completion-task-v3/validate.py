"""Private-profile-bound Task additive schema proof. No DB/engine/normalization fallback.
Only the directory Task-OFF profile is supported; Task metadata qualification
with populated tables is a different protocol and is never assumed empty here.
"""
import argparse,copy,hashlib,hmac,json,os,re,stat
from pathlib import Path
SQL_SHA='632b4412c18ea61787ca6b38afc9d3c16b62f76591e9a99d624401eeac117236'
SPEC_SHA='7cac65fe600e2681eed05618fd584a02f37e27b7f532d6ca2b59244bf5045ccb'
SQL_PATH='resources/sql/autopatches/20261112.task.contentgrants.sql'
SPEC_PATH='src/infrastructure/governance/content/PhorgeTaskContentGrantSchemaSpec.php'
NAMES=('governance_taskcontentapproval','governance_taskcontentaudit','governance_taskcontentgrant')
CAPTURE_CLEANUP_MODES=frozenset(('directory-Task-OFF','conduit-Task-OFF',
 'oauth-access-Task-OFF','oauth-code-Task-OFF','oauth-authorization-Task-OFF',
 'oauth-client-lifecycle-Task-OFF','assessment-credential-Task-OFF','api-credential-concurrency-Task-OFF','application-config-facts-Task-OFF','project-source-facts-Task-OFF','spaces-source-facts-Task-OFF'))
PROFILE_KEYS={'schema','runtime_authorized','mode','run_id','hmac_key','source_root','source_revision','source_pins','namespace','server_uuid','fixture_nonce_sha256','standard_upgrade_receipt_sha256','patch_discovery','parent_table_names','parent_patch_keys','parent_migration_hashes','mysql_version'}
def need(v):
 if v is not True:raise ValueError('Task_additive_schema_proof_refused')
def hex64(v):return type(v)is str and re.fullmatch('[a-f0-9]{64}',v)is not None and v!='0'*64
def sha(b):return hashlib.sha256(b).hexdigest()
def encoded(v):return json.dumps(v,sort_keys=True,separators=(',',':'),ensure_ascii=False,allow_nan=False).encode()
def decode(raw):
 def pairs(items):
  out={}
  for k,v in items:need(k not in out);out[k]=v
  return out
 return json.loads(raw,object_pairs_hook=pairs,parse_constant=lambda _:need(False))
def private(path):
 p=Path(path);need(p.is_absolute()and p.resolve()==p);a=p.lstat();need(stat.S_ISREG(a.st_mode)and a.st_uid==os.geteuid()and a.st_nlink==1 and stat.S_IMODE(a.st_mode)==0o600 and 0<a.st_size<=2097152)
 fd=os.open(p,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
 try:
  raw=os.read(fd,a.st_size+1);stamp=lambda s:(s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid,s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
  need(len(raw)==a.st_size and stamp(a)==stamp(os.fstat(fd))==stamp(p.lstat()));return raw
 finally:os.close(fd)
def process_output(path):
 p=Path(path);need(p.is_absolute()and p.resolve()==p);a=p.lstat();need(stat.S_ISREG(a.st_mode)and a.st_uid==os.geteuid()and a.st_nlink==1 and stat.S_IMODE(a.st_mode)==0o600 and 0<=a.st_size<=2097152)
 fd=os.open(p,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
 try:
  raw=os.read(fd,a.st_size+1);stamp=lambda s:(s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid,s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
  need(len(raw)==a.st_size and stamp(a)==stamp(os.fstat(fd))==stamp(p.lstat()));return raw
 finally:os.close(fd)
def column(name,kind,nullable=False,auto=False):
 text=kind=='mediumtext';return {'name':name,'type':kind,'nullable':nullable,'auto_increment':auto,'default':None,'charset':'utf8mb4'if text else None,'collation':'utf8mb4_bin'if text else None}
def expected_schema():
 def table(columns,indexes):
  for i,c in enumerate(columns,1):c['ordinal']=i
  return {'engine':'InnoDB','columns':columns,'indexes':{k:{'columns':v[0],'unique':v[1],'index_type':'BTREE','prefixes':[None]*len(v[0]),'visible':True}for k,v in indexes.items()},'constraints':[{'name':'PRIMARY','type':'PRIMARY KEY'}],'triggers':[]}
 return {
 'governance_taskcontentgrant':table([column('grantID','varbinary(64)'),column('targetPHID','varbinary(64)'),column('operation','varbinary(32)'),column('subjectKind','varbinary(16)'),column('subjectID','varbinary(64)'),column('validFrom','bigint unsigned'),column('validUntil','bigint unsigned'),column('revokedAt','bigint unsigned',True),column('version','bigint unsigned')],{'PRIMARY':(['grantID'],True),'key_scope':(['targetPHID','operation','subjectKind','subjectID'],False)}),
 'governance_taskcontentapproval':table([column('approvalID','varbinary(64)'),column('targetPHID','varbinary(64)'),column('bindingJSON','mediumtext'),column('bindingDigest','varbinary(64)'),column('state','varbinary(16)'),column('approverBindingJSON','mediumtext',True),column('appliedAt','bigint unsigned',True),column('version','bigint unsigned')],{'PRIMARY':(['approvalID'],True),'key_target':(['targetPHID'],False)}),
 'governance_taskcontentaudit':table([column('id','bigint unsigned',False,True),column('approvalID','varbinary(64)'),column('grantID','varbinary(64)'),column('targetPHID','varbinary(64)'),column('actorPHID','varbinary(64)'),column('action','varbinary(32)'),column('createdAt','bigint unsigned')],{'PRIMARY':(['id'],True),'key_approval':(['approvalID'],False),'key_grant':(['grantID'],False)})}
def profile(path,expected_sha):
 need(hex64(expected_sha));raw=private(path);need(sha(raw)==expected_sha);p=decode(raw)
 need(type(p)is dict and set(p)==PROFILE_KEYS and p['schema']=='Completion-private-schema-v3'and p['runtime_authorized']is True and p['mode']in{'completion-v3'})
 need(hex64(p['hmac_key'])and re.fullmatch('[a-f0-9]{32}',p['run_id'])is not None)
 need(re.fullmatch('[a-f0-9]{40}',p['source_revision'])is not None and re.fullmatch('governance_native_[a-f0-9]{16}',p['namespace'])is not None)
 need(re.fullmatch('[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}',p['server_uuid'])is not None and hex64(p['fixture_nonce_sha256'])and hex64(p['standard_upgrade_receipt_sha256']))
 root=Path(p['source_root']);need(root.is_absolute()and root.resolve()==root)
 need(p['mysql_version']=='8.4.13')
 need(type(p['source_pins'])is dict and set(p['source_pins'])=={SQL_PATH,SPEC_PATH,'support/governance/completion/fixture.php','support/governance/completion-task-v3/capture.php','resources/sql/quickstart.sql'})
 for relative,pin in p['source_pins'].items():need(hex64(pin)and sha((root/relative).read_bytes())==pin)
 need(p['source_pins'][SQL_PATH]==SQL_SHA and p['source_pins'][SPEC_PATH]==SPEC_SHA)
 d=p['patch_discovery'];need(type(d)is dict and set(d)=={'full_key','leaf_key','path','sha256','discovered_by'}and d['path']==SQL_PATH and d['sha256']==SQL_SHA and d['leaf_key']==Path(SQL_PATH).name and d['discovered_by']=='PhabricatorSQLPatchList.buildAllPatches/getFullKey')
 need(type(d['full_key'])is str and 1<=len(d['full_key'])<=256)
 for key,n in(('parent_table_names',549),('parent_patch_keys',1575)):
  rows=p[key];need(type(rows)is list and len(rows)==n and rows==sorted(set(rows))and all(type(s)is str and 0<len(s)<=256 for s in rows))
 need(d['full_key']not in p['parent_patch_keys']);need(all('maniphest.'+n not in p['parent_table_names']for n in NAMES))
 need(type(p['parent_migration_hashes'])is dict and d['full_key']not in p['parent_migration_hashes']and all(type(k)is str and hex64(v)for k,v in p['parent_migration_hashes'].items()))
 return p
CAPTURE_KEYS={'schema','profile_sha256','run_id','phase','capture_id','source_root','source_revision','source_pins','namespace','server_uuid','fixture_nonce_sha256','task_feature_enabled','standard_upgrade_receipt_sha256','patch_discovery','source_schema_spec','observed_schema','row_counts','table_inventory','source_patch_keys','patch_ledger','restore','read_only_transaction','raw_user_rows_exported','php_version','mysql_version','config_epoch'}
def check_capture(sealed,p,pin,phase):
 need(type(sealed)is dict and set(sealed)=={'payload','mac'}and hex64(sealed['mac']));d=sealed['payload'];need(type(d)is dict and set(d)==CAPTURE_KEYS)
 need(hmac.compare_digest(hmac.new(bytes.fromhex(p['hmac_key']),encoded(d),hashlib.sha256).hexdigest(),sealed['mac']))
 need(d['schema']=='Completion-schema-capture-v3'and d['profile_sha256']==pin and d['run_id']==p['run_id']and d['phase']==phase and re.fullmatch('[a-f0-9]{32}',d['capture_id'])is not None)
 for k in('source_root','source_revision','source_pins','namespace','server_uuid','fixture_nonce_sha256','standard_upgrade_receipt_sha256','patch_discovery'):need(d[k]==p[k])
 need(d['php_version']=='8.5.12'and d['mysql_version']==p['mysql_version'])
 need(d['task_feature_enabled']is False and d['read_only_transaction']is True and d['raw_user_rows_exported']is False)
 need(encoded(d['source_schema_spec'])==encoded(expected_schema())and encoded(d['observed_schema'])==encoded(expected_schema()))
 need(encoded(d['config_epoch'])==encoded([{'namespace':'default','config_key':'governance.task-content-grants.enabled','epoch':1}]))
 need(type(d['row_counts'])is dict and set(d['row_counts'])==set(NAMES)and all(type(n)is int and n==0 for n in d['row_counts'].values()))
 expected_tables=sorted(p['parent_table_names']+['maniphest.'+n for n in NAMES]);expected_patches=sorted(p['parent_patch_keys']+[p['patch_discovery']['full_key']])
 need(d['table_inventory']==expected_tables and d['source_patch_keys']==expected_patches and d['patch_ledger']==expected_patches)
 restore=d['restore'];need(type(restore)is dict and restore.get('current_table_count')==552 and type(restore['current_table_count'])is int)
 a=restore.get('patch_audit');need(type(a)is dict and a.get('source_patch_count')==1576 and a.get('applied_patch_count')==1576 and a.get('pending_patch_keys')==[]and a.get('current_patch_ledger_complete')is True)
 need(a.get('migration_source_hashes')=={**p['parent_migration_hashes'],p['patch_discovery']['full_key']:SQL_SHA}and a.get('migrations_executed')==len(p['parent_migration_hashes'])+1)
 return d

def validate_envelope(envelope,profile_path,expected_profile_sha,*,expected_mode="completion-v3"):
 p=profile(profile_path,expected_profile_sha)
 need(expected_mode in{"completion-v3"}and p["mode"]==expected_mode)
 envelope_schema="Completion-Task-envelope-v3"
 need(type(envelope)is dict and set(envelope)=={'schema','profile_sha256','native_receipt','before','after','mac'}and envelope['schema']==envelope_schema and envelope['profile_sha256']==expected_profile_sha and hex64(envelope['mac']))
 unsigned={k:v for k,v in envelope.items()if k!='mac'};need(hmac.compare_digest(hmac.new(bytes.fromhex(p['hmac_key']),encoded(unsigned),hashlib.sha256).hexdigest(),envelope['mac']))
 before=check_capture(envelope['before'],p,expected_profile_sha,'before');after=check_capture(envelope['after'],p,expected_profile_sha,'after');need(before['capture_id']!=after['capture_id'])
 b={k:v for k,v in before.items()if k not in('phase','capture_id')};a={k:v for k,v in after.items()if k not in('phase','capture_id')};need(b==a)
 native=envelope['native_receipt'];need(type(native)is dict and native.get('source_restore')==before['restore'])
 # Copy only AFTER bound native SchemaSpec/ledger/namespace/nonce/source/MAC
 # evidence succeeds. The raw original envelope/receipt remain unchanged.
 projected=copy.deepcopy(native);restore=projected['source_restore'];restore['current_table_count']=549;audit=restore['patch_audit'];audit['source_patch_count']=1575;audit['applied_patch_count']=1575;audit['migrations_executed']-=1;del audit['migration_source_hashes'][p['patch_discovery']['full_key']]
 # Only the explicitly enumerated focused profiles project their two sockets.
 # Completion-v3 remains outside that set and is projected by its own consumer.
 # The original strict checker still validates every original cleanup field.
 if expected_mode in CAPTURE_CLEANUP_MODES:
  cleanup=projected.get('cleanup');need(type(cleanup)is dict and set(cleanup)=={'ownedSchemasRemoved','ownedRuntimeUsersRemoved','readerRemoved','ownedAuxiliarySetupConnectionsClosed'})
  need(type(cleanup['ownedAuxiliarySetupConnectionsClosed'])is int and cleanup['ownedAuxiliarySetupConnectionsClosed']==2)
  del cleanup['ownedAuxiliarySetupConnectionsClosed']
 return projected,{'schema':'Task-additive-schema-verified-projection-v1','source_revision':p['source_revision'],'source_root':p['source_root'],'source_pins':p['source_pins'],'private_profile_sha256':expected_profile_sha,'native_profile_mode':p['mode'],'source_derived_expected_counts_only':False,'genuine_bound_capture_pair_verified':True,'native_SchemaSpec_three_tables_verified':True,'Task_OFF_empty_before_and_after_verified':True,'original_current_receipt_modified':False,'exact_projection':{'tables':[552,549],'patches':[1576,1575],'migrations_removed':1,**({'capture_auxiliary_setup_connections':[2,0]}if expected_mode in CAPTURE_CLEANUP_MODES else{})},'legacy_v1_validation_still_required':True,'actual_SQL_executed_by_validator':False,'production_authority':False}
if __name__=='__main__':
 parser=argparse.ArgumentParser();parser.add_argument('--envelope',required=True);parser.add_argument('--profile',required=True);parser.add_argument('--expected-profile-sha256',required=True);a=parser.parse_args()
 try:
  projected,proof=validate_envelope(decode(private(a.envelope)),a.profile,a.expected_profile_sha256);print(json.dumps(proof,sort_keys=True))
 except Exception:print('{"Task_additive_schema_accepted":false,"legacy_v1_validation_still_required":true}');raise SystemExit(1)

"""Fixed disposable MySQL/RR21 execution and source/receipt checks; no arbitrary shell."""
import hashlib,io,os,posixpath,re,stat,subprocess,tarfile,importlib.util,json,sys,types
from pathlib import Path
from provenance import decode,digest
MYSQL_IMAGE='mysql:8.4.13'
MYSQL_PASSWORD='native-rr-ci-disposable-only'
CURRENT_RESTORE_CLI='scripts/governance/check_native_user_rr_mdl_current_restore_v2.py'
CURRENT_RESTORE_ASSETS={
 'resources/sql/autopatches/20261112.task.contentgrants.sql':'632b4412c18ea61787ca6b38afc9d3c16b62f76591e9a99d624401eeac117236',
 'src/infrastructure/governance/content/PhorgeTaskContentGrantSchemaSpec.php':'7cac65fe600e2681eed05618fd584a02f37e27b7f532d6ca2b59244bf5045ccb'}
CURRENT_RESTORE_PATHS=frozenset({CURRENT_RESTORE_CLI,*CURRENT_RESTORE_ASSETS})
# Preserve all280 selected IDs at dd375d2d00, plus these five real current cases.
# Encoding: SHA256(JSON(sorted IDs), separators=(',',':')). No PHP test is skipped.
BASE_PURE_IDS_SHA256='6ba076f1a429860aab2b89c60d436a8bf5b1d4e42db1783d620eb84c243dc6fc'
CURRENT_PURE_ADDITIONS=frozenset({
 'PhorgeGovernanceMediaExchangeServiceTestCase::testIsolationRowsKeepExactNamesAndKnownNativeValues',
 'PhorgeGovernanceMediaFileGuardTestCase::testDisabledFileGuardDeniesBeforeInterpretingRouteOrIdentity',
 'PhorgeGovernanceMediaFileGuardTestCase::testPrivateResponsesDisableCachingAndConstrainActiveContent',
 'PhorgeGovernanceMediaHTTPProtocolTestCase::testDisabledCandidateEndpointsDenyWithoutCreatingAuthority',
 'PhorgeGovernanceMediaHTTPProtocolTestCase::testDisabledHTTPPreservesNativeCredentialsAndOmitsBootstrap'})
RETIRED_PURE_IDS=frozenset(['PhorgeGovernanceCapabilityMapTestCase::testWikiContentRequiresValueAndExpansionReview', 'PhorgeGovernanceNativeObservationTestCase::testCreationWrongEnvelopeAndUnknownBatchBlock', 'PhorgeGovernanceNativeObservationTestCase::testTaskAndWikiPolicyItemsAreOnlyLocalPassThrough', 'PhorgeGovernanceNativeObservationTestCase::testWikiBatchMoveAndDeletePreserveNativeOrder', 'PhorgeGovernanceNativeObservationTestCase::testWikiBatchNativeCanonicalVersionHydration', 'PhorgeGovernanceNativeObservationTestCase::testWikiBatchPreludeUsesAttachedNativeContent', 'PhorgeGovernanceNativeObservationTestCase::testWikiBatchRejectsMissingOrMismatchedContent', 'PhorgeGovernanceNativeObservationTestCase::testWikiEmptyBodyActuallyExpandsToDelete', 'PhorgeGovernanceNativeObservationTestCase::testWikiMoveActuallyAddsDestinationPolicies', 'PhorgeGovernanceNativeObservationTestCase::testWikiMoveCannotInjectAnArbitraryObject', 'PhorgeGovernanceNativeObservationTestCase::testWikiNonemptyBodyDoesNotClaimPublicationValidated', 'PhorgeGovernanceReadSetTestCase::testTaskAndWikiGroupDependenciesAreNotOmitted'])
RETIREMENT_PURE_ADDITIONS=frozenset(['PhorgeGovernanceCapabilityMapTestCase::testRetiredWikiCannotAcquireContentOrManagementCapabilities', 'PhorgeGovernanceNativeObservationTestCase::testTaskPolicyItemsAreOnlyLocalPassThrough', 'PhorgeGovernanceReadSetTestCase::testTaskGroupDependenciesAreNotOmitted'])
DIRECTORY_PURE_ADDITIONS=frozenset(['PhorgeOrganizationRequestTestCase::testBulkSelectionIsDetachedSortedAndDeduplicated', 'PhorgeOrganizationRequestTestCase::testBulkRejectsAuthorityFieldsUnknownSourceAndUnboundedInput', 'PhorgeOrganizationRequestTestCase::testReplacementIsExplicitAndSupportsEmptyDesiredSet', 'PhorgeOrganizationRequestTestCase::testBulkPeriodsAndOperationIDAreStrict', 'PhorgeOrganizationRequestTestCase::testBulkAdmitsTwoHundredDistinctExistingUserIdentifiers', 'PhorgeOrganizationRequestTestCase::testReplacementRetainsLiteralZeroTeamIdentifier'])
def exact(value,wanted):
 if type(value)is not type(wanted):raise ValueError('typed evidence mismatch')
 if type(wanted)is dict:
  if set(value)!=set(wanted):raise ValueError('evidence fields mismatch')
  for k,v in wanted.items():exact(value[k],v)
 elif type(wanted)is list:
  if len(value)!=len(wanted):raise ValueError('evidence length mismatch')
  for a,b in zip(value,wanted):exact(a,b)
 elif value!=wanted:raise ValueError('evidence value mismatch')
def service_identity(rows,container):
 if type(container)is not str or not re.fullmatch('[a-f0-9]{64}',container)or type(rows)is not list or len(rows)!=1:raise ValueError('actual isolated service identity required')
 row=rows[0]
 if type(row)is not dict or any(type(row.get(k))is not dict for k in ('Config','State','HostConfig')):raise ValueError('actual service shape refused')
 if row.get('Id')!=container or row.get('Config',{}).get('Image')!=MYSQL_IMAGE or row.get('State',{}).get('Running')is not True:raise ValueError('actual service not running expected image')
 host=row.get('HostConfig',{})
 if host.get('Privileged')is not False or host.get('PidMode')!=''or host.get('IpcMode')!='private'or host.get('Binds')not in (None,[]):raise ValueError('service isolation profile refused')
 if type(host.get('NetworkMode'))is not str or host['NetworkMode']in ('','host','none'):raise ValueError('isolated service network required')
 exact(host.get('PortBindings'),{'3306/tcp':[{'HostIp':'127.0.0.1','HostPort':'3306'}]})
 if type(row.get('Image'))is not str or not re.fullmatch('sha256:[a-f0-9]{64}',row['Image']):raise ValueError('actual image digest required')
 return {'image':MYSQL_IMAGE,'actual_image_id':row['Image'],'loopback_only':True,'port':3306,'running':True}
def extract_current_archive(archive,root):
 # A narrow Git-archive data extractor: no device/hardlink/sparse/chown paths,
 # no parent aliases, and only direct internal regular-file symlinks.
 with tarfile.open(fileobj=io.BytesIO(archive))as tar:
  rows=tar.getmembers();members={m.name:m for m in rows}
  if len(rows)>20000 or len(rows)!=len(members)or any(type(m.size)is not int or m.size<0 for m in rows)or sum(m.size for m in rows)>268435456:raise ValueError('bounded unique source archive required')
  for m in rows:
   name=m.name
   if not name or posixpath.isabs(name)or posixpath.normpath(name)!=name or '..'in name.split('/')or not(m.isdir()or m.isfile()or m.issym())or m.issparse():raise ValueError('source archive path/type refused')
   parent=posixpath.dirname(name)
   while parent:
    if parent in members and not members[parent].isdir():raise ValueError('source archive parent alias refused')
    parent=posixpath.dirname(parent)
   if m.issym():
    target=posixpath.normpath(posixpath.join(posixpath.dirname(name),m.linkname))
    if posixpath.isabs(m.linkname)or target not in members or not members[target].isfile():raise ValueError('source archive alias refused')
  for m in rows:
   target=root/m.name;target.parent.mkdir(parents=True,exist_ok=True)
   if m.isdir():target.mkdir(exist_ok=True)
   elif m.isfile():
    fd=os.open(target,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    with os.fdopen(fd,'wb')as output:
     raw=tar.extractfile(m).read()
     if len(raw)!=m.size:raise ValueError('source archive bytes incomplete')
     output.write(raw);os.fchmod(output.fileno(),0o755 if m.mode&0o111 else 0o644)
  for m in rows:
   if m.issym():os.symlink(m.linkname,root/m.name)
def source_manifest(archive):
 result={};aliases={}
 with tarfile.open(fileobj=io.BytesIO(archive))as tar:
  members={m.name:m for m in tar.getmembers()}
  if len(members)!=len(tar.getmembers()):raise ValueError('duplicate archive member')
  for name,m in members.items():
   if not(name.endswith(('.php','.sql'))or name.startswith('resources/governance/publicsuffix/')):continue
   target=m
   if m.issym():
    resolved=posixpath.normpath(posixpath.join(posixpath.dirname(name),m.linkname))
    if posixpath.isabs(m.linkname)or resolved=='..'or resolved.startswith('../')or resolved not in members or not members[resolved].isfile():raise ValueError('signed alias is not direct internal regular file')
    aliases[name]=m.linkname;target=members[resolved]
   if target.isfile():result[name]=digest(tar.extractfile(target).read())
 return result,aliases
def whole_runtime(root,expected,aliases):
 actual={}
 for p in root.rglob('*'):
  name=str(p.relative_to(root))
  if not(name.endswith(('.php','.sql'))or name.startswith('resources/governance/publicsuffix/'))or p.is_dir():continue
  if p.is_symlink()and(name not in aliases or os.readlink(p)!=aliases[name]):raise ValueError('unknown runtime source alias')
  if not p.is_file():raise ValueError('unknown runtime source object')
  actual[name]=digest(p.read_bytes())
 exact(actual,expected)
 for path,target in aliases.items():
  p=root/path
  if not p.is_symlink()or os.readlink(p)!=target:raise ValueError('actual signed alias changed')
 return {'passed':True,'expected_files':len(expected),'actual_files':len(actual),'missing':[],'extra':[],'changed':[],'signed_aliases_verified':len(aliases),'actual_sha256':actual,'signed_alias_targets':aliases}
def pure_inventory(root):
 text=(root/'tests/governance/run.php').read_text();block=text.split('$classes = array(',1)[1].split(');',1)[0];classes=re.findall(r"'(Phorge[A-Za-z0-9_]+TestCase)'",block);ids=set()
 if len(classes)!=len(set(classes))or len(classes)!=49:raise ValueError('current pure classes refused')
 for cls in classes:
  paths=list((root/'src').rglob(cls+'.php'))
  if len(paths)!=1:raise ValueError('pure class source ambiguous')
  methods=re.findall(r'public\s+function\s+(test[A-Za-z0-9_]+)\s*\(',paths[0].read_text())
  if len(methods)!=len(set(methods)):raise ValueError('pure method duplicate')
  ids.update(cls+'::'+method for method in methods)
 if len(ids)!=282:raise ValueError('current pure IDs refused')
 if not CURRENT_PURE_ADDITIONS<=ids or not RETIREMENT_PURE_ADDITIONS<=ids or not DIRECTORY_PURE_ADDITIONS<=ids or RETIRED_PURE_IDS&ids or digest(json.dumps(sorted((ids-CURRENT_PURE_ADDITIONS-RETIREMENT_PURE_ADDITIONS-DIRECTORY_PURE_ADDITIONS)|RETIRED_PURE_IDS),separators=(',',':')).encode())!=BASE_PURE_IDS_SHA256:raise ValueError('original280 plus exact current additions required')
 return ids
def validate_pure(data,ids,*,expected_runtime='8.5.13',profile='production'):
 if (profile,expected_runtime)not in {('production','8.5.13'),('php86-isolated-v1','8.6.0')}:raise ValueError('unqualified runtime profile refused')
 if data.get('passed')is not True or data.get('runtime')!=expected_runtime or type(data.get('expected_methods'))is not int or data['expected_methods']!=282:raise ValueError('actual pure outcome refused')
 results=data.get('results')
 if type(results)is not list or len(results)!=282 or len({r['id']for r in results})!=282 or {r['id']for r in results}!=ids or any(r.get('result')!='pass'for r in results):raise ValueError('actual pure IDs refused')
 return {'passed':True,'methods':282,'classes':49,'new_executions':1}
def cleanup_proved(receipt,revision):
 try:
  exact(receipt['cleanup'],{'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True})
  exact(receipt['worker_cleanup'],{'private_directories_removed':0,'owned_processes_stopped':0,'cleanup_failures':0,'private_directories_remaining':0,'live_processes_after_cleanup':0,'workers_registered':0})
  exact(receipt['cleanup_failures'],[])
  native=receipt['native']
  exact(native['profile'],'isolated-same-native-User-writer-RR-MDL-v1');exact(native['source_revision'],revision)
  exact(native['local_children'],2)
  child={'owned_processes_stopped':True,'owned_SQL_sessions_ended':True,'private_directory_removed':True,'cleanup_proved':True,'pidfd_remaining':0}
  exact(native['local_cleanup'],[child,child])
  exact(native['final_owned_cleanup'],{'cleanup_proved':True,'local_children_registered':2,'failures':[],'revoker_closed':True,'principal_cleanup':{'observer_removed':True,'owned_observer_sessions_absent':True,'cleanup_proved':True}})
 except (KeyError,ValueError,TypeError):return False
 return True

def current_restore_read(path):
 if path.resolve(strict=True)!=path:raise ValueError('canonical restore input required')
 fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
 try:
  before=os.fstat(fd)
  if not stat.S_ISREG(before.st_mode)or before.st_nlink!=1 or before.st_uid!=os.getuid()or before.st_mode&0o022 or not 0<before.st_size<=1048576:raise ValueError('bounded regular restore input required')
  raw=bytearray()
  while len(raw)<before.st_size:
   part=os.read(fd,before.st_size-len(raw))
   if not part:raise ValueError('complete restore input required')
   raw.extend(part)
  fields=('st_dev','st_ino','st_mode','st_uid','st_gid','st_nlink','st_size','st_mtime_ns','st_ctime_ns')
  if os.read(fd,1)or any(getattr(before,k)!=getattr(after,k)for after in (os.fstat(fd),path.lstat())for k in fields):raise ValueError('restore input changed')
  return bytes(raw)
 finally:os.close(fd)

def current_restore_inputs(code_root,source_root,revision,read_committed):
 # Separate signed-checkout DATA commitment; never alters the 15-source anchor.
 if type(revision)is not str or not re.fullmatch('[a-f0-9]{40}',revision)or revision=='0'*40:raise ValueError('fixed restore revision required')
 if code_root.resolve(strict=True)!=code_root or not source_root.is_absolute()or source_root.resolve()!=source_root or source_root==code_root or source_root.is_relative_to(code_root):raise ValueError('distinct fixed code and staged roots required')
 files={}
 for name in sorted(CURRENT_RESTORE_PATHS):
  raw=current_restore_read(code_root/name)
  if raw!=read_committed(name):raise ValueError('restore input differs from exact committed source')
  pin=digest(raw)
  if name in CURRENT_RESTORE_ASSETS and pin!=CURRENT_RESTORE_ASSETS[name]:raise ValueError('unreviewed restore asset')
  files[name]={'bytes':len(raw),'sha256':pin}
 return {'schema':'native-RR-current-restore-inputs-DATA-v2','source_revision':revision,
  'code_root':str(code_root),'source_root':str(source_root),'files':files,'runtime_authority':False}

def verify_current_restore_inputs(source_root,revision,commitment,*,code_root,read_committed):
 if type(commitment)is not dict or set(commitment)!={'schema','source_revision','code_root','source_root','files','runtime_authority'}:raise ValueError('closed restore commitment required')
 if commitment['schema']!='native-RR-current-restore-inputs-DATA-v2'or commitment['runtime_authority']is not False or commitment['source_revision']!=revision or type(revision)is not str or not re.fullmatch('[a-f0-9]{40}',revision)or revision=='0'*40:raise ValueError('same restore revision required')
 if code_root.resolve(strict=True)!=code_root or str(code_root)!=commitment['code_root']or source_root.resolve(strict=True)!=source_root or str(source_root)!=commitment['source_root']or source_root==code_root or source_root.is_relative_to(code_root)or (source_root/'.git').exists()or (source_root/'.git').is_symlink():raise ValueError('fixed Git checkout and Gitfree staged source required')
 if type(commitment['files'])is not dict or set(commitment['files'])!=CURRENT_RESTORE_PATHS:raise ValueError('closed restore input paths required')
 for name,row in commitment['files'].items():
  if type(row)is not dict or set(row)!={'bytes','sha256'}or type(row['bytes'])is not int or not 0<row['bytes']<=1048576 or type(row['sha256'])is not str or not re.fullmatch('[a-f0-9]{64}',row['sha256']):raise ValueError('typed restore input pin required')
  raw=current_restore_read(source_root/name);code=current_restore_read(code_root/name)
  if raw!=code or code!=read_committed(name)or (len(raw),digest(raw))!=(row['bytes'],row['sha256'])or name in CURRENT_RESTORE_ASSETS and row['sha256']!=CURRENT_RESTORE_ASSETS[name]:raise ValueError('committed/worktree/staged restore input changed')

def strict_accept_current_restore_v2(root,receipt,revision,receipt_path,commitment,*,code_root,read_committed):
 verify_current_restore_inputs(root,revision,commitment,code_root=code_root,read_committed=read_committed)  # Before importing new CLI code.
 receipt_raw=current_restore_read(receipt_path);exact(decode(receipt_raw),receipt)
 scripts=root/'scripts/governance';sys.path.insert(0,str(scripts))
 sealed=current_restore_read(root/CURRENT_RESTORE_CLI)
 exact({'bytes':len(sealed),'sha256':digest(sealed)},commitment['files'][CURRENT_RESTORE_CLI])
 gate=types.ModuleType('actual_current_rr_restore_metadata_v2');gate.__file__=str(root/CURRENT_RESTORE_CLI)
 exec(compile(sealed,gate.__file__,'exec'),gate.__dict__)  # Verified CURRENT bytes, never cached bytecode.
 expected=gate.validate(receipt,root=root,source_revision=revision)
 required={'schema':'native-user-RR-current-restore-metadata-v2','profile':'RR-current-restore-metadata-v2','scope':'same-native-User-writer-RR-MDL-current-restore-metadata-only','passed':True,'source_revision':revision,'checks':21,'SQL_obligations':12,'native_children':2,'current_restore_metadata_verified':True,'legacy_reference_validated':True,'legacy_reference_only':True,'raw_v1_accepted':False,'raw_v1_profile_qualified':False,'raw_receipt_modified':False,'Task_physical_schema_verified':False,'Task_empty_tables_verified':False,'Task_config_epoch_verified':False,'independent_Task_schema_before_after_verified':False,'SQL_executed_by_validator':False,'source_owner_issued':False,'production_authority':False}
 if type(expected)is not dict or set(expected)!=set(required)|{'legacy_reference'}:raise ValueError('closed explicit restore metadata v2 scope required')
 exact({k:expected[k]for k in required},required)
 verify_current_restore_inputs(root,revision,commitment,code_root=code_root,read_committed=read_committed)  # Immediately before subprocess CLI.
 if current_restore_read(receipt_path)!=receipt_raw:raise ValueError('raw RR receipt changed')
 cli=subprocess.run([sys.executable,'-B',str(root/CURRENT_RESTORE_CLI),'--receipt',str(receipt_path),'--source-revision',revision,'--source-root',str(root)],capture_output=True)
 verify_current_restore_inputs(root,revision,commitment,code_root=code_root,read_committed=read_committed)  # After CLI; no Python-file gap.
 if current_restore_read(receipt_path)!=receipt_raw:raise ValueError('raw RR receipt changed')
 if cli.returncode!=0 or cli.stderr!=b''or decode(cli.stdout)!=expected:raise ValueError('actual current restore CLI/inprocess mismatch')
 return expected,cli.stdout

def restore_comparison_DATA(receipt,patches):
 # Fixed old-gate comparison only, never an acceptance or root-cause verdict.
 if type(patches)not in(tuple,list)or len(patches)!=38 or any(type(p)is not str for p in patches):raise ValueError('fixed diagnostic migration profile required')
 restore=receipt.get('source_restore')if type(receipt)is dict else None
 audit=restore.get('patch_audit')if type(restore)is dict else None
 hashes=audit.get('migration_source_hashes')if type(audit)is dict else None
 def keys_equal(actual,wanted):
  return type(actual)is dict and all(type(k)is str for k in actual)and set(actual)==wanted
 fields={}
 for prefix,actual,expected in (
  ('',restore,{'app_schema_count':54,'owned_schema_count':55,'quickstart_source_table_count':500,'current_table_count':549,'restore_phase_workers_started':0,'restore_phase_network_endpoints_configured':0}),
  ('patch_audit.',audit,{'source_patch_count':1575,'applied_patch_count':1575,'migrations_executed':38,'current_patch_ledger_complete':True})):
  for name,wanted in expected.items():
   observed=actual.get(name)if type(actual)is dict else None
   valid=type(observed)is type(wanted)and(type(observed)is bool or -(2**63)<=observed<2**63)
   fields[prefix+name]={'expected':wanted,'observed':observed if valid else None,'type_valid':valid,'equal':valid and observed==wanted}
 return {'schema':'native-RR-source-restore-comparison-DATA-v1','comparison_DATA_only':True,
  'qualification':False,'root_cause_proved':False,'fields':fields,
  'keyset_equal':{
   'source_restore':keys_equal(restore,{'quickstart_sha256','app_schema_count','owned_schema_count','quickstart_source_table_count','current_table_count','restore_phase_workers_started','restore_phase_network_endpoints_configured','patch_audit'}),
   'patch_audit':keys_equal(audit,{'source_patch_count','applied_patch_count','pending_patch_keys','current_patch_ledger_complete','migration_source_hashes','migrations_executed'}),
   'migration_source_hashes':keys_equal(hashes,{'phabricator:'+p for p in patches})}}

def strict_accept(root,receipt,revision,receipt_path):
 scripts=root/'scripts/governance';sys.path.insert(0,str(scripts));spec=importlib.util.spec_from_file_location('actual_portable_rr21_gate',scripts/'check_native_user_rr_mdl.py');gate=importlib.util.module_from_spec(spec);spec.loader.exec_module(gate)
 try:
  expected=gate.validate(receipt,root=root,source_revision=revision)
  cli=subprocess.run([sys.executable,'-B',str(scripts/'check_native_user_rr_mdl.py'),'--receipt',str(receipt_path),'--source-revision',revision],capture_output=True)
  if cli.returncode!=0 or cli.stderr!=b''or decode(cli.stdout)!=expected:raise ValueError('actual CLI/inprocess strict acceptance mismatch')
  return expected,cli.stdout
 except Exception:
  try:
   comparison=restore_comparison_DATA(receipt,gate.completion.PATCHES)
   safe=json.dumps(comparison,sort_keys=True,separators=(',',':'),allow_nan=False)+'\n'
   if len(safe.encode())>4096:raise ValueError('bounded comparison required')
   sys.stderr.write(safe);sys.stderr.flush()
  except Exception:pass  # Diagnostic failure cannot replace the original strict failure.
  raise

"""Strict source-bound native File/Task endpoint evidence; never a restore permit."""
import argparse,hashlib,json,os,re,stat
from pathlib import Path
import check_completion as completion
import check_native_organization as native
import check_recovery_partial_composition as shared
import recovery_conduit_source_delta as native_driver_view
ROOT=Path(__file__).resolve().parents[2]
SQL={'file-parent-policy-unattached-author-root-is-unsupported',
 'file-parent-policy-native-Task-attachment-changes-endpoint-binding',
 'file-parent-policy-native-endpoints-never-become-restoration-permit',
 'file-parent-policy-native-invisible-parent-policy-changes-binding',
 'file-parent-policy-current-account-flags-admission-bound',
 'file-parent-policy-anonymous-refused-before-native-author-grant'}
# Reviewed selected producer/consumer bytes, not receipt-supplied source proofs.
SOURCE_INPUTS={'src/applications/people/storage/PhabricatorUser.php': '1b402225b9ecd675cbe752f7b2d1de9bd05807583bcbc15c1ee58bce1e6822eb', 'src/infrastructure/governance/recovery/PhorgeRecoveryFileSourceFacts.php': '751adef2e7a90220fceac58cb2894edf442fa01d3e5fbdb7b600f561f749c723', 'src/infrastructure/governance/recovery/PhorgeRecoveryOwnedRRScope.php': 'c1f32b0b3d556a7a06f2273824d1ea528211ed76c6ab3c34436c0ed5ee5c8a08', 'src/applications/files/query/PhabricatorFileQuery.php': '2ccf285287e7f7e1f5889afce530b1a5073cfa7c076100a1129506816619e421', 'src/applications/maniphest/query/ManiphestTaskQuery.php': '225b35fe0fa0154e444530e35ca83a5525d2f2a36965891e0fa1834931b772a5', 'src/applications/policy/rule/PhorgeGlobalPolicyRule.php': '7f0f1188915b7837fd1e52fecf87b4aae7249c44f3795c8cae6cc940fbd817e2', 'support/governance/recovery/file-parent-policy-cases.php': '0d1ca152b7979199a3cedcbfe9290db0350bb41d9bdc0b215b2f28fdd01ef891', 'support/governance/recovery/file-parent-policy-focused.php': 'fc3357119c2e65c9d6d34298578bd8d3688b2ff2f40e8209b034bfba3e92ac45', 'support/governance/recovery/file-source-cases.php': 'd106bc50447c9a6a62c1d115840d7272ee305d98b56d42f2fef0fd39558b2c10', 'support/governance/recovery/task-owner-source-cases.php': '56ccc81c0b5dcb664484862f93bc8e14df4dcd9a10db27de3f20469a2355d7a1', 'support/governance/completion/pipeline-gorge.php': 'ff27390d7a7e5b5c14555758e8b8172fd401bb0dcf51221fe32251b11308fcda', 'support/governance/native-organization/test-native-organization-mysql.php': '12f3da8ac1ff4f0eda73200a28247add24066378c9295cb10f23339d6bf589c7'}
RELATIONSHIPS={'owned_User_RR_scope_observed':True,'same_server_observed':True,
 'File_reader_is_User_writer':False,'Task_reader_is_User_writer':False,
 'File_Task_same_physical_reader':False,'same_snapshot_proved':False}
def read_source_checked(path):
 path=Path(path);parents=[(p,os.lstat(p))for p in path.parents]
 if any(not stat.S_ISDIR(v.st_mode)or stat.S_ISLNK(v.st_mode)for _,v in parents):raise ValueError('source parent alias refused')
 stamp=lambda x:(x.st_dev,x.st_ino,x.st_uid,x.st_gid,x.st_mode,x.st_nlink,x.st_size,x.st_mtime_ns,x.st_ctime_ns)
 fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
 try:
  old=os.fstat(fd)
  if not stat.S_ISREG(old.st_mode)or old.st_uid!=os.getuid()or old.st_nlink!=1 or old.st_mode&0o022 or not 0<old.st_size<=1048576:raise ValueError('bounded owned source required')
  body=bytearray();remaining=old.st_size
  while remaining:
   chunk=os.read(fd,min(65536,remaining))
   if not chunk:raise ValueError('source short read')
   body.extend(chunk);remaining-=len(chunk)
  if stamp(old)!=stamp(os.fstat(fd))or stamp(old)!=stamp(os.lstat(path)):raise ValueError('source changed during capture')
  for p,was in parents:
   now=os.lstat(p)
   if (was.st_dev,was.st_ino,was.st_uid,was.st_gid,was.st_mode)!=(now.st_dev,now.st_ino,now.st_uid,now.st_gid,now.st_mode):raise ValueError('source parent changed')
  return bytes(body)
 finally:os.close(fd)
def read_native_driver_checked(path):
 path=Path(path);parents=[(p,os.lstat(p))for p in path.parents]
 if any(not stat.S_ISDIR(v.st_mode)or stat.S_ISLNK(v.st_mode)for _,v in parents):raise ValueError('source parent alias refused')
 stamp=lambda x:(x.st_dev,x.st_ino,x.st_uid,x.st_gid,x.st_mode,x.st_nlink,x.st_size,x.st_mtime_ns,x.st_ctime_ns)
 fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
 try:
  old=os.fstat(fd)
  if not stat.S_ISREG(old.st_mode)or stat.S_IMODE(old.st_mode)!=0o644 or old.st_uid!=os.getuid()or old.st_nlink!=1 or old.st_mode&0o022 or not 0<old.st_size<=1048576:raise ValueError('bounded owned 0644 native driver required')
  body=bytearray();remaining=old.st_size
  while remaining:
   chunk=os.read(fd,min(65536,remaining))
   if not chunk:raise ValueError('source short read')
   body.extend(chunk);remaining-=len(chunk)
  if stamp(old)!=stamp(os.fstat(fd))or stamp(old)!=stamp(os.lstat(path)):raise ValueError('source changed during capture')
  for p,was in parents:
   now=os.lstat(p)
   if (was.st_dev,was.st_ino,was.st_uid,was.st_gid,was.st_mode)!=(now.st_dev,now.st_ino,now.st_uid,now.st_gid,now.st_mode):raise ValueError('source parent changed')
  return bytes(body)
 finally:os.close(fd)
# Immutable whole PHP fixture at actual20b, not executable replacement code.
NATIVE_DRIVER_PATH='support/governance/native-organization/test-native-organization-mysql.php'
NATIVE_DRIVER_TARGET_PIN={'bytes':39675,'sha256':'12f3da8ac1ff4f0eda73200a28247add24066378c9295cb10f23339d6bf589c7'}
NATIVE_DRIVER_TARGET_ORIGIN={'revision':'20b138b4cd9a2cf61522613b4a8e5e21eca7341b','tree':'74ca6114980547fceb27aedf3a2464a4518fcc91','git_mode':'100644','git_blob':'7495c6bd85911fcee14ae0beed8d841d25676c4c'}
def _native_driver_prior(raw):
 if type(raw)is not bytes or len(raw)!=NATIVE_DRIVER_TARGET_PIN['bytes']or hashlib.sha256(raw).hexdigest()!=NATIVE_DRIVER_TARGET_PIN['sha256']:
  raise ValueError('unknown whole native driver source phase')
 return raw

def admitted_native_driver_source(root,raw):
 """Current physical/R/metadata role first, then exact old PHP source only.

 No historical fixture is executed, and this source view grants no runtime,
 identity, object policy, restoration or optional-feature authority.
 """
 import project_description_admission_delta as current
 try:
  root=Path(root)
  if not root.is_absolute()or root.resolve()!=root:raise ValueError('native driver source root refused')
  current.assert_current_recipe(root)
  target=root/NATIVE_DRIVER_PATH
  if type(raw)is not bytes or raw!=read_native_driver_checked(target):
   raise ValueError('unknown physical native driver source')
  registry_path='support/governance/source-operation/registry-v1.json'
  registry_raw=current.description.checked_bytes(root/registry_path)
  if registry_path not in current.CURRENT_METADATA:raise ValueError('native driver registry role missing')
  current._exact(registry_raw,current.CURRENT_METADATA[registry_path])
  def pairs(items):
   result={}
   for key,value in items:
    if key in result:raise ValueError('duplicate native driver source role')
    result[key]=value
   return result
  registry=json.loads(registry_raw,object_pairs_hook=pairs)
  if type(registry)is not dict or registry.get('schema')!='source-operation-registry-v1'or registry.get('source_revision')!=NATIVE_DRIVER_TARGET_ORIGIN['revision']or registry.get('source_tree')!=NATIVE_DRIVER_TARGET_ORIGIN['tree']or type(registry.get('entries'))is not dict or type(registry.get('successor_entries'))is not dict:
   raise ValueError('native driver registry origin refused')
  pin=registry['successor_entries'][NATIVE_DRIVER_PATH]['current']if NATIVE_DRIVER_PATH in registry['successor_entries']else registry['entries'][NATIVE_DRIVER_PATH]
  if type(pin)is not dict or set(pin)!={'bytes','sha256'}or type(pin['bytes'])is not int or type(pin['sha256'])is not str:
   raise ValueError('native driver source role refused')
  current._exact(raw,pin)
  if hashlib.sha256(raw).hexdigest()==NATIVE_DRIVER_TARGET_PIN['sha256']:
   prior=_native_driver_prior(raw)
  else:
   row=current.CURRENT_METADATA.get(NATIVE_DRIVER_PATH)
   if type(row)is not dict or row.get('target_bytes')!=NATIVE_DRIVER_TARGET_PIN['bytes']or row.get('target_sha256')!=NATIVE_DRIVER_TARGET_PIN['sha256']:
    raise ValueError('native driver current metadata role missing')
   current._exact(raw,row)
   prior=_native_driver_prior(current._normalize_metadata(NATIVE_DRIVER_PATH,raw))
  current.assert_current_recipe(root)
  return prior
 except OSError:
  raise ValueError('native driver source unavailable')from None

def source_obligations(root=ROOT):
 root=Path(root)
 current_root=root==ROOT
 if current_root:
  import project_description_admission_delta as current
  current.assert_current_recipe(root)
 captured={}
 if len(SOURCE_INPUTS)!=12:raise ValueError('reviewed source set refused')
 for relative,pin in SOURCE_INPUTS.items():
  raw=read_source_checked(Path(root)/relative)
  if relative==NATIVE_DRIVER_PATH:
   raw=admitted_native_driver_source(root,raw)if current_root or hashlib.sha256(raw).hexdigest()!=SOURCE_INPUTS[relative]else raw
   raw=native_driver_view.reduce_policy_cleanup_driver(raw)
  if hashlib.sha256(raw).hexdigest()!=pin:raise ValueError('reviewed Fileparent source bytes changed')
  captured[relative]=raw
 text=captured['support/governance/recovery/file-parent-policy-cases.php'].decode('utf-8')
 labels=re.findall(r"'(file-parent-policy-[A-Za-z0-9-]+)'\);",text)
 if len(labels)!=len(set(labels))or set(labels)!=SQL:raise ValueError('actual Fileparent case obligations changed')
 if current_root:current.assert_current_recipe(root)
 return shared.BOOTSTRAP|shared.PREPARATION|set(labels)|shared.CLEANUP

def summary(revision,root=ROOT):
 # Source obligations retain historical pins; PHP wire receipts hash raw bytes.
 source_obligations(root)
 driver='support/governance/native-organization/test-native-organization-mysql.php'
 raw=read_source_checked(Path(root)/driver)
 prior=admitted_native_driver_source(Path(root),raw)if Path(root)==ROOT or hashlib.sha256(raw).hexdigest()!=SOURCE_INPUTS[driver]else raw
 prior=native_driver_view.reduce_policy_cleanup_driver(prior)
 if hashlib.sha256(prior).hexdigest()!=SOURCE_INPUTS[driver]:raise ValueError('Fileparent wire source phase refused')
 wire_inputs=dict(SOURCE_INPUTS);wire_inputs[driver]=hashlib.sha256(raw).hexdigest()
 source_obligations(root)
 return {'profile':'native-File-parent-Task-policy-endpoints-v2-current-account-flags','source_revision':revision,
  'executed_case_obligations':sorted(SQL),'source_inputs_sha256':wire_inputs,
  'native_reader_relationships':RELATIONSHIPS,'observer_closed_and_absent':True,
  'native_File_and_Task_queries_executed':True,'actual_current_account_admission_checked':True,
  'current_account_flags_bound':['isAdmin','isApproved','isDisabled','isSystemAgent','isMailingList'],
  'parent_policy_interpreter_complete':False,'durable_review_compatible':False,
  'dependency_budget_proved':False,'all_object_classes_covered':False,
  'all_sources_complete':False,'complete':False,'restoration_authorized':False,'execution_blocked':True}
def validate(data,root=ROOT,*,source_revision,file_digest):
 if type(source_revision)is not str or not re.fullmatch('[a-f0-9]{40}',source_revision):raise ValueError('source revision required')
 obligations=source_obligations(root);count=len(obligations)
 completion.fields(data,native.FIELDS,'Fileparent receipt shape refused')
 def exact(a,b):completion.exact(a,b,'Fileparent evidence refused')
 for k,v in {'batch':'native-File-parent-policy-endpoints','scope':'owned-native-File-parent-policy-endpoints-no-permit',
  'passed':True,'php_version':'8.5.12','check_count':count,'failure':None,'cleanup_failures':[],
  'optional_source_reader_adapter_exercised':True,'isolated_optional_features_enabled':True,
  'successful_owned_file_service_startups':1,'terminal_failure_cleanup_rollbacks':0,
  'production_deployment':False,'production_executor':False,'production_optional_features_enabled':False,
  'native_permission_axes_changed':False}.items():exact(data[k],v)
 checks=data['checks']
 if type(checks)is not list or len(checks)!=count or any(type(x)is not str for x in checks)or len(set(checks))!=count or set(checks)!=obligations:raise ValueError('Fileparent obligations refused')
 service=data['file_service_cleanup'];completion.fields(service,{'kind','binary_sha256','process_stopped','private_storage_removed'},'Fileparent file cleanup shape refused')
 if type(file_digest)is not str or not re.fullmatch('[a-f0-9]{64}',file_digest):raise ValueError('independent paired binary pin required')
 for k,v in {'kind':'file','binary_sha256':file_digest,'process_stopped':True,'private_storage_removed':True}.items():exact(service[k],v)
 exact(data['native'],summary(source_revision,root=root))
 exact(data['worker_cleanup'],{'private_directories_removed':0,'owned_processes_stopped':0,'cleanup_failures':0,'private_directories_remaining':0,'live_processes_after_cleanup':0,'workers_registered':0})
 exact(data['cleanup'],{'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True})
 native.validate_restore(data['source_restore'],root);exact(data['remaining_blocked'],native.BLOCKERS)
 if type(data['captured_native_output_bytes'])is not int or not 0<=data['captured_native_output_bytes']<=65536 or not completion.digest(data['captured_native_output_sha256']):raise ValueError('Fileparent output refused')
 return {'passed':True,'scope':'selected-File-Task-policy-endpoints-no-permit','source_revision':source_revision,
  'checks':count,'SQL_obligations':len(SQL),'source_inputs':len(SOURCE_INPUTS),
  'same_snapshot_proved':False,'durable_review_compatible':False,'all_sources_complete':False,
  'restoration_authorized':False,'execution_blocked':True}
def main():
 p=argparse.ArgumentParser();p.add_argument('--receipt',type=Path,required=True);p.add_argument('--source-revision',required=True)
 p.add_argument('--file-binary',type=Path,required=True);p.add_argument('--file-binary-sha256',required=True);a=p.parse_args()
 if not re.fullmatch('[a-f0-9]{64}',a.file_binary_sha256)or shared.read_checked(a.file_binary,binary=True)!=a.file_binary_sha256:raise ValueError('independent binary bytes/pin mismatch')
 def nonfinite(_):raise ValueError('nonfinite Fileparent evidence refused')
 data=json.loads(shared.read_checked(a.receipt),object_pairs_hook=completion.strict_object,parse_constant=nonfinite)
 print(json.dumps(validate(data,source_revision=a.source_revision,file_digest=a.file_binary_sha256),sort_keys=True))
if __name__=='__main__':main()

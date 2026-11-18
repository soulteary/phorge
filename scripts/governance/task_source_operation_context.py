"""Root-signed actual Task source stage for the real issued SourceOperation.
No arbitrary reader callbacks, executable historical modules, DB or runtime grant.
"""
import base64,hashlib,json,os,re,stat,subprocess,zlib
from pathlib import Path
from types import MappingProxyType
ROOT_SIGNER='CD85ED7465AFF2FFA2FFB686DBCDD8CBF440F8DE'
BASE='356878fb64e33f62967e36eeff926abba285ff25'
BASELINE='support/governance/source-operation/task-parent5903.json'
BASELINE_SHA='e9b0860ac3d4e8fb835b18fdee07e60b20b373713a11ddfb2ac1d5a24f2d7ac0'
INVERSE='support/governance/source-operation/task-api-parent-inverse.json'
INVERSE_SHA='4af284a3da68786d2508826f69aeb50d85b7080ca7af81073b34e657473440ce'
TASK_NEW_PINS={'resources/sql/autopatches/20261112.task.contentgrants.sql': {'bytes': 1553, 'sha256': '632b4412c18ea61787ca6b38afc9d3c16b62f76591e9a99d624401eeac117236'}, 'scripts/governance/task_content_grant_source_delta.py': {'bytes': 13894, 'sha256': '25ff589d4e9a78127066ceee80ccf12830513cc96e445a2a9d7270f5e980f0b6'}, 'src/infrastructure/governance/content/PhorgeTaskContentApprovalBinding.php': {'bytes': 1017, 'sha256': 'fb5c45796754222fb0e0c608ae16ac7c3de29ee463ddc149025b7f8e910c0e8e'}, 'src/infrastructure/governance/content/PhorgeTaskContentGrantCommitProof.php': {'bytes': 1870, 'sha256': '47fa65e73361a8a17f1f428ffdc3fa402d9263a290a17c14b99902f381f3fc48'}, 'src/infrastructure/governance/content/PhorgeTaskContentGrantConfigOptions.php': {'bytes': 867, 'sha256': 'c55503b9deb2cd2077a9dcf7b08c1dea881de97c880c33c369643363f9d85e94'}, 'src/infrastructure/governance/content/PhorgeTaskContentGrantConfiguration.php': {'bytes': 794, 'sha256': '6bd5868f4b86d5918b000adea0af1447d641018aed6ad036b5a444d493c6b1d4'}, 'src/infrastructure/governance/content/PhorgeTaskContentGrantPlan.php': {'bytes': 3687, 'sha256': '5274f30a211ab36ea59a2c2c0897792c61b3e2551606df11f8a2e94df83ce158'}, 'src/infrastructure/governance/content/PhorgeTaskContentGrantRequest.php': {'bytes': 2619, 'sha256': 'b526bfc0665178a16e15bfbca09206c89b6f5a4f67041713aa8a5caf02b5b480'}, 'src/infrastructure/governance/content/PhorgeTaskContentGrantSchemaSpec.php': {'bytes': 1782, 'sha256': '7cac65fe600e2681eed05618fd584a02f37e27b7f532d6ca2b59244bf5045ccb'}, 'src/infrastructure/governance/content/PhorgeTaskContentGrantService.php': {'bytes': 1807, 'sha256': '45b2f14300ae6b308ee33ec088aa732e14fe9396fbf8f4bf4dd7e1fafd116eef'}, 'src/infrastructure/governance/content/PhorgeTaskContentGrantStore.php': {'bytes': 10327, 'sha256': '07a1d9533c5b0bc23bb0cd147c57706b3e0863fe47ecc647628fe965e1d8d85d'}, 'src/infrastructure/governance/content/PhorgeTaskContentGrantValue.php': {'bytes': 1845, 'sha256': '4b9fb818abd4126dbd184f2876606fef5751c916304434cdcebf6616ca180cb6'}, 'src/infrastructure/governance/content/PhorgeTaskContentGrantWriteScope.php': {'bytes': 29444, 'sha256': 'da48c7aa59cc9d1c2be7df2ac39230a6757e992ee8956b781c3e245f6a294374'}, 'src/infrastructure/governance/content/PhorgeTaskContentSource.php': {'bytes': 5155, 'sha256': 'd71672d278a8a3ac2d2a5f3e0f13ea44d49e4c7daffc6106125a0c846298509d'}, 'src/infrastructure/governance/content/README.task-durable-grants.md': {'bytes': 4184, 'sha256': '6fc6078b52c4d4115d7f96a329afd6039026913c6171140842205e09649783c2'}, 'src/infrastructure/governance/content/task-grant-migration-source.json': {'bytes': 541, 'sha256': '675eb678ae558bbaf0c2d9707bd7ffcc9753c4dd4ee650636b05576b3347d02d'}, 'src/infrastructure/governance/execution/PhorgeTaskGrantExecutionConfigSource.php': {'bytes': 10783, 'sha256': '8301a70ee12b6a2707d624806983ba24ede834f9881869fd656f772056f13f7f'}, 'support/governance/task-content-grants-qualification/README.md': {'bytes': 5886, 'sha256': '26fe1bec2fc31b8cbe81bdd6852f61fbeba697fe6643cb102793316c91fad198'}, 'support/governance/task-content-grants-qualification/native.php': {'bytes': 17537, 'sha256': 'c36d890bba1b192955b154620c3723394547146d47be8942d7ecc4640cd52e66'}, 'support/governance/task-content-grants-qualification/qualify.py': {'bytes': 8475, 'sha256': '322e4471a14f6b80d26bf461f8e81e5a470f988e07631b8db76362fa4428d384'}, 'support/governance/task-content-grants-qualification/source_gate.py': {'bytes': 6985, 'sha256': '6ceeb0d88e5cbf999e500ff8c8c3d0fb79e3539f06a8af2b3197c49025263476'}, 'tests/governance/test_task_current_source_admission.py': {'bytes': 17736, 'sha256': '2de9e287431540fcf375b9a900a152e3d8bfa9c75738cfc74bf68d3204238154'}, 'tests/governance/test_task_durable_grant_lifecycle.py': {'bytes': 10029, 'sha256': '2fd99c724f3a5d5d5032a9fcd4562edb683c78661a7c7dd27d7409209c0755d1'}, 'tests/governance/test_task_durable_grant_source.py': {'bytes': 11978, 'sha256': 'bafa91f4dbd2ad1d20b18ac08deef409880b0ecffc5089434f374846cdde7b4f'}}
V2_PATHS={'scripts/governance/check_directory_transports_task_source_v2.py','scripts/governance/check_media_freshness_task_source_v2.py','tests/governance/test_task_source_consumers_v2.py'}
CI_INVERSE='support/governance/source-operation/task-ci-modified-parent-v1.json'
CI_INVERSE_SHA='d71e48e70f3f23755c28b9f16cf247fa5dace3cab6c09487c4a0705317cfeb51'
CI_V2_NEW_PATHS={'support/governance/task-additive-schema-v2/producer-roles-v2.json', 'support/governance/task-additive-schema-v2/producer-prefix.php', 'scripts/governance/run_task_directory_native_v2.py', 'scripts/governance/oauth_access_task_strict_body_v2.py', 'tests/governance/test_task_native_role_adapter_v2.py', 'tests/governance/test_paired_source_facts_task_strict_body_v2.py', 'tests/governance/test_project_description_current_consumers_task_v2.py', 'tests/governance/task_paired_workflow_shell_test_api_v2.py', 'support/governance/task-additive-schema-v2/oauth-reviewed-current-body-pins.json', 'scripts/governance/conduit_task_strict_body_v2.py', 'scripts/governance/probe_conduit_task_mysql84.php', 'tests/governance/task_ci_workflow_test_api_v2.py', 'support/governance/task-additive-schema-v2/bind.py', 'tests/governance/test_oauth_access_receipt_rejection_v3.py', 'scripts/governance/check_oauth_code_task_schema_receipt_v2.py', 'tests/governance/test_oauth_access_stage_diagnostic_v3.py', 'scripts/governance/oauth_code_task_strict_body_v2.py', 'tests/governance/task_partial_schema_test_api_v2.py', 'support/governance/source-operation/ci-source-verifier.asc', 'scripts/governance/check_directory_task_schema_receipt_v4.py', 'scripts/governance/check_oauth_task_schema_receipt_v2.py', 'support/governance/task-additive-schema-v2/capture.php', 'scripts/governance/check_directory_receipt_task_v2.py', 'scripts/governance/task_ci_native_source_v2.py', 'tests/governance/test_task_additive_schema_v2.py', 'scripts/governance/paired_source_facts_task_strict_body_v2.py', 'scripts/governance/prepare_task_directory_run_v2.py', 'scripts/governance/source_facts_task_strict_body_v2.py', 'support/governance/task-additive-schema-v2/producer_adapter.py', 'tests/governance/test_project_description_admission_task_v2.py', 'support/governance/project-description-ci/task-metadata-lock-v2.json', 'scripts/governance/probe_oauth_access_task_mysql84.php', 'support/governance/task-additive-schema-v2/run.php', 'scripts/governance/check_paired_source_facts_task_schema_receipt_v2.py', 'tests/governance/task_inverse_test_api_v2.py', 'tests/governance/test_task_ci_native_adopter_v2.py', 'scripts/governance/check_conduit_task_schema_receipt_v2.py', 'scripts/governance/oauth_task_strict_body_v2.py', 'tests/governance/task_admission_test_api_v2.py', 'tests/governance/recovery_oauth_code_task_test_cohort_v2.py', 'tests/governance/test_source_facts_task_strict_body_v2.py', 'scripts/governance/check_source_facts_task_schema_receipt_v2.py', 'scripts/governance/check_assessment_task_schema_receipt_v2.py', 'support/governance/task-additive-schema-v2/validate.py', 'scripts/governance/check_oauth_access_task_schema_receipt_v2.py', 'scripts/governance/check_task_ci_head_v1.py', 'support/governance/source-operation/task-ci-modified-parent-v1.json', 'tests/governance/task_description_consumer_test_api_v2.py', 'tests/governance/run_description_task_v2_controls.py', 'scripts/governance/probe_oauth_code_task_mysql84.php', 'scripts/governance/probe_assessment_task_mysql84.php', 'tests/governance/test_project_description_source_inverse_task_v2.py', 'scripts/governance/assessment_task_strict_body_v2.py', 'tests/governance/test_task_directory_run_binding_v2.py', 'tests/governance/test_project_description_main_task_v2.py'}
CI_V2_NEW_PATHS.update({'scripts/governance/probe_full_native_task_mysql84.php', 'scripts/governance/full_native_task_strict_body_v2.py', 'scripts/governance/check_full_native_task_schema_receipt_v2.py'})
CI_V2_NEW_PATHS.add('tests/governance/task_current_phase_shell_test_v2.py')
CI_V2_NEW_PATHS.update({'tests/governance/ci_test_partition.py','tests/governance/test_ci_test_partition.py','tests/atomic/README.ci-cost.md'})
CI_V2_NEW_PATHS.add('tests/governance/task_atomic_access_shell_test_v2.py')
CI_V2_NEW_PATHS.update({'scripts/governance/check_native_directory_task_source_v2.py','tests/governance/test_native_directory_task_static_v2.py'})
TASK_PREUPGRADE_FIXTURE='support/governance/completion/fixture.php'
TASK_PREUPGRADE_RECIPE='support/governance/source-operation/task-preupgrade-fixture-inverse-v1.json'
TASK_PREUPGRADE_RECIPE_PIN={'bytes': 10929, 'sha256': '9c67f64a4dd2268cc554267a618c1078cd967e45ba978d6bfb9098eba92cb6db'}
TASK_PREUPGRADE_CURRENT_PIN={'bytes': 37961, 'sha256': '5fcb8e63a287880f32d0ea71a744a6e63bef104469086b5441be5d16cc6353e6'}
TASK_PREUPGRADE_PARENT_PIN={'bytes': 27902, 'sha256': '00c79b823ae5d92ae6a365f7f13de97243981d9efd5cefa827ca0ca70e1cee93'}
CI_V2_NEW_PATHS.update({TASK_PREUPGRADE_RECIPE,'tests/governance/test_task_preupgrade_fixture_v1.py'})
CI_V2_NEW_PATHS.update({'tests/governance/task_historical_views_test_api_v2.py', 'tests/governance/task_oauth_coordination_test_api_v2.py'})
CI_V2_NEW_PATHS.update({'tests/governance/task_description_shard_routes_v3.py','tests/governance/test_task_description_shard_routes_v3.py'})
CI_V2_NEW_PATHS.update({'scripts/governance/project_task_bootstrap_failure_v2.py','tests/governance/test_task_bootstrap_failure_diagnostic_v2.py'})
CI_V2_NEW_PATHS.add('tests/governance/test_source_operation_snapshot_task_v2.py')
CI_V2_NEW_PATHS.update({'tests/governance/assessment_task_test_api_v2.py','tests/governance/task_directory_inventory_test_api_v2.py','tests/governance/test_source_snapshot_assessment_task_v2.py'})
CI_V2_NEW_PATHS.add('tests/governance/task_notice_native_source_test_api_v2.py')
CI_V2_NEW_PATHS.update({'tests/governance/assessment_legacy_DATA_model_v3.py','tests/governance/test_assessment_legacy_DATA_model_v3.py'})
CI_V2_NEW_PATHS.add('tests/governance/test_assessment_legacy_DATA_boundaries_v3.py')
CI_V2_NEW_PATHS.update({'tests/governance/task_explicit_shard_routes_v4.py','tests/governance/test_task_explicit_shard_routes_v4.py'})
CI_V2_NEW_PATHS.update({'scripts/governance/project_conduit_stage_failure_v3.py','tests/governance/test_conduit_stage_failure_v3.py'})
CI_V2_NEW_PATHS.add('tests/governance/project_grant_source_task_test_api_v2.py')
CI_V2_NEW_PATHS.add('tests/governance/test_conduit_receipt_rejection_v3.py')
CI_V2_NEW_PATHS.add('tests/governance/calendar_sql_task_test_api_v2.py')
CI_V2_NEW_PATHS.update({'support/governance/completion-task-v3/validate.py', 'scripts/governance/check_completion_task_receipt_v3.py', 'tests/governance/test_completion_same_owner_v3.py', 'support/governance/completion-task-v3/run.php', 'support/governance/completion-task-v3/capture.php', 'support/governance/completion-task-v3/prefix.php', 'scripts/governance/run_completion_task_v3.py'})
MAX=268435456

CI_V2_NEW_PATHS.update({'scripts/governance/project_completion_ci_integration_v3.py', 'tests/governance/calendar_failure_task_test_api_v2.py', 'tests/governance/task_source_candidate_test_api_v2.py', 'tests/governance/test_completion_ci_integration_v3.py'})

CI_V2_NEW_PATHS.update({'tests/governance/task_native_gate_test_api_v2.py', 'tests/governance/test_task_native_gate_test_api_v2.py'})

CI_V2_NEW_PATHS.update({'tests/governance/test_full_native_task_capture_cleanup_v2.py', 'tests/governance/test_task_explicit_shard_routes_v5.py', 'tests/governance/test_task_current_source_admission_task_v2.py', 'tests/governance/task_explicit_shard_routes_v5.py'})

CI_V2_NEW_PATHS.update({'tests/governance/test_api_credential_task_strict_body_v2.py', 'scripts/governance/check_api_credential_task_schema_receipt_v2.py', 'scripts/governance/api_credential_task_strict_body_v2.py'})

CI_V2_NEW_PATHS.update({'scripts/governance/project_media_task_selected_v2.py', 'tests/governance/test_media_task_runtime_v2.py', 'scripts/governance/media_task_archive_v2.py', 'tests/governance/test_media_current_archive_owner_v2.py', 'scripts/governance/media_task_runtime_v2.py', 'scripts/governance/prepare_media_task_archive_v2.py', 'scripts/governance/run_media_task_selected_v2.py'})

CI_V2_NEW_PATHS.update({'scripts/governance/paired_session_facts_task_strict_body_v2.py', 'scripts/governance/check_paired_session_facts_task_schema_receipt_v2.py', 'tests/governance/test_paired_session_facts_task_strict_body_v2.py'})

CI_V2_NEW_PATHS.update({'tests/governance/test_task_paired_failure_transport_v2.py', 'tests/governance/test_push_groups_upload_causality_v2.py', 'tests/governance/task_three_paired_workflow_shell_test_api_v2.py'})

CI_V2_NEW_PATHS.update({'scripts/governance/check_workbench_task_schema_receipt_v2.py', 'tests/governance/test_workbench_task_strict_body_v2.py', 'scripts/governance/workbench_task_strict_body_v2.py'})

CI_V2_NEW_PATHS.update({'tests/governance/test_calendar_task_strict_body_v2.py', 'scripts/governance/calendar_task_strict_body_v2.py', 'scripts/governance/check_calendar_task_schema_receipt_v2.py'})

CI_V2_NEW_PATHS.add('tests/governance/test_atomic_two_owner_task_routes_v2.py')

CI_V2_NEW_PATHS.update({'tests/governance/task_workbench_workflow_shell_test_api_v2.py'})

CI_V2_NEW_PATHS.update({'scripts/governance/project_calendar_failure_task_v2.py', 'tests/governance/test_calendar_failure_task_v2.py'})

CI_V2_NEW_PATHS.update({'tests/governance/task_calendar_workflow_shell_test_api_v2.py'})
CI_V2_NEW_PATHS.add('tests/governance/test_n11_task_runtime_entry_current_v2.py')
# Versioned notification admission keeps all original parent/FD checks.
CI_V2_NEW_PATHS.update({'scripts/governance/run_n11_task_current_v2.py', 'scripts/governance/n11_task_source_current_v2.py', 'tests/governance/test_n11_task_source_current_v2.py', 'tests/governance/test_n11_task_current_source_operation_v2.py'})

CI_V2_NEW_PATHS.update({'scripts/governance/current_native_pair_metadata_v1.py','tests/governance/test_current_native_pair_metadata_v1.py'})
# Explicit reviewed CI-tier additions. Unknown source paths still refuse.
CI_V2_NEW_PATHS.update({
 '.github/workflows/release-full.yml',
 'deploy/release/ci_policy.py',
 'docs/ci/README.md',
 'docs/ci/support-baseline.json',
 'docs/upstream/native-trial-acceptance-scope.md',
 'scripts/ci/core_guard.php',
 'scripts/ci/core_guard.py',
 'scripts/ci/focused_workflows.py',
 'scripts/ci/source_footprint.py',
 'tests/ci/test_core_focused_tiers.py',
 'tests/ci/test_core_footprint.py',
 'tests/ci/test_core_guard.py',
 'tests/ci/test_core_workflow.py',
 'tests/release/test_ci_policy.py',
})
# Exact additions for the external CI proof tool and its runbook.
CI_V2_NEW_PATHS.update({'scripts/ci/create_merge_source_proof.py','docs/ci/merge-source-proof.md'})
def need(v):
 if v is not True:raise ValueError('task_SourceOperation_admission_refused')
def sha(raw):return hashlib.sha256(raw).hexdigest()
def stamp(s):return(s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid,s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
def read(path):
 a=path.lstat();need(stat.S_ISREG(a.st_mode)and a.st_uid==os.getuid()and a.st_nlink==1 and not a.st_mode&0o022 and a.st_size<=16777216)
 fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
 try:
  raw=bytearray()
  while len(raw)<a.st_size:
   b=os.read(fd,min(65536,a.st_size-len(raw)));need(bool(b));raw.extend(b)
  need(os.read(fd,1)==b''and stamp(a)==stamp(os.fstat(fd))==stamp(path.lstat()));return bytes(raw),stamp(a)
 finally:os.close(fd)
def decode(raw):
 def pairs(items):
  d={}
  for k,v in items:need(k not in d);d[k]=v
  return d
 return json.loads(raw,object_pairs_hook=pairs,parse_constant=lambda _:need(False))
def task_preupgrade_fixture_inverse(relative,raw,recipe_raw):
 """Pure fixed DATA inverse; never executes a fixture, recipe, or source code."""
 need(type(relative)is str and relative==TASK_PREUPGRADE_FIXTURE)
 need(type(raw)is bytes and len(raw)==TASK_PREUPGRADE_CURRENT_PIN['bytes']and sha(raw)==TASK_PREUPGRADE_CURRENT_PIN['sha256'])
 need(type(recipe_raw)is bytes and len(recipe_raw)==TASK_PREUPGRADE_RECIPE_PIN['bytes']and sha(recipe_raw)==TASK_PREUPGRADE_RECIPE_PIN['sha256'])
 recipe=decode(recipe_raw)
 need(type(recipe)is dict and set(recipe)=={'schema','path','preimage_sha256','current_sha256','edits','exact_preimage_reconstructed','not_an_admission_grant'})
 need(recipe['schema']=='Task-preupgrade-fixture-literal-inverse-recipe-v1'and recipe['path']==TASK_PREUPGRADE_FIXTURE and recipe['preimage_sha256']==TASK_PREUPGRADE_PARENT_PIN['sha256']and recipe['current_sha256']==TASK_PREUPGRADE_CURRENT_PIN['sha256']and recipe['exact_preimage_reconstructed']is True and recipe['not_an_admission_grant']is True)
 edits=recipe['edits'];names=('private_stage_fields','closed_versioned_methods','explicit_preTask_audit_branch')
 need(type(edits)is list and len(edits)==len(names))
 restored=raw
 for edit,name in zip(edits,names):
  need(type(edit)is dict and set(edit)=={'name','old','added'}and edit['name']==name and edit['old']==''and type(edit['added'])is str and bool(edit['added']))
  addition=edit['added'].encode('utf-8');need(restored.count(addition)==1)
  restored=restored.replace(addition,b'',1)
 need(len(restored)==TASK_PREUPGRADE_PARENT_PIN['bytes']and sha(restored)==TASK_PREUPGRADE_PARENT_PIN['sha256'])
 return restored

def git_tree(root,expected_checkpoint):
 need(type(expected_checkpoint)is str and re.fullmatch('[a-f0-9]{40}',expected_checkpoint)is not None)
 env={'PATH':'/usr/local/bin:/opt/homebrew/bin:/usr/bin:/bin','HOME':str(Path.home()),'LANG':'C','GIT_NO_REPLACE_OBJECTS':'1','GIT_OPTIONAL_LOCKS':'0'}
 def git(*args,**kw):return subprocess.run(['git','-C',str(root),*args],env=env,stdin=None if'input'in kw else subprocess.DEVNULL,capture_output=True,timeout=30,check=True,**kw)
 revision=git('rev-parse','HEAD').stdout.decode().strip();need(revision==expected_checkpoint)
 try:
  verified=git('verify-commit','--raw',revision)
  need(re.search(rb'^\[GNUPG:\] (?:BADSIG|ERRSIG|EXPSIG|EXPKEYSIG|REVKEYSIG|KEYREVOKED|SIGEXPIRED|KEYEXPIRED)(?: |$)',verified.stderr,re.M)is None)
  need(re.findall(rb'^\[GNUPG:\] VALIDSIG ([A-F0-9]{40}) ',verified.stderr,re.M)==[ROOT_SIGNER.encode()])
 except (ValueError,subprocess.CalledProcessError):
  # External CI only: independently reverify the exact merge proof with the
  # fixed public-only trust home. Never consume a boolean admission receipt.
  tag=os.environ.get('PHORGE_CI_MERGE_SOURCE_PROOF')
  need(tag=='source-proof/'+revision)
  import check_task_ci_head_v1 as admission
  need(admission.SIGNER==ROOT_SIGNER)
  admission.verify_checkout(root,revision,trust_home=str(Path.home()),external_proof_tag=tag)
 git('merge-base','--is-ancestor',BASE,revision);git('diff','--quiet',revision,'--');git('diff','--cached','--quiet',revision,'--')
 rows=[];names=set()
 for item in git('ls-tree','-rz','--full-tree',revision).stdout.split(b'\0'):
  if not item:continue
  a,p=item.split(b'\t',1);mode,kind,oid=a.decode().split();p=p.decode();need(kind=='blob'and mode in('100644','100755','120000')and p not in names);names.add(p);rows.append((p,mode,oid))
 need(len(rows)<=12000);batch=git('cat-file','--batch',input=('\n'.join(r[2]for r in rows)+'\n').encode()).stdout;need(len(batch)<=MAX+len(rows)*128)
 entries={};raws={};offset=0
 for p,mode,oid in rows:
  end=batch.find(b'\n',offset);need(offset<=end<offset+128);h=batch[offset:end].decode().split();need(len(h)==3 and h[0]==oid and h[1]=='blob'and re.fullmatch('0|[1-9][0-9]*',h[2])is not None);n=int(h[2]);need(n<=16777216);offset=end+1;raw=batch[offset:offset+n];offset+=n;need(len(raw)==n and batch[offset:offset+1]==b'\n');offset+=1
  entries[p]={'kind':'symlink','target':raw.decode(),'sha256':sha(raw)}if mode=='120000'else{'kind':'file','mode':int(mode[-3:],8),'bytes':n,'sha256':sha(raw)};raws[p]=raw
 need(offset==len(batch));return revision,entries,raws

class TaskContext:
 def __init__(self,operation,root,entries,git_raws):
  self.operation=operation;self.root=Path(root);self.entries=entries;self.files={};self.directories={};self.current_raw={};self.closed=False
  self._path_cache_root=self.root;paths={};file_parents={};parent_chains={};ancestor_objects={}
  for rel,pin in entries.items():
   path=self.root/rel;paths[rel]=path
   directory=path.parent
   if directory not in parent_chains:
    parent_chains[directory]=tuple(ancestor_objects.setdefault(parent,parent)for parent in path.parents)
    # Previously encountered chains contain only already populated directory
    # keys. Skip repeated pure membership queries, never filesystem checks.
    for parent in parent_chains[directory]:
     if parent not in self.directories:
      s=parent.lstat();need(stat.S_ISDIR(s.st_mode)and not parent.is_symlink());self.directories[parent]=self.directory_stamp(parent,s)
   file_parents[rel]=parent_chains[directory]
   if pin['kind']=='file':
    raw,identity=read(path);need(stat.S_IMODE(identity[2])==pin['mode']and len(raw)==pin['bytes']and sha(raw)==pin['sha256']);self.current_raw[rel]=raw
   else:
    st=path.lstat();need(stat.S_ISLNK(st.st_mode)and os.readlink(path)==pin['target']);identity=stamp(st)
   self.files[rel]=identity
  self._captured_paths=MappingProxyType(paths);self._captured_file_parents=MappingProxyType(file_parents)
  self._directory_scopes_root=self.root
  self._directory_scopes=MappingProxyType({path:path.is_relative_to(self.root)for path in self.directories})
  need(sha(self.current_raw[BASELINE])==BASELINE_SHA and sha(self.current_raw[INVERSE])==INVERSE_SHA)
  self.parent_pins=decode(self.current_raw[BASELINE])['entries'];self.api_inverse=decode(self.current_raw[INVERSE]);self.reduced={}
  need(sha(self.current_raw[CI_INVERSE])==CI_INVERSE_SHA)
  ci=decode(self.current_raw[CI_INVERSE]);need(set(ci)=={'schema','parent_revision','files'}and ci['schema']=='Task-CI-modified-parent5903-v1'and ci['parent_revision']=='5903d98b14926401f0be49cb0a9d6168026a2642')
  self.ci_inverse=ci['files'];need(set(self.ci_inverse)=={'tests/governance/test_recovery_spaces_source_gate.py', 'tests/governance/test_restore_current_schema_profile.py', 'tests/governance/test_recovery_borrowed_native_producer.py', 'tests/governance/test_application_config_atomic_ci.py', 'scripts/governance/check_native_organization.py', '.github/workflows/atomic-feature-regressions.yml', 'tests/atomic/test_current_pair_consumers.py', 'tests/governance/test_recovery_session_lease_ci.py', 'tests/governance/recovery_oauth_code_test_cohort.py', 'tests/governance/test_task_owner_atomic_ci.py', 'tests/governance/test_recovery_oauth_access_sql.py', 'src/applications/auth/__tests__/PhabricatorTraefikAuthProviderTestCase.php', 'deploy/acceptance/build-lock.json', 'tests/governance/test_recovery_oauth_authorization_coordination.py', 'support/governance/notification/wiki-render-service.php', 'tests/governance/test_private_calendar_sql_gate.py', 'tests/governance/test_notice_native_admin_wire_source.py', 'tests/governance/test_callers.py', 'tests/governance/test_n05_r3_historical_test_view.py', 'tests/governance/test_directory_transports_receipt_v1.py', 'tests/governance/test_recovery_session_lease_gate.py', 'tests/governance/test_recovery_conduit_participant.py', 'tests/governance/test_native_directory.py', 'tests/governance/test_media_fixture_contentgrant_migration.py', 'tests/governance/test_directory_terminal_failure_v1.py', 'tests/governance/test_directory_transport_failure_v1.py', 'tests/governance/test_organization_approval_workbench_atomic_ci.py', 'tests/governance/test_recovery_borrowed_wiring.py', 'tests/governance/test_project_content_grant_source_cohort.py', 'tests/governance/test_source_operation_snapshot.py', 'tests/governance/test_recovery_oauth_code_ci.py', '.github/workflows/private-mail-wire.yml', '.github/workflows/native-media-session-positive-sql.yml', 'tests/governance/test_project_content_grant_ci_runtime.py', 'tests/governance/test_recovery_assessment_current.py', 'tests/governance/test_project_content_grant_ci_workflow.py', 'tests/governance/test_recovery_oauth_authorization_mysql84.py', 'tests/governance/test_file_source_atomic_ci.py', 'tests/atomic/packages/D01.json', 'tests/governance/test_recovery_oauth_authorization_sql.py', 'scripts/governance/project_description_admission_delta.py', 'tests/governance/test_recovery_borrowed_session.py', 'tests/atomic/paired-gorge.json', 'src/applications/config/schema/PhabricatorConfigSchemaSpec.php', 'tests/governance/test_native_worker_terminal.py', '.github/workflows/oauth-access-producer-focused.yml', '.github/workflows/project-content-grants-focused.yml', 'tests/governance/test_recovery_conduit_wiring.py', 'tests/governance/test_private_calendar_wire.py', 'tests/governance/test_recovery_borrowed_worker_diagnostic.py', 'support/governance/project-content-grants-ci/ci.py', 'tests/governance/test_recovery_assessment_credentials.py', 'tests/governance/test_recovery_oauth_access_source.py', 'tests/governance/test_recovery_oauth_authorization_ci.py', 'tests/governance/test_recovery_session_actor_order.py', 'tests/governance/test_notice_history_lifecycle.py', 'tests/governance/test_recovery_file_parent_ci.py', '.github/workflows/oauth-authorization-producer-focused.yml', 'tests/atomic/extract_native_renderer.py', '.github/workflows/conduit-producer-focused.yml', 'tests/governance/test_project_content_grant_target_binding.py', 'tests/governance/test_calendar_conduit_union.py', 'docs/governance/batch-2-callers.json', 'tests/governance/test_api_concurrency_atomic_ci.py', '.github/workflows/project-description-focused.yml', 'tests/governance/test_recovery_oauth_code_sql.py', '.github/workflows/native-directory-transports-v1.yml', 'tests/governance/test_project_source_atomic_ci.py', 'tests/governance/test_media_remote_native_profile.py', 'tests/governance/test_recovery_oauth_code_media_binding.py', '.github/workflows/oauth-code-producer-focused.yml', 'tests/governance/test_private_calendar_failure_diagnostic.py', 'tests/governance/test_recovery_assessment_credential_sql.py', 'tests/governance/test_recovery_oauth_code_coordination.py', 'tests/governance/test_native_organization.py', 'tests/governance/test_directory_source_inventory_pin.py', 'tests/governance/test_recovery_oauth_access_ci.py', 'tests/governance/test_recovery_file_parent_gate.py', 'support/governance/project-description-ci/ci.py', '.github/workflows/assessment-credential-focused.yml', 'support/governance/media-session-ci/test_ci.py', 'tests/governance/test_recovery_file_parent_policy.py', 'tests/governance/test_recovery_oauth_client_lifecycle_ci.py', 'tests/governance/test_n05_legacy_audit_test_view.py', 'tests/governance/test_recovery_partial_wiring.py', '.github/workflows/oauth-client-lifecycle-focused.yml', 'tests/governance/test_recovery_oauth_client_lifecycle_coordination.py', 'tests/governance/test_recovery_partial_schema_coordination.py'})
  need(set(entries)==set(self.parent_pins)|set(TASK_NEW_PINS)|{BASELINE,INVERSE,'scripts/governance/task_source_operation_context.py'}|V2_PATHS|CI_V2_NEW_PATHS)
  for rel,pin in TASK_NEW_PINS.items():need(len(self.current_raw[rel])==pin['bytes']and sha(self.current_raw[rel])==pin['sha256'])
  # Compile CURRENT Task inverse, captured from this real source; never Git old code.
  path='scripts/governance/task_content_grant_source_delta.py';namespace={'__file__':str(self.root/path),'__name__':'TaskOperation_current_inverse'};exec(compile(self.current_raw[path],str(self.root/path),'exec'),namespace);self.reduce=namespace['reduce_selected']
  for rel,pin in entries.items():
   if pin['kind']!='file':continue
   raw=self.current_raw[rel]
   if rel in self.ci_inverse:
    inverse=self.ci_inverse[rel];decoder=zlib.decompressobj();raw=decoder.decompress(base64.b64decode(inverse['zlib_base64'],validate=True),inverse['bytes']+1);need(decoder.eof and not decoder.unused_data and not decoder.unconsumed_tail and len(raw)==inverse['bytes']and sha(raw)==inverse['sha256'])
   elif rel in self.api_inverse:
    inverse=self.api_inverse[rel];decoder=zlib.decompressobj();raw=decoder.decompress(base64.b64decode(inverse['zlib_base64'],validate=True),inverse['bytes']+1);need(decoder.eof and not decoder.unused_data and not decoder.unconsumed_tail and len(raw)==inverse['bytes']and sha(raw)==inverse['sha256'])
   elif rel in self.parent_pins:
    if rel==TASK_PREUPGRADE_FIXTURE:
     raw=task_preupgrade_fixture_inverse(rel,raw,self.current_raw[TASK_PREUPGRADE_RECIPE])
    raw=self.reduce(rel,raw)
   else:raw=None
   if rel in self.parent_pins:
    old=self.parent_pins[rel];need(old['kind']=='file'and raw is not None and len(raw)==old['bytes']and sha(raw)==old['sha256']);self.reduced[rel]=raw
   else:need(raw is None)
  need(set(self.parent_pins).issubset(entries))
  for rel,pin in self.parent_pins.items():
   if pin['kind']=='symlink':need(entries[rel]==pin)
 def directory_stamp(self,path,info=None):
  # os.lstat is the same non-following stat, without Path.lstat/stat wrappers.
  value=stamp(os.lstat(path)if info is None else info)
  # Cache pure lexical scope only. Every call still obtains/checks actual stat.
  scopes=getattr(self,'_directory_scopes',None)
  if scopes is not None and (self.root is self._directory_scopes_root or self.root==self._directory_scopes_root):
   try:inside=scopes[path]
   except KeyError:inside=path.is_relative_to(self.root)
  else:inside=path.is_relative_to(self.root)
  return value if inside else value[:5]
 def require(self):
  self.operation._require_task_context(self);need(not self.closed)
 def current(self,relative):
  self.require();need(relative in self.current_raw)
  cached_root=getattr(self,'_path_cache_root',None)
  bound=cached_root is self.root or cached_root==self.root
  paths=getattr(self,'_captured_paths',None);parents=getattr(self,'_captured_file_parents',None)
  path=paths[relative]if bound and paths is not None and relative in paths else self.root/relative
  chain=parents[relative]if bound and parents is not None and relative in parents else path.parents
  need(stamp(path.lstat())==self.files[relative])
  for p in chain:need(self.directory_stamp(p)==self.directories[p])
  return self.current_raw[relative]
 def parent(self,relative):
  self.current(relative);return self.reduced.get(relative)
 def exit(self):
  self.require()
  try:
   for p,identity in self.directories.items():need(self.directory_stamp(p)==identity)
   for rel,pin in self.entries.items():
    paths=getattr(self,'_captured_paths',None)
    cached_root=getattr(self,'_path_cache_root',None)
    path=paths[rel]if (cached_root is self.root or cached_root==self.root)and paths is not None and rel in paths else self.root/rel
    need(stamp(path.lstat())==self.files[rel])
    if pin['kind']=='file':raw,_=read(path);need(raw==self.current_raw[rel])
    else:need(os.readlink(path)==pin['target'])
  finally:self.closed=True

def capture_current(operation,roots):
 code=roots[0];revision,entries,raws=git_tree(code,operation._task_expected_checkpoint)
 # The known-root signer authenticates exact CURRENT code and data. Producer
 # copies are checked against the identical real tree; prepared files are
 # still solely accepted by the original strict PreparedLedger policy.
 result={root:TaskContext(operation,root,entries,raws)for root in dict.fromkeys(roots)}
 return result

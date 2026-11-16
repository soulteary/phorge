"""Synthetic MAC/schema mutation vectors only; NO SQL/runtime qualification."""
import copy,hashlib,hmac,importlib.util,json,os,subprocess,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
D=ROOT/'support/governance/task-additive-schema-v2'
spec=importlib.util.spec_from_file_location('Task_additive_schema_under_test',D/'validate.py');gate=importlib.util.module_from_spec(spec);spec.loader.exec_module(gate)
class TaskAdditiveSchemaVectors(unittest.TestCase):
 def setUp(self):
  self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup);home=Path(self.temp.name).resolve();self.root=home/'source';self.root.mkdir(mode=0o700)
  # Only exact SQL/SchemaSpec source inputs are real; all DB observations below
  # are synthetic contract vectors, never a fake279artifact or a native run.
  reference=Path(os.environ.get('TASK_ADOPTER_TEST_REFERENCE_ROOT',str(ROOT)))
  self.pins={}
  for relative in(gate.SQL_PATH,gate.SPEC_PATH,'support/governance/completion/fixture.php','support/governance/task-additive-schema-v2/capture.php','resources/sql/quickstart.sql'):
   p=self.root/relative;p.parent.mkdir(parents=True,exist_ok=True)
   if relative in(gate.SQL_PATH,gate.SPEC_PATH):raw=(reference/relative).read_bytes()
   elif relative.endswith('capture.php'):raw=(D/'capture.php').read_bytes()
   else:raw=b'-- synthetic source binding, not a fixture or restore\n'
   p.write_bytes(raw);p.chmod(0o644);self.pins[relative]=gate.sha(raw)
  self.profile={'schema':'Task-additive-schema-private-binding-v1','runtime_authorized':True,'mode':'directory-Task-OFF','run_id':'1'*32,'hmac_key':'4'*64,'source_root':str(self.root),'source_revision':'a'*40,'source_pins':self.pins,'namespace':'governance_native_'+'b'*16,'server_uuid':'11111111-2222-3333-4444-555555555555','fixture_nonce_sha256':'c'*64,'standard_upgrade_receipt_sha256':'d'*64,'mysql_version':'8.4.13','patch_discovery':{'full_key':'actual-API-test-vendor:20261112.task.contentgrants.sql','leaf_key':'20261112.task.contentgrants.sql','path':gate.SQL_PATH,'sha256':gate.SQL_SHA,'discovered_by':'PhabricatorSQLPatchList.buildAllPatches/getFullKey'},'parent_table_names':['app.t%04d'%n for n in range(549)],'parent_patch_keys':['old-api:k%04d'%n for n in range(1575)],'parent_migration_hashes':{'old-api:migration.sql':'e'*64}}
  self.path=home/'profile.private.json';self.write_profile();self.native={'source_restore':{'quickstart_sha256':self.pins['resources/sql/quickstart.sql'],'app_schema_count':54,'owned_schema_count':55,'quickstart_source_table_count':500,'current_table_count':552,'restore_phase_workers_started':0,'restore_phase_network_endpoints_configured':0,'patch_audit':{'source_patch_count':1576,'applied_patch_count':1576,'pending_patch_keys':[],'current_patch_ledger_complete':True,'migration_source_hashes':{**self.profile['parent_migration_hashes'],self.profile['patch_discovery']['full_key']:gate.SQL_SHA},'migrations_executed':2}},'cleanup':{'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True,'ownedAuxiliarySetupConnectionsClosed':2},'untouched_business_vector':['unchanged']};self.env=self.envelope()
 def write_profile(self):
  raw=gate.encoded(self.profile);self.path.write_bytes(raw);self.path.chmod(0o600);self.pin=gate.sha(raw)
 def mac(self,value):return hmac.new(bytes.fromhex(self.profile['hmac_key']),gate.encoded(value),hashlib.sha256).hexdigest()
 def capture(self,phase):
  p=self.profile;patch=p['patch_discovery'];keys=sorted(p['parent_patch_keys']+[patch['full_key']]);d={'schema':'Task-additive-schema-capture-v1','profile_sha256':self.pin,'run_id':p['run_id'],'phase':phase,'capture_id':('2'if phase=='before'else'3')*32,'source_root':p['source_root'],'source_revision':p['source_revision'],'source_pins':p['source_pins'],'namespace':p['namespace'],'server_uuid':p['server_uuid'],'fixture_nonce_sha256':p['fixture_nonce_sha256'],'task_feature_enabled':False,'standard_upgrade_receipt_sha256':p['standard_upgrade_receipt_sha256'],'patch_discovery':patch,'source_schema_spec':gate.expected_schema(),'observed_schema':gate.expected_schema(),'row_counts':{n:0 for n in gate.NAMES},'table_inventory':sorted(p['parent_table_names']+['maniphest.'+n for n in gate.NAMES]),'source_patch_keys':keys,'patch_ledger':keys,'restore':copy.deepcopy(self.native['source_restore']),'read_only_transaction':True,'raw_user_rows_exported':False,'php_version':'8.5.12','mysql_version':'8.4.13','config_epoch':[{'namespace':'default','config_key':'governance.task-content-grants.enabled','epoch':1}]};return {'payload':copy.deepcopy(d),'mac':self.mac(d)}
 def envelope(self):
  e={'schema':'Task-directory-native-envelope-v2','profile_sha256':self.pin,'native_receipt':copy.deepcopy(self.native),'before':self.capture('before'),'after':self.capture('after')};e['mac']=self.mac(e);return e
 def reseal(self,e):
  for phase in('before','after'):e[phase]['mac']=self.mac(e[phase]['payload'])
  e['mac']=self.mac({k:v for k,v in e.items()if k!='mac'})
 def validate(self,e=None):return gate.validate_envelope(self.env if e is None else e,self.path,self.pin)
 def conduit_envelope(self,cleanup):
  self.profile['mode']='conduit-Task-OFF';self.write_profile();self.native['cleanup']=cleanup;e=self.envelope();e['schema']='Task-native-envelope-v3';self.reseal(e);return e
 def test_conduit_projects_only_exact_two_capture_owned_socket_cleanup(self):
  cleanup={'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True,'ownedAuxiliarySetupConnectionsClosed':2};e=self.conduit_envelope(cleanup);original=copy.deepcopy(e)
  projected,proof=gate.validate_envelope(e,self.path,self.pin,expected_mode='conduit-Task-OFF');self.assertEqual(e,original);self.assertEqual(projected['cleanup'],{k:v for k,v in cleanup.items()if k!='ownedAuxiliarySetupConnectionsClosed'});self.assertFalse(proof['actual_SQL_executed_by_validator'])
  # This projection never repairs an original strict field: downstream exact must reject.
  for key,bad in [('ownedSchemasRemoved',54),('ownedRuntimeUsersRemoved',1),('readerRemoved',False)]:
   changed=copy.deepcopy(cleanup);changed[key]=bad;e=self.conduit_envelope(changed);p,_=gate.validate_envelope(e,self.path,self.pin,expected_mode='conduit-Task-OFF');self.assertEqual(p['cleanup'][key],bad)
 def test_conduit_cleanup_projection_refuses_wrong_count_type_or_shape(self):
  cleanup={'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True,'ownedAuxiliarySetupConnectionsClosed':2}
  bad=[]
  for value in (True,False,0,1,3,2.0,'2',None):
   changed=copy.deepcopy(cleanup);changed['ownedAuxiliarySetupConnectionsClosed']=value;bad.append(changed)
  changed=copy.deepcopy(cleanup);del changed['ownedAuxiliarySetupConnectionsClosed'];bad.append(changed)
  changed=copy.deepcopy(cleanup);changed['extra']=True;bad.append(changed)
  for changed in bad:
   e=self.conduit_envelope(changed)
   with self.assertRaises(ValueError):gate.validate_envelope(e,self.path,self.pin,expected_mode='conduit-Task-OFF')
 def test_conduit_capture_cleanup_projection_follows_MAC_acceptance(self):
  cleanup={'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True,'ownedAuxiliarySetupConnectionsClosed':2};e=self.conduit_envelope(cleanup);e['native_receipt']['cleanup']['ownedAuxiliarySetupConnectionsClosed']=3
  with self.assertRaises(ValueError):gate.validate_envelope(e,self.path,self.pin,expected_mode='conduit-Task-OFF')
 def focused_envelope(self,mode,cleanup):
  self.profile['mode']=mode;self.write_profile();self.native['cleanup']=cleanup;e=self.envelope()
  if mode!='directory-Task-OFF':e['schema']='Task-native-envelope-v3'
  self.reseal(e);return e
 def test_existing_Task_File_auxiliary_obligations_are_preserved_exactly(self):
  expected={'task-owner-source-facts-Task-OFF':(8,6),'file-source-facts-Task-OFF':(12,10)}
  self.assertEqual(gate.CAPTURE_NATIVE_AUXILIARY,expected)
  self.assertFalse(set(expected)&set(gate.CAPTURE_CLEANUP_MODES))
  for mode,(total,native)in expected.items():
   cleanup={'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True,'ownedAuxiliarySetupConnectionsClosed':total}
   e=self.focused_envelope(mode,cleanup);before=copy.deepcopy(e);projected,proof=gate.validate_envelope(e,self.path,self.pin,expected_mode=mode)
   self.assertEqual(e,before);self.assertEqual(projected['cleanup'],dict(cleanup,ownedAuxiliarySetupConnectionsClosed=native));self.assertEqual(proof['exact_projection']['native_auxiliary_setup_connections'],[total,native]);self.assertEqual(proof['exact_projection']['capture_pair_connections_removed'],2)
 def test_Task_File_auxiliary_wrong_type_total_shape_or_MAC_cannot_be_projected(self):
  for mode,(total,native)in {'task-owner-source-facts-Task-OFF':(8,6),'file-source-facts-Task-OFF':(12,10)}.items():
   base={'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True,'ownedAuxiliarySetupConnectionsClosed':total}
   for count in [None,True,False,0,1,2,native,total-1,total+1,str(total),float(total)]:
    with self.subTest(mode=mode,count=count),self.assertRaises(ValueError):gate.validate_envelope(self.focused_envelope(mode,dict(base,ownedAuxiliarySetupConnectionsClosed=count)),self.path,self.pin,expected_mode=mode)
   for key in base:
    bad=dict(base);del bad[key]
    with self.subTest(mode=mode,key=key),self.assertRaises(ValueError):gate.validate_envelope(self.focused_envelope(mode,bad),self.path,self.pin,expected_mode=mode)
   with self.assertRaises(ValueError):gate.validate_envelope(self.focused_envelope(mode,dict(base,unknown=False)),self.path,self.pin,expected_mode=mode)
   e=self.focused_envelope(mode,base);e['native_receipt']['cleanup']['ownedAuxiliarySetupConnectionsClosed']=total+1
   with self.assertRaises(ValueError):gate.validate_envelope(e,self.path,self.pin,expected_mode=mode)
 def test_Task_File_other_original_cleanup_fields_are_never_repaired(self):
  for mode,(total,native)in {'task-owner-source-facts-Task-OFF':(8,6),'file-source-facts-Task-OFF':(12,10)}.items():
   for key,bad in [('ownedSchemasRemoved',54),('ownedRuntimeUsersRemoved',1),('readerRemoved',False)]:
    row={'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True,'ownedAuxiliarySetupConnectionsClosed':total};row[key]=bad;e=self.focused_envelope(mode,row);out,_=gate.validate_envelope(e,self.path,self.pin,expected_mode=mode);self.assertEqual(out['cleanup'][key],bad)
 def test_all_focused_roles_project_only_exact_two_capture_sockets(self):
  modes={'directory-Task-OFF','conduit-Task-OFF','oauth-access-Task-OFF','oauth-code-Task-OFF','oauth-authorization-Task-OFF','oauth-client-lifecycle-Task-OFF','assessment-credential-Task-OFF','api-credential-concurrency-Task-OFF','application-config-facts-Task-OFF','project-source-facts-Task-OFF','spaces-source-facts-Task-OFF','recovery-partial-composition-Task-OFF','file-parent-policy-Task-OFF','session-producer-lease-Task-OFF','session-borrowed-owner-Task-OFF'}
  self.assertEqual(gate.CAPTURE_CLEANUP_MODES,modes)
  for mode in sorted(modes):
   with self.subTest(mode=mode):
    cleanup={'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True,'ownedAuxiliarySetupConnectionsClosed':2}
    e=self.focused_envelope(mode,cleanup);original=copy.deepcopy(e)
    projected,proof=gate.validate_envelope(e,self.path,self.pin,expected_mode=mode)
    self.assertEqual(e,original);self.assertEqual(projected['cleanup'],{'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True})
    self.assertEqual(proof['exact_projection']['capture_auxiliary_setup_connections'],[2,0])
    for field,value in [('ownedSchemasRemoved',54),('ownedRuntimeUsersRemoved',1),('readerRemoved',False)]:
     bad=copy.deepcopy(cleanup);bad[field]=value;e=self.focused_envelope(mode,bad)
     p,_=gate.validate_envelope(e,self.path,self.pin,expected_mode=mode)
     self.assertEqual(p['cleanup'][field],value) # unchanged old checker must reject
 def test_each_focused_role_refuses_capture_cleanup_shape_count_or_mac_mutation(self):
  for mode in sorted(gate.CAPTURE_CLEANUP_MODES):
   cleanup={'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True,'ownedAuxiliarySetupConnectionsClosed':2}
   bad=[]
   for value in (True,False,0,1,3,2.0,'2',None):
    row=copy.deepcopy(cleanup);row['ownedAuxiliarySetupConnectionsClosed']=value;bad.append(row)
   for field in cleanup:
    row=copy.deepcopy(cleanup);del row[field];bad.append(row)
   row=copy.deepcopy(cleanup);row['unexpected']=True;bad.append(row)
   for row in bad:
    with self.subTest(mode=mode,cleanup=row):
     e=self.focused_envelope(mode,row)
     with self.assertRaises(ValueError):gate.validate_envelope(e,self.path,self.pin,expected_mode=mode)
   e=self.focused_envelope(mode,cleanup);e['native_receipt']['cleanup']['ownedAuxiliarySetupConnectionsClosed']=3
   with self.assertRaises(ValueError):gate.validate_envelope(e,self.path,self.pin,expected_mode=mode)
 def test_full_native_retains_capture_cleanup_for_distinct_Completion_consumer(self):
  cleanup={'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True,'ownedAuxiliarySetupConnectionsClosed':2}
  e=self.focused_envelope('full-native-Task-OFF',cleanup)
  projected,proof=gate.validate_envelope(e,self.path,self.pin,expected_mode='full-native-Task-OFF')
  self.assertEqual(projected['cleanup'],cleanup)
  self.assertNotIn('capture_auxiliary_setup_connections',proof['exact_projection'])
 def test_bound_pair_permits_only_exact_copy_projection_and_preserves_original(self):
  original=copy.deepcopy(self.env);projected,proof=self.validate();self.assertEqual(self.env,original);self.assertEqual(projected['source_restore']['current_table_count'],549);self.assertEqual(projected['source_restore']['patch_audit']['source_patch_count'],1575);self.assertEqual(projected['source_restore']['patch_audit']['migrations_executed'],1);self.assertEqual(projected['untouched_business_vector'],['unchanged']);self.assertTrue(proof['legacy_v1_validation_still_required']);self.assertFalse(proof['actual_SQL_executed_by_validator'])
 def test_explicit_OAuth_role_modes_refuse_default_or_cross_role_projection(self):
  for mode in('oauth-authorization-Task-OFF','oauth-client-lifecycle-Task-OFF'):
   self.profile['mode']=mode;self.write_profile();e=self.envelope();e['schema']='Task-native-envelope-v3';self.reseal(e)
   with self.assertRaises(ValueError):gate.validate_envelope(e,self.path,self.pin)
   projected,proof=gate.validate_envelope(e,self.path,self.pin,expected_mode=mode);self.assertEqual(proof['native_profile_mode'],mode);self.assertEqual(projected['source_restore']['current_table_count'],549)
   other='oauth-client-lifecycle-Task-OFF'if mode.startswith('oauth-authorization')else'oauth-authorization-Task-OFF'
   with self.assertRaises(ValueError):gate.validate_envelope(e,self.path,self.pin,expected_mode=other)
 def test_wrong_source_uuid_namespace_nonce_and_upgrade_binding_refused_even_with_valid_MAC(self):
  for key,value in [('source_revision','f'*40),('source_root','/foreign'),('server_uuid','99999999-2222-3333-4444-555555555555'),('namespace','governance_native_'+'9'*16),('fixture_nonce_sha256','9'*64),('standard_upgrade_receipt_sha256','8'*64)]:
   e=copy.deepcopy(self.env);e['before']['payload'][key]=value;e['after']['payload'][key]=value;self.reseal(e)
   with self.assertRaises(ValueError):self.validate(e)
 def test_bad_patch_name_missing_ledger_key_and_unknown_ledger_extra_refused(self):
  for mutation in('patch','missing','extra'):
   e=copy.deepcopy(self.env)
   for phase in('before','after'):
    if mutation=='patch':e[phase]['payload']['patch_discovery']['full_key']='guessed:wrong'
    elif mutation=='missing':e[phase]['payload']['patch_ledger'].pop()
    else:e[phase]['payload']['patch_ledger'].append('unknown:extra')
   self.reseal(e)
   with self.assertRaises(ValueError):self.validate(e)
 def test_wrong_column_index_engine_constraint_trigger_or_table_extra_refused(self):
  for change in('column','index','engine','constraint','trigger','table'):
   e=copy.deepcopy(self.env)
   for phase in('before','after'):
    schema=e[phase]['payload']['observed_schema'];table=schema['governance_taskcontentgrant']
    if change=='column':table['columns'][0]['type']='varbinary(32)'
    elif change=='index':table['indexes']['key_scope']['visible']=False
    elif change=='engine':table['engine']='MyISAM'
    elif change=='constraint':table['constraints'].append({'name':'unknown','type':'FOREIGN KEY'})
    elif change=='trigger':table['triggers'].append({'unknown':'trigger'})
    else:schema['unknown_extra']={}
   self.reseal(e)
   with self.assertRaises(ValueError):self.validate(e)
 def test_unknown_unrelated_inventory_and_nonempty_OFF_tables_refused(self):
  for change in('table','row','flag','epoch'):
   e=copy.deepcopy(self.env)
   for phase in('before','after'):
    p=e[phase]['payload']
    if change=='table':p['table_inventory'].append('other.unknown')
    elif change=='row':p['row_counts'][gate.NAMES[0]]=1
    elif change=='flag':p['task_feature_enabled']=True
    else:p['config_epoch'][0]['epoch']=2
   self.reseal(e)
   with self.assertRaises(ValueError):self.validate(e)
 def test_inflight_schema_or_restore_drift_and_duplicate_capture_refused(self):
  for change in('drift','duplicate'):
   e=copy.deepcopy(self.env)
   if change=='drift':e['after']['payload']['restore']['quickstart_source_table_count']=501
   else:e['after']['payload']['capture_id']=e['before']['payload']['capture_id']
   self.reseal(e)
   with self.assertRaises(ValueError):self.validate(e)
 def test_unauthenticated_or_wrong_profile_or_duplicate_JSON_refused(self):
  e=copy.deepcopy(self.env);e['before']['payload']['capture_id']='7'*32
  with self.assertRaises(ValueError):self.validate(e)
  with self.assertRaises(ValueError):gate.validate_envelope(self.env,self.path,'9'*64)
  with self.assertRaises(ValueError):gate.decode(b'{"before":{},"before":{}}')
 def test_integer_boolean_schema_type_confusion_and_raw_user_export_refused(self):
  for change in('type','raw'):
   e=copy.deepcopy(self.env)
   for phase in('before','after'):
    if change=='type':e[phase]['payload']['observed_schema']['governance_taskcontentgrant']['columns'][0]['nullable']=0
    else:e[phase]['payload']['raw_user_rows_exported']=True
   self.reseal(e)
   with self.assertRaises(ValueError):self.validate(e)
 def test_Task_metadata_qualifier_mode_cannot_inherit_empty_table_assumption(self):
  self.profile['mode']='Task-metadata-qualification';self.write_profile()
  with self.assertRaises(ValueError):gate.profile(self.path,self.pin)
 def test_changed_source_pin_refused(self):
  (self.root/gate.SPEC_PATH).write_text('changed')
  with self.assertRaises(ValueError):self.validate()
 def test_profile_symlink_hardlink_and_duplicate_captures_refused(self):
  alias=self.path.parent/'alias';alias.symlink_to(self.path)
  with self.assertRaises(ValueError):gate.validate_envelope(self.env,alias,self.pin)
  alias.unlink();os.link(self.path,alias)
  with self.assertRaises(ValueError):self.validate()
  alias.unlink();e=copy.deepcopy(self.env);e['after']=copy.deepcopy(e['before']);self.reseal(e)
  with self.assertRaises(ValueError):self.validate(e)
 def test_private_binding_changes_one_literal_and_does_not_execute_or_modify_source(self):
  spec=importlib.util.spec_from_file_location('Task_schema_binder_under_test',D/'bind.py');binder=importlib.util.module_from_spec(spec);spec.loader.exec_module(binder)
  raw=(self.root/'support/governance/task-additive-schema-v2/capture.php').read_bytes();out=self.path.parent/'bound';receipt=binder.bind(self.path,self.pin,out)
  self.assertEqual((self.root/'support/governance/task-additive-schema-v2/capture.php').read_bytes(),raw);bound=(out/'capture-bound.php').read_bytes();marker=("\nconst N05_TASK_ADDITIVE_PROFILE_SHA256='"+self.pin+"';\n").encode();self.assertEqual(bound.count(marker),1);self.assertEqual(bound.replace(marker,b'\nconst N05_TASK_ADDITIVE_PROFILE_SHA256=null;\n'),raw);self.assertFalse(receipt['SQL_executed']);self.assertFalse(receipt['PHP_executed'])
 def test_default_PHP_entry_denies_before_app_bootstrap_or_database(self):
  r=subprocess.run(['php',str(D/'capture.php')],capture_output=True,text=True,timeout=5);self.assertEqual(r.returncode,1);self.assertEqual(r.stdout,'');self.assertEqual(r.stderr,'Task_additive_schema_unbound_before_DB\n')
if __name__=='__main__':unittest.main()

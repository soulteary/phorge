"""Actual host source/CLI checks and rejection controls; no SQL or daemon execution."""
import copy,io,json,sys,tarfile,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2];sys.path.insert(0,str(ROOT/'tests/governance/native_rr_ci'))
import execution as e
from test_native_user_rr_mdl_gate import template,REV
ID='a'*64
def service():return [{'Id':ID,'Image':'sha256:'+'b'*64,'Config':{'Image':'mysql:8.4.13'},'State':{'Running':True},'HostConfig':{'NetworkMode':'github_network_owned','Privileged':False,'PidMode':'','IpcMode':'private','Binds':None,'PortBindings':{'3306/tcp':[{'HostIp':'127.0.0.1','HostPort':'3306'}]}}}]
class Controls(unittest.TestCase):
 def test_owned_service_shape_and_network_negatives(self):
  self.assertTrue(e.service_identity(service(),ID)['loopback_only'])
  for change in ('image','id','running','network','hostip','extra-port','privileged','bind'):
   data=service();r=data[0];h=r['HostConfig']
   if change=='image':r['Config']['Image']='mysql:8.0'
   elif change=='id':r['Id']='c'*64
   elif change=='running':r['State']['Running']=1
   elif change=='network':h['NetworkMode']='host'
   elif change=='hostip':h['PortBindings']['3306/tcp'][0]['HostIp']='0.0.0.0'
   elif change=='extra-port':h['PortBindings']['33060/tcp']=[]
   elif change=='privileged':h['Privileged']=True
   else:h['Binds']=['/host:/data']
   with self.subTest(change=change),self.assertRaises(ValueError):e.service_identity(data,ID)
 def test_real_archive_alias_and_whole_runtime_integrity(self):
  raw=io.BytesIO()
  with tarfile.open(fileobj=raw,mode='w')as tar:
   m=tarfile.TarInfo('code.php');body=b'<?php // synthetic';m.size=len(body);tar.addfile(m,io.BytesIO(body));alias=tarfile.TarInfo('alias.php');alias.type=tarfile.SYMTYPE;alias.linkname='code.php';tar.addfile(alias)
  data=raw.getvalue();expected,aliases=e.source_manifest(data)
  with tempfile.TemporaryDirectory()as tmp:
   root=Path(tmp);(root/'code.php').write_bytes(body);(root/'alias.php').symlink_to('code.php');self.assertEqual(2,e.whole_runtime(root,expected,aliases)['actual_files'])
   (root/'alias.php').unlink();(root/'alias.php').write_bytes(body)
   with self.assertRaises(ValueError):e.whole_runtime(root,expected,aliases)
   (root/'extra.sql').write_text('SELECT 1')
   with self.assertRaises(ValueError):e.whole_runtime(root,expected,{})
 def test_external_or_nested_archive_alias_rejected(self):
  for target in ('/outside','../outside','other.php'):
   raw=io.BytesIO()
   with tarfile.open(fileobj=raw,mode='w')as tar:
    alias=tarfile.TarInfo('alias.php');alias.type=tarfile.SYMTYPE;alias.linkname=target;tar.addfile(alias)
   with self.subTest(target=target),self.assertRaises(ValueError):e.source_manifest(raw.getvalue())
 def test_real_source_extractor_refuses_unsafe_types_before_writing(self):
  for change in ('absolute','traversal','hardlink','parent-alias','outside-alias'):
   raw=io.BytesIO()
   with tarfile.open(fileobj=raw,mode='w')as tar:
    target=tarfile.TarInfo('target.php');target.size=1;tar.addfile(target,io.BytesIO(b'x'))
    member=tarfile.TarInfo('/escape.php'if change=='absolute'else'../escape.php'if change=='traversal'else'link.php')
    if change=='hardlink':member.type=tarfile.LNKTYPE;member.linkname='target.php'
    elif change in ('parent-alias','outside-alias'):member.type=tarfile.SYMTYPE;member.linkname='target.php'if change=='parent-alias'else'../../escape'
    else:member.size=1
    tar.addfile(member,None if member.islnk()or member.issym()else io.BytesIO(b'y'))
    if change=='parent-alias':child=tarfile.TarInfo('link.php/child.php');child.size=1;tar.addfile(child,io.BytesIO(b'z'))
   with tempfile.TemporaryDirectory()as tmp:
    root=Path(tmp)
    with self.subTest(change=change),self.assertRaises(ValueError):e.extract_current_archive(raw.getvalue(),root)
    self.assertEqual([],list(root.iterdir()))
 def test_actual_current_pure_inventory_and_typed_outcome(self):
  ids=e.pure_inventory(ROOT);self.assertEqual(282,len(ids));data={'passed':True,'runtime':'8.5.13','expected_methods':282,'results':[{'id':i,'result':'pass'}for i in ids]};self.assertTrue(e.validate_pure(data,ids)['passed'])
  for change in ('bool','duplicate','failed','old-count','stale-280','stale-276'):
   bad=copy.deepcopy(data)
   if change=='bool':bad['expected_methods']=True
   elif change=='duplicate':bad['results'][0]=bad['results'][1]
   elif change=='old-count':bad['expected_methods']=277
   elif change=='stale-276':bad['expected_methods']=276
   elif change=='stale-280':bad['expected_methods']=280
   else:bad['results'][0]['result']='fail'
   with self.subTest(change=change),self.assertRaises(ValueError):e.validate_pure(bad,ids)
 def test_five_current_media_methods_are_not_omitted_from_real_inventory(self):
  ids=e.pure_inventory(ROOT)
  additions={
   'PhorgeGovernanceMediaExchangeServiceTestCase::testIsolationRowsKeepExactNamesAndKnownNativeValues',
   'PhorgeGovernanceMediaFileGuardTestCase::testDisabledFileGuardDeniesBeforeInterpretingRouteOrIdentity',
   'PhorgeGovernanceMediaFileGuardTestCase::testPrivateResponsesDisableCachingAndConstrainActiveContent',
   'PhorgeGovernanceMediaHTTPProtocolTestCase::testDisabledCandidateEndpointsDenyWithoutCreatingAuthority',
   'PhorgeGovernanceMediaHTTPProtocolTestCase::testDisabledHTTPPreservesNativeCredentialsAndOmitsBootstrap'}
  self.assertTrue(additions<=ids)
  self.assertEqual(additions,e.CURRENT_PURE_ADDITIONS)
  for removed in additions|e.DIRECTORY_PURE_ADDITIONS:
   data={'passed':True,'runtime':'8.5.13','expected_methods':282,
         'results':[{'id':i,'result':'pass'}for i in ids if i!=removed]}
   with self.subTest(removed=removed),self.assertRaises(ValueError):e.validate_pure(data,ids)
  # The historical280 prefix/success bit cannot replace all current282 IDs.
  old=ids-additions
  with self.assertRaises(ValueError):e.validate_pure({'passed':True,'runtime':'8.5.13',
   'expected_methods':280,'results':[{'id':i,'result':'pass'}for i in old]},ids)
 def test_unchanged_count_cannot_replace_original_or_new_method(self):
  import re
  text=(ROOT/'tests/governance/run.php').read_text()
  classes=re.findall(r"'(Phorge[A-Za-z0-9_]+TestCase)'",text.split('$classes = array(',1)[1].split(');',1)[0])
  with tempfile.TemporaryDirectory()as tmp:
   root=Path(tmp);runner=root/'tests/governance/run.php';runner.parent.mkdir(parents=True);runner.write_text(text)
   files={}
   for cls in classes:
    original=next((ROOT/'src').rglob(cls+'.php'));target=root/'src'/original.name
    target.parent.mkdir(exist_ok=True);target.write_text(original.read_text());files[cls]=target
   self.assertEqual(282,len(e.pure_inventory(root)))
   for identity in ('PhorgeGovernanceDirectoryReaderTestCase',
                    'PhorgeGovernanceMediaExchangeServiceTestCase',
                    'PhorgeOrganizationRequestTestCase'):
    target=files[identity];original=target.read_text()
    method=('testIsolationRowsKeepExactNamesAndKnownNativeValues'if 'MediaExchange'in identity
            else re.search(r'public\s+function\s+(test[A-Za-z0-9_]+)\s*\(',original).group(1))
    target.write_text(original.replace(method,'testUnreviewedReplacement',1))
    with self.subTest(identity=identity),self.assertRaises(ValueError):e.pure_inventory(root)
    target.write_text(original)
 def test_cleanup_unknown_and_bool_are_never_zero(self):
  data=template();self.assertTrue(e.cleanup_proved(data,REV));data['passed']=False;self.assertTrue(e.cleanup_proved(data,REV))
  for target,key,value in (('cleanup','ownedSchemasRemoved',True),('worker_cleanup','workers_registered',False),('worker_cleanup','live_processes_after_cleanup',1)):
   bad=copy.deepcopy(data);bad[target][key]=value
   with self.subTest(key=key):self.assertFalse(e.cleanup_proved(bad,REV))
  self.assertFalse(e.cleanup_proved({},REV))
  for change in ('missing-native','child-session','final-unknown','bool-zero','wrong-source'):
   bad=copy.deepcopy(data)
   if change=='missing-native':bad['native']=None
   elif change=='child-session':bad['native']['local_cleanup'][0]['owned_SQL_sessions_ended']=False
   elif change=='final-unknown':bad['native']['final_owned_cleanup']['cleanup_proved']=False
   elif change=='bool-zero':bad['native']['local_cleanup'][1]['pidfd_remaining']=False
   else:bad['native']['source_revision']='b'*40
   with self.subTest(change=change):self.assertFalse(e.cleanup_proved(bad,REV))
 def test_real_strict_CLI_matches_and_stderr_or_mismatch_refused(self):
  data=template()
  patches=[key.split(':',1)[1]for key in data['source_restore']['patch_audit']['migration_source_hashes']]
  with tempfile.TemporaryDirectory()as tmp:
   path=Path(tmp)/'receipt.json';path.write_text(json.dumps(data));safe=io.StringIO()
   with patch.object(e.sys,'stderr',safe):accepted,raw=e.strict_accept(ROOT,data,REV,path)
   self.assertEqual(safe.getvalue(),'');self.assertEqual(21,accepted['checks']);self.assertEqual(accepted,json.loads(raw))
   for stderr,stdout,code in ((b'warning',raw,0),(b'',b'{}',0),(b'',raw,1)):
    result=type('Result',(),dict(returncode=code,stderr=stderr,stdout=stdout))()
    safe=io.StringIO()
    with patch.object(e.subprocess,'run',return_value=result),patch.object(e.sys,'stderr',safe),self.assertRaises(ValueError):e.strict_accept(ROOT,data,REV,path)
    comparison=json.loads(safe.getvalue());self.assertTrue(comparison['comparison_DATA_only'])
    self.assertFalse(comparison['qualification']);self.assertFalse(comparison['root_cause_proved'])
    self.assertTrue(all(row['equal']for row in comparison['fields'].values()))
   changed=copy.deepcopy(data);changed['source_restore']['current_table_count']=552
   changed['source_restore']['patch_audit'].update(source_patch_count=1576,applied_patch_count=1576,migrations_executed=39)
   safe=io.StringIO()
   with patch.object(e.sys,'stderr',safe),self.assertRaises(ValueError):e.strict_accept(ROOT,changed,REV,path)
   comparison=json.loads(safe.getvalue());self.assertEqual(comparison['fields']['current_table_count']['observed'],552)
   self.assertEqual(comparison['fields']['patch_audit.migrations_executed']['observed'],39)
   self.assertFalse(comparison['root_cause_proved'])  # Synthetic numbers, no actual SQL qualification.
   secret='DO_NOT_EMIT_DSN_PROFILE_SQL_SECRET'
   for value in (secret,True,1<<100,[secret],{secret:secret},b'secret'):
    changed=copy.deepcopy(data);restore=changed['source_restore'];restore['current_table_count']=value;restore[secret]=secret
    restore['quickstart_sha256']=secret;restore['patch_audit']['migration_source_hashes']={secret:secret}
    comparison=e.restore_comparison_DATA(changed,patches);encoded=json.dumps(comparison)
    self.assertNotIn(secret,encoded);self.assertLessEqual(len(encoded.encode()),4096)
    self.assertIsNone(comparison['fields']['current_table_count']['observed'])
    self.assertFalse(comparison['fields']['current_table_count']['type_valid'])
    self.assertFalse(comparison['keyset_equal']['source_restore']);self.assertFalse(comparison['keyset_equal']['migration_source_hashes'])
   for value in (None,[],secret):
    changed=copy.deepcopy(data);changed['source_restore']=value
    comparison=e.restore_comparison_DATA(changed,patches)
    self.assertFalse(any(comparison['keyset_equal'].values()));self.assertNotIn(secret,json.dumps(comparison))
   changed=copy.deepcopy(data);del changed['source_restore']['current_table_count']
   comparison=e.restore_comparison_DATA(changed,patches)
   self.assertFalse(comparison['keyset_equal']['source_restore']);self.assertIsNone(comparison['fields']['current_table_count']['observed'])
   changed=copy.deepcopy(data);changed['passed']=False;safe=io.StringIO()
   with patch.object(e.sys,'stderr',safe),self.assertRaises(ValueError):e.strict_accept(ROOT,changed,REV,path)
   comparison=json.loads(safe.getvalue());self.assertTrue(all(row['equal']for row in comparison['fields'].values()))
   self.assertFalse(comparison['root_cause_proved'])  # Earlier non-restore failure is not misdiagnosed.
   changed=copy.deepcopy(data);changed['source_restore']['patch_audit']['current_patch_ledger_complete']=1
   self.assertFalse(e.restore_comparison_DATA(changed,patches)['fields']['patch_audit.current_patch_ledger_complete']['type_valid'])
   original=ValueError('original strict refusal')
   def refuse(*args,**kwargs):raise original
   gate=type('Gate',(),{'validate':staticmethod(refuse),'completion':type('Completion',(),{'PATCHES':patches})})()
   spec=type('Spec',(),{'loader':type('Loader',(),{'exec_module':staticmethod(lambda module:None)})()})()
   for failure in ('projector','stderr'):
    with patch.object(e.importlib.util,'spec_from_file_location',return_value=spec),patch.object(e.importlib.util,'module_from_spec',return_value=gate):
     with patch.object(e,'restore_comparison_DATA',side_effect=RuntimeError('diagnostic refused'))if failure=='projector'else patch.object(e.sys,'stderr',type('Sink',(),{'write':staticmethod(lambda raw:(_ for _ in ()).throw(OSError('sink refused')))})()):
      with self.assertRaises(ValueError)as caught:e.strict_accept(ROOT,data,REV,path)
      self.assertIs(caught.exception,original)
  # Independent transport/pinning fixture, not a CURRENT metadata/SQL proof.
  with tempfile.TemporaryDirectory()as tmp:
   base=Path(tmp).resolve();code=base/'checkout';stage=base/'staged';code.mkdir();stage.mkdir()
   v2={'schema':'native-user-RR-current-restore-metadata-v2','scope':'same-native-User-writer-RR-MDL-current-restore-metadata-only','passed':True,'source_revision':REV,'checks':21,'SQL_obligations':12,'native_children':2,'current_restore_metadata_verified':True,'legacy_reference_validated':True,'raw_v1_accepted':False,'raw_receipt_modified':False,'Task_physical_schema_verified':False,'independent_Task_schema_before_after_verified':False,'SQL_executed_by_validator':False,'source_owner_issued':False,'production_authority':False,'legacy_reference':{'checks':21}}
   v2.update(profile='RR-current-restore-metadata-v2',legacy_reference_only=True,raw_v1_profile_qualified=False,Task_empty_tables_verified=False,Task_config_epoch_verified=False)
   program=('import json,argparse\nfrom pathlib import Path\nRESULT='+repr(v2)+'\n'
    'def validate(data,root,*,source_revision):\n'
    ' if source_revision!=RESULT["source_revision"] or data["source_restore"]["current_table_count"]!=552: raise ValueError("wire fixture refused")\n'
    ' return RESULT\n'
    'if __name__=="__main__":\n'
    ' p=argparse.ArgumentParser();p.add_argument("--receipt",required=True);p.add_argument("--source-revision",required=True);p.add_argument("--source-root",required=True);a=p.parse_args()\n'
    ' print(json.dumps(validate(json.loads(Path(a.receipt).read_text()),Path(a.source_root),source_revision=a.source_revision)))\n').encode()
   committed={name:(ROOT/name).read_bytes()if name in e.CURRENT_RESTORE_ASSETS else program for name in e.CURRENT_RESTORE_PATHS}
   for directory in (code,stage):
    for name,body in committed.items():
     target=directory/name;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(body)
   read_committed=lambda name:committed[name]
   binding=e.current_restore_inputs(code,stage,REV,read_committed)
   self.assertEqual(set(binding['files']),e.CURRENT_RESTORE_PATHS)
   raw_data=copy.deepcopy(data);raw_data['source_restore']['current_table_count']=552
   receipt_path=base/'raw.json';receipt_path.write_text(json.dumps(raw_data));original_raw=receipt_path.read_bytes()
   def call(bind=binding,source=stage,revision=REV,checkout=code):
    return e.strict_accept_current_restore_v2(source,raw_data,revision,receipt_path,bind,code_root=checkout,read_committed=read_committed)
   with patch.object(e.importlib.util,'spec_from_file_location',side_effect=AssertionError('verified new CLI must compile raw, not cached bytecode')):
    result,raw=call()
   self.assertEqual(result,v2);self.assertEqual(json.loads(raw),v2);self.assertEqual(receipt_path.read_bytes(),original_raw)
   wrong=base/'wrong-stage';wrong.mkdir()
   for mutation in ('cli_hash','root','head','stage','extra_path','bool_size','committed_bytes'):
    bad=copy.deepcopy(binding);kwargs={}
    if mutation=='cli_hash':bad['files'][e.CURRENT_RESTORE_CLI]['sha256']='0'*64
    elif mutation=='root':kwargs['checkout']=stage
    elif mutation=='head':kwargs['revision']='b'*40
    elif mutation=='stage':kwargs['source']=wrong
    elif mutation=='extra_path':bad['files']['src/unreviewed.py']=bad['files'][e.CURRENT_RESTORE_CLI]
    elif mutation=='bool_size':bad['files'][e.CURRENT_RESTORE_CLI]['bytes']=True
    else:committed[e.CURRENT_RESTORE_CLI]=program+b'\n'
    with self.subTest(binding=mutation),patch.object(e.subprocess,'run',side_effect=AssertionError('rejected input must precede CLI')),self.assertRaises(ValueError):call(bind=bad,**kwargs)
    committed[e.CURRENT_RESTORE_CLI]=program
   with self.assertRaises(ValueError):e.current_restore_inputs(code,stage,REV,lambda name:b'wrong committed blob')
   (stage/'.git').mkdir()
   with self.assertRaises(ValueError):call()
   (stage/'.git').rmdir()
   original_run=e.subprocess.run
   def drift_after_cli(*args,**kwargs):
    actual=original_run(*args,**kwargs);(stage/e.CURRENT_RESTORE_CLI).write_bytes(program+b'\n');return actual
   with patch.object(e.subprocess,'run',side_effect=drift_after_cli),self.assertRaises(ValueError):call()
   (stage/e.CURRENT_RESTORE_CLI).write_bytes(program)
   real_verify=e.verify_current_restore_inputs;barriers=[]
   def drift_before_cli(*args,**kwargs):
    barriers.append(True)
    if len(barriers)==2:(stage/e.CURRENT_RESTORE_CLI).write_bytes(program+b'\n')
    return real_verify(*args,**kwargs)
   with patch.object(e,'verify_current_restore_inputs',side_effect=drift_before_cli),patch.object(e.subprocess,'run',side_effect=AssertionError('before-CLI drift must prevent CLI')),self.assertRaises(ValueError):call()
   (stage/e.CURRENT_RESTORE_CLI).write_bytes(program)
   for mutation in ('bytes','root','cli_reply'):
    if mutation=='bytes':receipt_path.write_text(json.dumps(dict(raw_data,passed=False)))
    if mutation=='root':(code/e.CURRENT_RESTORE_CLI).write_bytes(program+b'\n')
    fake=type('Reply',(),{'returncode':0,'stdout':b'{"schema":"legacy-v1","passed":true}','stderr':b''})()
    with patch.object(e.subprocess,'run',return_value=fake)as invoked,self.subTest(drift=mutation),self.assertRaises(ValueError):call()
    self.assertEqual(invoked.called,mutation=='cli_reply')
    receipt_path.write_bytes(original_raw);(code/e.CURRENT_RESTORE_CLI).write_bytes(program)
   for delta in ({'raw_v1_accepted':True},{'Task_physical_schema_verified':True},{'scope':'legacy-physical-profile'},{'unreviewed':'never forward unknown data'}):
    changed=dict(v2,**delta);bad_program=program.replace(('RESULT='+repr(v2)).encode(),('RESULT='+repr(changed)).encode(),1)
    committed[e.CURRENT_RESTORE_CLI]=bad_program
    for directory in (code,stage):(directory/e.CURRENT_RESTORE_CLI).write_bytes(bad_program)
    bad_binding=e.current_restore_inputs(code,stage,REV,read_committed)
    with patch.object(e.subprocess,'run',side_effect=AssertionError('invalid scope must precede CLI')),self.subTest(scope=delta),self.assertRaises(ValueError):call(bind=bad_binding)
   committed[e.CURRENT_RESTORE_CLI]=program
   for directory in (code,stage):(directory/e.CURRENT_RESTORE_CLI).write_bytes(program)
  # Real current v2 consumer + unchanged legacy gate, on prepared Gitfree DATA.
  with tempfile.TemporaryDirectory()as tmp:
   base=Path(tmp).resolve();stage=base/'source';stage.mkdir()
   paths=set(e.CURRENT_RESTORE_PATHS)|{'scripts/governance/check_native_user_rr_mdl.py','scripts/governance/check_completion.py','resources/sql/quickstart.sql'}
   paths|={'resources/sql/autopatches/'+p for p in patches}
   for name in paths:
    target=stage/name;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes((ROOT/name).read_bytes())
   actual_committed={name:(ROOT/name).read_bytes()for name in e.CURRENT_RESTORE_PATHS}
   binding=e.current_restore_inputs(ROOT,stage,REV,lambda name:actual_committed[name])
   current=copy.deepcopy(data);restore=current['source_restore'];restore['current_table_count']=552
   restore['patch_audit'].update(source_patch_count=1576,applied_patch_count=1576,migrations_executed=39)
   restore['patch_audit']['migration_source_hashes']['phabricator:20261112.task.contentgrants.sql']=e.CURRENT_RESTORE_ASSETS['resources/sql/autopatches/20261112.task.contentgrants.sql']
   receipt=base/'actual-schema-synthetic-receipt.json';receipt.write_text(json.dumps(current));raw_before=receipt.read_bytes()
   accepted,cli=e.strict_accept_current_restore_v2(stage,current,REV,receipt,binding,code_root=ROOT,read_committed=lambda name:actual_committed[name])
   self.assertEqual(accepted,json.loads(cli));self.assertEqual(accepted['checks'],21);self.assertTrue(accepted['legacy_reference_only'])
   self.assertFalse(accepted['raw_v1_profile_qualified']);self.assertFalse(accepted['Task_physical_schema_verified']);self.assertFalse(accepted['source_owner_issued'])
   self.assertEqual(receipt.read_bytes(),raw_before);self.assertEqual(current['source_restore']['current_table_count'],552)
   with patch.object(e.sys,'stderr',io.StringIO()),self.assertRaises(ValueError):e.strict_accept(stage,current,REV,receipt)
if __name__=='__main__':unittest.main()

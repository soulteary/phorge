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
  ids=e.pure_inventory(ROOT);self.assertEqual(266,len(ids));data={'passed':True,'runtime':'8.5.12','expected_methods':266,'results':[{'id':i,'result':'pass'}for i in ids]};self.assertTrue(e.validate_pure(data,ids)['passed'])
  for change in ('bool','duplicate','failed'):
   bad=copy.deepcopy(data)
   if change=='bool':bad['expected_methods']=True
   elif change=='duplicate':bad['results'][0]=bad['results'][1]
   else:bad['results'][0]['result']='fail'
   with self.subTest(change=change),self.assertRaises(ValueError):e.validate_pure(bad,ids)
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
  with tempfile.TemporaryDirectory()as tmp:
   path=Path(tmp)/'receipt.json';path.write_text(json.dumps(data));accepted,raw=e.strict_accept(ROOT,data,REV,path);self.assertEqual(21,accepted['checks']);self.assertEqual(accepted,json.loads(raw))
   for stderr,stdout,code in ((b'warning',raw,0),(b'',b'{}',0),(b'',raw,1)):
    result=type('Result',(),dict(returncode=code,stderr=stderr,stdout=stdout))()
    with patch.object(e.subprocess,'run',return_value=result),self.assertRaises(ValueError):e.strict_accept(ROOT,data,REV,path)
if __name__=='__main__':unittest.main()

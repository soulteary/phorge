"""Host-only fixture protocol controls; no DB, HTTP server or browser is started."""
import base64,json,os,pathlib,secrets,shutil,subprocess,tempfile,unittest
ROOT=pathlib.Path(__file__).resolve().parents[2]
COMMON=ROOT/'support/governance/organization/workbench-http-common.php'
class Controls(unittest.TestCase):
 def php(self,code,env=None,timeout=3):
  program='require '+json.dumps(str(COMMON))+';'+code
  return subprocess.run([shutil.which('php'),'-r',program],stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=timeout,env=dict(os.environ,**(env or {})),check=False)
 def config(self):return {'file_service':{'uri':'http://127.0.0.1:43211','token':'1'*64,'binary_sha256':'2'*64},'fixture_nonce':'a'*64,'owner':'b'*64,'native26_prerequisite_sha256':'c'*64,'quickstart_sha256':'d'*64,'source_revision':'e'*40,'origin':'http://localhost:43210','writer':{'host':'127.0.0.1','port':3306,'namespace':'governance_native_'+'a'*16,'user':'gov_c_writer_'+'a'*12,'password':'f'*48}}
 def check_config(self,value,valid):
  raw=json.dumps(value,separators=(',',':')).encode();encoded=base64.b64encode(raw).decode();code="$raw=base64_decode('"+encoded+"');try{$v=governance_workbench_http_config($raw,hash('sha256',$raw),str_repeat('d',64));echo 'accepted';}catch(Throwable$e){echo 'refused';}"
  result=self.php(code);self.assertEqual(result.returncode,0);self.assertEqual(result.stderr,b'');self.assertEqual(result.stdout,b'accepted'if valid else b'refused')
 def test_real_private_config_strict_origin_writer_and_types(self):
  self.check_config(self.config(),True)
  for field,value in [('origin','http://127.0.0.1:43210'),('origin','https://localhost:43210'),('source_revision','e'*39),('owner',True),('extra','unknown')]:
   c=self.config();c[field]=value;self.check_config(c,False)
  for field,value in [('port',True),('port',0),('host','public.example'),('user','root'),('namespace','phabricator'),('password','not-private-profile')]:
   c=self.config();c['writer'][field]=value;self.check_config(c,False)
 def test_actual_file_service_private_config_rejects_missing_foreign_or_malformed(self):
  for key,value in [('uri','http://public.example:43211'),('uri','http://127.0.0.1:65536'),('uri','http://127.0.0.1:0'),('token',True),('token','a'*63),('binary_sha256','G'*64),('extra','unknown')]:
   c=self.config();c['file_service'][key]=value;self.check_config(c,False)
  c=self.config();del c['file_service'];self.check_config(c,False)
 def test_actual_paired_file_starter_refuses_missing_binary_before_service_creation(self):
  pipeline=ROOT/'support/governance/completion/pipeline-gorge.php'
  code='class PhorgeGovernanceCompletionFixture{public function assertOwned(){}}require '+json.dumps(str(pipeline))+';try{governance_completion_start_gorge(new PhorgeGovernanceCompletionFixture(),"file",function(){throw new Exception("must-not-reach-ready");});echo "wrong";}catch(RuntimeException$e){echo $e->getMessage();}'
  result=self.php(code,{'PHORGE_GOVERNANCE_GORGE_FILE_BINARY':'','PHORGE_GOVERNANCE_GORGE_FILE_SHA256':''});self.assertEqual(result.returncode,0);self.assertEqual(result.stdout,b'completion_paired_gorge_binary_required');self.assertEqual(result.stderr,b'')
 def test_file_service_source_uses_disk_without_native_writer_credentials(self):
  source=(ROOT/'support/governance/completion/pipeline-gorge.php').read_text();file_branch=source.split("if ($kind === 'file') {",1)[1].split('} else {',1)[0]
  self.assertIn("GORGE_FILE_MYSQL_BLOB_MAX_SIZE",file_branch);self.assertIn("GORGE_FILE_LOCAL_DISK_PATH",file_branch);self.assertNotIn('getWriterConfig',file_branch);self.assertNotIn('MYSQL_USER',file_branch)
  driver=(ROOT/'support/governance/native-organization/test-native-organization-mysql.php').read_text();http=driver.split('if($workbench_http){',1)[1].split("}elseif($phase===",1)[0];self.assertLess(http.index("governance_completion_start_gorge($fixture,'file',$check)"),http.index('governance_workbench_http_hold('));self.assertIn('$file_service);',http)
  router=(ROOT/'support/governance/organization/workbench-http-router.php').read_text();self.assertIn("'gorge.file.uri'=>$config['file_service']['uri']",router);self.assertIn("'gorge.file.token'=>$config['file_service']['token']",router)
 def test_real_routes_and_encoded_traversal_denied(self):
  for path,valid in [('/file/data/key/PHID-FILE-synthetic/avatar.png',True),('/file/xform/profile/PHID-FILE-synthetic/key/',True),('/file/upload/',False),('/file/download/key/PHID-FILE-synthetic/name',False),('/file/data/%2e%2e/config/',False),('/people/organization/',True),('/people/organization/approvals/?approval=synthetic',True),('/auth/login/password:self/',True),('/res/abcd/test.js',True),('/typeahead/class/People/',True),('/config/',False),('/conduit/',False),('/people/organization/../../config/',False),('/people/organization/%2e%2e/config/',False),('/people/organization/%252e%252e/config/',False)]:
   result=self.php('try{governance_workbench_http_route("localhost:43210",'+json.dumps(path)+',"http://localhost:43210");echo "accepted";}catch(Throwable$e){echo "refused";}');self.assertEqual(result.returncode,0);self.assertEqual(result.stderr,b'');self.assertEqual(result.stdout,b'accepted'if valid else b'refused')
  result=self.php('try{governance_workbench_http_route("other:43210","/","http://localhost:43210");}catch(Throwable$e){echo "refused";}');self.assertEqual(result.stdout,b'refused')
 def private(self):
  path=pathlib.Path(tempfile.gettempdir()).resolve()/('phorge-workbench-http-'+secrets.token_hex(12));path.mkdir(mode=0o700);self.addCleanup(lambda:shutil.rmtree(path,ignore_errors=True));return path
 def test_real_owned_journal_header_and_stable_FD_append(self):
  directory=self.private();owner='a'*64;path=directory/'journal.private.jsonl';path.write_bytes(('WB_HTTP_JOURNAL_V1 '+owner+'\n').encode());os.chmod(path,0o600)
  result=self.php('$j=governance_workbench_http_journal('+json.dumps(str(directory))+',str_repeat("a",64));governance_workbench_http_record($j,array("phase"=>"host-control"));fclose($j["stream"]);echo "recorded";');self.assertEqual(result.returncode,0);self.assertEqual(result.stderr,b'');self.assertEqual(result.stdout,b'recorded');self.assertEqual(json.loads(path.read_bytes().splitlines()[1]),{'phase':'host-control'})
 def test_foreign_header_symlink_fifo_refused_without_write(self):
  for kind in ('header','symlink','fifo'):
   directory=self.private();path=directory/'journal.private.jsonl'
   if kind=='header':path.write_bytes(b'FOREIGN');os.chmod(path,0o600)
   elif kind=='symlink':target=directory/'foreign';target.write_bytes(b'FOREIGN');path.symlink_to(target)
   else:os.mkfifo(path,0o600)
   before=path.lstat();result=self.php('try{governance_workbench_http_journal('+json.dumps(str(directory))+',str_repeat("a",64));echo "wrong";}catch(Throwable$e){echo "refused";}');self.assertEqual(result.returncode,0);self.assertEqual(result.stdout,b'refused');self.assertEqual(path.lstat().st_ino,before.st_ino)
   if kind=='header':self.assertEqual(path.read_bytes(),b'FOREIGN')
 def test_same_inode_header_payload_mutation_and_unexpected_append_refused(self):
  for mutation in ('header','payload','append'):
   with self.subTest(mutation=mutation):
    directory=self.private();path=directory/'journal.private.jsonl';path.write_bytes(('WB_HTTP_JOURNAL_V1 '+'a'*64+'\n').encode());os.chmod(path,0o600)
    prefix='$d='+json.dumps(str(directory))+';$p=$d."/journal.private.jsonl";$j=governance_workbench_http_journal($d,str_repeat("a",64));governance_workbench_http_record($j,array("phase"=>"initial"));'
    changes={'header':'$x=file_get_contents($p);$x[0]="X";file_put_contents($p,$x);','payload':'$x=file_get_contents($p);$x[strlen($x)-3]="X";file_put_contents($p,$x);','append':'file_put_contents($p,"UNEXPECTED",FILE_APPEND);'}
    result=self.php(prefix+changes[mutation]+'$before=file_get_contents($p);try{governance_workbench_http_record($j,array("private_canary"=>"must-not-append"));echo "wrong";}catch(Throwable$e){echo file_get_contents($p)===$before?"refused-unchanged":"changed";}fclose($j["stream"]);')
    self.assertEqual(result.returncode,0);self.assertEqual(result.stdout,b'refused-unchanged');self.assertNotIn(b'must-not-append',path.read_bytes())

 def test_journal_replacement_refused_and_foreign_untouched(self):
  directory=self.private();path=directory/'journal.private.jsonl';path.write_bytes(('WB_HTTP_JOURNAL_V1 '+'a'*64+'\n').encode());os.chmod(path,0o600)
  result=self.php('$d='+json.dumps(str(directory))+';$j=governance_workbench_http_journal($d,str_repeat("a",64));rename($d."/journal.private.jsonl",$d."/original");file_put_contents($d."/journal.private.jsonl","FOREIGN");chmod($d."/journal.private.jsonl",0600);try{governance_workbench_http_record($j,array("phase"=>"x"));echo "wrong";}catch(Throwable$e){echo "refused";}fclose($j["stream"]);');self.assertEqual(result.returncode,0);self.assertEqual(result.stdout,b'refused');self.assertEqual(path.read_bytes(),b'FOREIGN')
 def test_private_channel_commands_strict_release_scope(self):
  for command,valid in [({'action':'observe','owner':'a'*64,'phase':'reviewer-reviewed'},True),({'action':'release','owner':'a'*64,'phase':'servers-stopped'},True),({'action':'release','owner':'a'*64,'phase':'initial'},False),({'action':'observe','owner':'b'*64,'phase':'initial'},False),({'action':'observe','owner':'a'*64,'phase':'unknown'},False)]:
   raw=base64.b64encode(json.dumps(command).encode()).decode();result=self.php('try{governance_workbench_http_command(base64_decode("'+raw+'"),str_repeat("a",64));echo "accepted";}catch(Throwable$e){echo "refused";}');self.assertEqual(result.returncode,0);self.assertEqual(result.stdout,b'accepted'if valid else b'refused')
 def test_router_cli_and_secret_redaction_no_server(self):
  router=ROOT/'support/governance/organization/workbench-http-router.php';result=subprocess.run([shutil.which('php'),str(router)],stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=3,env=dict(os.environ,PHORGE_GOVERNANCE_WORKBENCH_HTTP_CONFIG='PRIVATE_CANARY'));self.assertEqual(result.returncode,0);self.assertIn(b'owned_workbench_http_unavailable',result.stdout);self.assertNotIn(b'PRIVATE_CANARY',result.stdout+result.stderr)
 def test_native_source_wiring_no_fake_session_or_N09_router_change(self):
  hold=(ROOT/'support/governance/organization/workbench-http-hold.php').read_text();router=(ROOT/'support/governance/organization/workbench-http-router.php').read_text();driver=(ROOT/'support/governance/native-organization/test-native-organization-mysql.php').read_text()
  self.assertNotIn('establishSession(',hold);self.assertNotIn('governance_native_request(',hold);self.assertIn('PhabricatorAuthPassword::initializeNewPassword',hold);self.assertIn('PhabricatorPasswordAuthProvider',hold);self.assertIn('web_session_counts',hold);self.assertIn('LiskDAO::closeAllConnections()',hold);self.assertIn('information_schema.PROCESSLIST',hold)
  self.assertIn('buildRequest()',router);self.assertIn('processRequest(',router);self.assertIn('$request->validateCSRF()',router);self.assertNotIn('setUser(',router);self.assertIn("'governance.media-exchange.enabled'=>false",router);self.assertIn("'governance.organization.enabled'=>true",router);self.assertIn('workbench-http-parent-or-live-writer-cleanup-unproved',driver)
if __name__=='__main__':unittest.main()

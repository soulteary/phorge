"""No SQL: actual PHP projection and exact child journal refusal boundaries."""
import json,pathlib,subprocess,tempfile,types,unittest
from test_project_description_ci import ci,ROOT
class DescriptionDiagnostics(unittest.TestCase):
 def vector(self):return {'schema':'N05-description-native-failure-v1','phase':'native-cases','family':'runtime','errno':None,'locations':[{'source':'cases','line':81}],'execution_authorized':False,'SQL_execution_status':'unknown'}
 def wire(self,v=None):return b'description_qualification_refused_retain_no_retry\n'+json.dumps(self.vector()if v is None else v,separators=(',',':')).encode()+b'\n'
 def test_actual_null_entry_emits_strict_safe_diagnostic_without_source_IO(self):
  r=subprocess.run(['php','-n',str(ROOT/'support/governance/project-description-qualification/native.php'),'/does-not-exist','/does-not-exist'],capture_output=True)
  self.assertEqual(r.returncode,1);self.assertEqual(r.stdout,b'');v=ci.diagnostic(r.stderr);self.assertEqual(v['phase'],'entry');self.assertEqual(v['locations'][0]['source'],'helper')
 def test_actual_php_exception_secret_message_arguments_and_path_are_absent(self):
  path=json.dumps(str(ROOT/'support/governance/project-description-qualification/native.php'))
  php="define('N05_DESCRIPTION_QUALIFICATION_LIBRARY_ONLY',true);require "+path+";ndphase('private-token');function secret_call($secret){throw new RuntimeException($secret);}try{secret_call('SECRET_SQL_PHID_token_password');}catch(Throwable $e){echo json_encode(ndfailure($e,'/SECRET_PRIVATE_PATH'));}"
  r=subprocess.run(['php','-n','-r',php],capture_output=True);self.assertEqual(r.returncode,0);self.assertEqual(r.stderr,b'');self.assertNotIn(b'SECRET',r.stdout);self.assertNotIn(str(ROOT).encode(),r.stdout)
  v=json.loads(r.stdout);self.assertEqual(v['phase'],'entry');self.assertTrue(all(x=={'source':'other','line':0}for x in v['locations']))
 def test_stderr_mixing_duplicate_json_and_private_fields_refuse(self):
  for raw in [b'warning\n'+self.wire(),self.wire()+b'warning\n',self.wire()[:-1],self.wire().replace(b'"phase":',b'"phase":"entry","phase":'),self.wire().replace(b'{"schema":',b'{"secret":"TOKEN","schema":')]:
   with self.assertRaises((ValueError,TypeError)):ci.diagnostic(raw)
  for k,value in [('phase','private'),('family','PrivateClass'),('errno',1644),('errno','private'),('locations',[{'source':'/private/path','line':1}]),('locations',[{'source':'other','line':2}]),('locations',[{'source':'cases','line':True}]),('locations',[{'source':'cases','line':1}]*5)]:
   v=self.vector();v[k]=value
   with self.assertRaises(ValueError):ci.diagnostic(self.wire(v))
 def journal(self,p):
  p=pathlib.Path(p).resolve();j=types.SimpleNamespace(path=p,n=1);argv=['/usr/bin/php','fixed-native','fixed-root','fixed-profile']
  records={'01-intent.json':json.dumps({'argv':argv,'signals_retry_cleanup':False}).encode(),'01-started.json':b'{"pid":123}','01-terminal.json':b'{"known_terminal":true,"exit_code":1}','01.stdout.private':b'','01.stderr.private':self.wire()}
  for name,b in records.items():q=p/name;q.write_bytes(b);q.chmod(0o600)
  return j,argv,records
 def test_observer_accepts_only_exact_known_failed_invocation(self):
  with tempfile.TemporaryDirectory()as tmp:
   j,argv,_=self.journal(tmp);self.assertEqual(ci.observed_failure(j,1,argv),self.vector())
   for wrong in [0,2,True]:
    with self.assertRaises(ValueError):ci.observed_failure(j,wrong,argv)
   with self.assertRaises(ValueError):ci.observed_failure(j,1,argv+['other'])
 def test_unknown_zero_wrong_terminal_or_raw_stdout_cannot_publish(self):
  for name,raw in [('01-intent.json',b'{"argv":["/usr/bin/php","fixed-native","fixed-root","fixed-profile"],"signals_retry_cleanup":0}'),('01-terminal.json',b'{"known_terminal":false,"exit_code":1}'),('01-terminal.json',b'{"known_terminal":true,"exit_code":0}'),('01-terminal.json',b'{"known_terminal":true,"exit_code":true}'),('01-terminal.json',b'{"known_terminal":true,"exit_code":1,"raw":"SECRET"}'),('01-terminal.json',b'{"known_terminal":true,"known_terminal":true,"exit_code":1}'),('01-unknown.json',b'{}'),('01.stdout.private',b'SECRET')]:
   with tempfile.TemporaryDirectory()as tmp:
    j,argv,_=self.journal(tmp);q=j.path/name;q.write_bytes(raw);q.chmod(0o600)
    with self.assertRaises(ValueError):ci.observed_failure(j,1,argv)
 def test_wrapper_never_retries_or_publishes_unvalidated_failure(self):
  with tempfile.TemporaryDirectory()as tmp:
   j,argv,_=self.journal(tmp);j.n=0;calls=[]
   def run(args,cwd,timeout):calls.append(args);j.n=1;raise ValueError('SECRET_RAW')
   j.run=run
   with self.assertRaisesRegex(ValueError,'SECRET_RAW'):ci.native_cases(j,argv,j.path)
   self.assertEqual(len(calls),1);self.assertEqual(ci.failure()['native_failure'],self.vector())
   (j.path/'01-unknown.json').write_text('{}');(j.path/'01-unknown.json').chmod(0o600);j.n=0
   with self.assertRaises(ValueError):ci.native_cases(j,argv,j.path)
   self.assertNotIn('native_failure',ci.failure());ci.NATIVE_FAILURE=None
if __name__=='__main__':unittest.main()

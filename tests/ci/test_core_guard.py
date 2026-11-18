import importlib.util, pathlib, unittest
ROOT=pathlib.Path(__file__).resolve().parents[2]
s=importlib.util.spec_from_file_location('core_guard',ROOT/'scripts/ci/core_guard.py');guard=importlib.util.module_from_spec(s);s.loader.exec_module(guard)
class TierPolicy(unittest.TestCase):
 def test_PR_and_main_are_core_manual_and_calls_full(self):
  a='a'*40;b='b'*40
  self.assertEqual(guard.plan('pull_request',a,b)['mode'],'core');self.assertEqual(guard.plan('pull_request',a,b)['head'],b)
  self.assertEqual(guard.plan('push',a)['mode'],'core');self.assertEqual(guard.plan('workflow_dispatch',a)['mode'],'full')
  for event in ('push','workflow_dispatch'):self.assertEqual(guard.plan(event,a,called_head=a),{'mode':'full','head':a,'reason':'release_workflow_call'})
 def test_unknown_events_wrong_head_and_PR_call_refuse(self):
  for args in [('schedule','a'*40),('push','main'),('push','a'*40,'','b'*40),('pull_request','a'*40,'b'*40,'a'*40),('push','a'*40,'',False)]:
   with self.subTest(args=args),self.assertRaises(ValueError):guard.plan(*args)
 def test_core_cannot_claim_full_and_full_cannot_skip(self):
  for mode,heavy in [('core','skipped'),('full','success')]:
   v={'plan':'success','core':'success',**dict.fromkeys(('native','unit0','unit1','tooling'),heavy)};self.assertTrue(guard.aggregate(mode,v))
   for key in v:
    for result in ('failure','cancelled','skipped'if v[key]=='success'else'success',''):
     changed=dict(v);changed[key]=result
     with self.subTest(mode=mode,key=key,result=result),self.assertRaises(ValueError):guard.aggregate(mode,changed)
 def test_unknown_missing_or_extra_aggregate_results_refuse(self):
  v={'plan':'success','core':'success',**dict.fromkeys(('native','unit0','unit1','tooling'),'success')}
  for mode,rows in [('optional',v),('full',{k:x for k,x in v.items()if k!='core'}),('full',{**v,'unknown':'success'})]:
   with self.assertRaises(ValueError):guard.aggregate(mode,rows)
 def test_native_guard_reuses_real_tests_and_disallows_deployment(self):
  source=(ROOT/'scripts/ci/core_guard.php').read_text();self.assertIn("new PhabricatorConfigDefaultSource()",source);self.assertIn("getDefault()!==false",source);self.assertIn("getLocked()!==true",source);self.assertIn("$test->$method()",source);self.assertIn("$count<1",source);self.assertIn("catch(Throwable $error)",source);self.assertNotIn('eval(',source);self.assertNotIn('new PDO',source)
  for method in ('testDisabledHTTPPreservesNativeCredentialsAndOmitsBootstrap','testDisabledCandidateEndpointsDenyWithoutCreatingAuthority','testDisabledFileGuardDeniesBeforeInterpretingRouteOrIdentity','testPrivateResponsesDisableCachingAndConstrainActiveContent'):
   self.assertIn(method,source)
if __name__=='__main__':unittest.main()

class StrictOriginalUnittest(unittest.TestCase):
 def run_fixture(self,body):
  import tempfile,uuid,contextlib,io
  with tempfile.TemporaryDirectory()as folder:
   p=pathlib.Path(folder);name='test_core_'+uuid.uuid4().hex+'.py';(p/name).write_text('import unittest\n'+body)
   with contextlib.redirect_stderr(io.StringIO()):return guard.strict_unittest(p,name)
 def test_actual_original_suite_success(self):
  self.assertEqual(self.run_fixture('class T(unittest.TestCase):\n def test_ok(self):self.assertEqual(1,1)\n'),1)
 def test_actual_skip_expectedfailure_and_failure_refuse(self):
  for body in ['class T(unittest.TestCase):\n @unittest.skip("synthetic")\n def test_skip(self):pass\n','class T(unittest.TestCase):\n @unittest.expectedFailure\n def test_x(self):self.fail()\n','class T(unittest.TestCase):\n def test_fail(self):self.fail()\n']:
   with self.assertRaises(ValueError):self.run_fixture(body)
 def test_empty_selection_refuses(self):
  with self.assertRaises(ValueError):self.run_fixture('class T(unittest.TestCase):pass\n')

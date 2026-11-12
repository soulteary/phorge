"""Offline recipe controls only: detached receipts are never actual SQL evidence."""
import copy,importlib.util,json,pathlib,subprocess,unittest
ROOT=pathlib.Path(__file__).resolve().parents[2]
def load(path,name):
 s=importlib.util.spec_from_file_location(name,ROOT/path);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
ci=load('support/governance/project-description-ci/ci.py','description_ci')
class DescriptionCI(unittest.TestCase):
 def test_one_profile_pin_successor_preserves_all_other_template_bytes(self):
  for kind,path in [('cases','support/governance/project-description-qualification/native.php'),('fixture','support/governance/project-description-ci/fixture.php')]:
   b=(ROOT/path).read_bytes();n=ci.successor(b,'1'*64,kind)
   self.assertNotEqual(b,n);self.assertEqual(n.count(b"='"+b'1'*64+b"';"),1)
   with self.assertRaises(ValueError):ci.successor(b+b,'1'*64,kind)
   for pin in [None,True,'../x','f'*63,'G'*64]:
    with self.assertRaises(ValueError):ci.successor(b,pin,kind)
 def test_original_fixture_and_raw_entry_refuse_before_native_bootstrap(self):
  for p in ['support/governance/project-description-ci/fixture.php','support/governance/project-description-qualification/native.php']:
   r=subprocess.run(['php','-n',str(ROOT/p)],capture_output=True,text=True)
   self.assertEqual(r.returncode,1);self.assertEqual(r.stdout,'');self.assertIn('retain_no_retry' if p.endswith('fixture.php') else 'description_qualification_refused',r.stderr)
 def test_default_metadata_interface_cannot_accept_arbitrary_callback_or_truthy_flag(self):
  shared=load('support/governance/project-content-grants-ci/ci.py','description_shared_ci')
  pmod=load('support/governance/project-content-grants-ci/prerequisites/provision.py','description_shared_provision')
  for bad in [None,1,'true',{},lambda:None]:
   with self.assertRaises(ValueError):shared.execute(None,description_fixture=bad)
   with self.assertRaises(ValueError):pmod.sequence(None,None,None,None,description_fixture=bad)
 def test_failure_projection_contains_only_fixed_phase_and_no_exception_message(self):
  self.assertEqual(set(ci.failure()),{'schema','passed','phase','SQL_execution_status','retained','automatic_retry_or_cleanup','execution_authorized'})
  self.assertFalse(ci.failure()['passed']);self.assertFalse(ci.failure()['automatic_retry_or_cleanup']);self.assertEqual(ci.failure()['SQL_execution_status'],'unknown')
 def vector(self):
  positive={k:True for k in ['native_applyTransactions_committed','description_and_use_audit_persisted','security_and_other_fields_unchanged','native_publish_task_persisted','native_mail_and_feed_intent_persisted']};positive.update({k:False for k in ['native_EDIT_granted','publish_worker_executed','mail_feed_search_delivery_verified']})
  cases={k:(copy.deepcopy(positive)if k in ('user-positive','team-positive')else {'refused':True,'all_target_bytes_unchanged':True})for k in ci.CASES};cases['audit-fault-rollback'].update(real_SQL_trigger_rejected_use_audit=True,description_transaction_and_queue_rolled_back=True)
  return {'schema':'N05-native-description-SQL-cases-v1','cases':cases,'initial_capabilities':{'native_VIEW':True,'native_EDIT':False,'ordinary_account':True},**{k:False for k in ['execution_authorized','HTTP_verified','publish_worker_executed','mail_feed_search_delivery_verified','online_activation']}}
 def test_detached_receipt_needs_all_nine_exact_cases_and_does_not_certify_SQL(self):
  v=self.vector();self.assertEqual(ci.result_ok(v),v)
  for k in ci.CASES:
   bad=copy.deepcopy(v);del bad['cases'][k]
   with self.assertRaises(ValueError):ci.result_ok(bad)
  bad=copy.deepcopy(v);bad['cases']['unknown']={}
  with self.assertRaises(ValueError):ci.result_ok(bad)
 def test_detached_receipt_refuses_unknown_fields_and_nonboolean_capabilities(self):
  for where in ['top','positive','negative','capability']:
   bad=self.vector()
   if where=='top':bad['unknown']='private'
   elif where=='positive':bad['cases']['user-positive']['unknown']='private'
   elif where=='negative':bad['cases']['no-grant']['unknown']='private'
   else:bad['initial_capabilities']['native_VIEW']=1
   with self.assertRaises(ValueError):ci.result_ok(bad)
 def test_detached_receipt_cannot_upgrade_scope_or_hide_missing_audit_and_queue(self):
  for field in ['execution_authorized','HTTP_verified','publish_worker_executed','mail_feed_search_delivery_verified','online_activation']:
   bad=self.vector();bad[field]=True
   with self.assertRaises(ValueError):ci.result_ok(bad)
  for field in ['native_applyTransactions_committed','description_and_use_audit_persisted','security_and_other_fields_unchanged','native_publish_task_persisted','native_mail_and_feed_intent_persisted']:
   bad=self.vector();bad['cases']['user-positive'][field]=False
   with self.assertRaises(ValueError):ci.result_ok(bad)
  for field in ['real_SQL_trigger_rejected_use_audit','description_transaction_and_queue_rolled_back']:
   bad=self.vector();bad['cases']['audit-fault-rollback'][field]=False
   with self.assertRaises(ValueError):ci.result_ok(bad)
if __name__=='__main__':unittest.main()

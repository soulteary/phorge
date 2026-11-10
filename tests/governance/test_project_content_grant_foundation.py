"""Current native classes, pure value/admission tests; not a SQL/provider proof."""
import hashlib,json,subprocess,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
def php(code):
 pre="require "+json.dumps(str(ROOT/'support/runtime/src/__phutil_library_init__.php'))+";phutil_load_library("+json.dumps(str(ROOT/'src'))+");"
 p=subprocess.run(['php','-r',pre+code],cwd=ROOT,capture_output=True,text=True,timeout=20)
 if p.returncode:raise AssertionError(p.stderr)
 return json.loads(p.stdout)
class Foundation(unittest.TestCase):
 def test_explicit_subjects_description_only_and_bounded_requests(self):
  d=php(r'''
  $r=array('action'=>'grant','grant_id'=>null,'reason'=>'合成申请','subject_id'=>'PHID-USER-'.str_repeat('a',20),'subject_kind'=>'user','target_phid'=>'PHID-PROJ-'.str_repeat('b',20),'valid_for_seconds'=>3600);
  $valid=PhorgeProjectContentGrantRequest::submit($r);$n=0;
  foreach(array(array('subject_kind','department'),array('subject_kind','project'),array('subject_kind','parent-team'),array('valid_for_seconds',true),array('valid_for_seconds',0),array('valid_for_seconds',86401),array('grant_id',str_repeat('1',64)),array('target_phid','PHID-TASK-'.str_repeat('b',20)),array('reason',''))as$c){$x=$r;$x[$c[0]]=$c[1];try{PhorgeProjectContentGrantRequest::submit($x);throw new Exception('negative accepted');}catch(InvalidArgumentException$e){$n++;}}
  $team=$r;$team['subject_kind']='business-team';$team['subject_id']='business.engineering';$t=PhorgeProjectContentGrantRequest::submit($team);
  $extra=$r;$extra['actor']=$r['subject_id'];try{PhorgeProjectContentGrantRequest::submit($extra);throw new Exception('actor accepted');}catch(InvalidArgumentException$e){$n++;}
  $revoke=$r;$revoke['action']='revoke';$revoke['grant_id']=str_repeat('2',64);$revoke['valid_for_seconds']=null;PhorgeProjectContentGrantRequest::submit($revoke);
  $wrong=$valid;$wrong['operation']='task.description';try{PhorgeProjectContentGrantRequest::stored($wrong);throw new Exception('task accepted');}catch(InvalidArgumentException$e){$n++;}
  echo json_encode(array('operation'=>$valid['operation'],'team_kind'=>$t['subject_kind'],'negatives'=>$n));
  ''');self.assertEqual(d,{'operation':'project.description','team_kind':'business-team','negatives':11})
 def test_sealed_history_plan_does_not_open_other_native_modes(self):
  d=php(r'''
  $r=PhorgeProjectContentGrantRequest::submit(array('action'=>'grant','grant_id'=>null,'reason'=>'synthetic','subject_id'=>'PHID-USER-'.str_repeat('a',20),'subject_kind'=>'user','target_phid'=>'PHID-PROJ-'.str_repeat('b',20),'valid_for_seconds'=>1));$p=PhorgeProjectContentGrantPlan::newForSubmission($r);$p->assertScope('history',$r['target_phid']);$n=0;
  foreach(array('execute','approve','propose','self-join')as$m){try{$p->assertScope($m,$r['target_phid']);throw new Exception('mode accepted');}catch(RuntimeException$e){$n++;}}
  try{PhorgeProjectContentGrantPlan::newForStoredApproval($r['target_phid'],array($r['subject_id']));throw new Exception('forged store source');}catch(RuntimeException$e){$n++;}
  try{serialize($p);throw new Exception('serialization accepted');}catch(RuntimeException$e){$n++;}
  echo json_encode(array('identities'=>$p->getIdentityPHIDs(),'negatives'=>$n));
  ''');self.assertEqual(d['negatives'],6);self.assertEqual(d['identities'],['PHID-USER-'+'a'*20])
 def test_direct_membership_period_decoder_is_fail_closed(self):
  d=php(r'''
  $r=array('unitID'=>'team.a','userPHID'=>'PHID-USER-'.str_repeat('a',20),'state'=>'active','validFrom'=>'10','validUntil'=>'30','version'=>'1');$positive=PhorgeProjectContentSource::decodeMembership($r,$r['unitID'],$r['userPHID'],20);$denied=0;$refused=0;
  foreach(array(array('state','ended'),array('state','suspended'),array('validFrom','21'),array('validUntil','20'))as$c){$x=$r;$x[$c[0]]=$c[1];if(!PhorgeProjectContentSource::decodeMembership($x,$r['unitID'],$r['userPHID'],20)){$denied++;}}
  foreach(array(array('state','unknown'),array('validFrom','bad'),array('validUntil',true),array('version',true),array('validUntil','18446744073709551615'),array('unitID','parent.team'))as$c){$x=$r;$x[$c[0]]=$c[1];try{PhorgeProjectContentSource::decodeMembership($x,$r['unitID'],$r['userPHID'],20);throw new Exception('unknown accepted');}catch(RuntimeException$e){$refused++;}}
  echo json_encode(array('positive'=>$positive,'denied'=>$denied,'refused'=>$refused,'missing'=>PhorgeProjectContentSource::decodeMembership(null,$r['unitID'],$r['userPHID'],20)));
  ''');self.assertEqual(d,{'positive':True,'denied':4,'refused':6,'missing':False})
 def test_execution_remains_blocked_and_flag_defaults_false(self):
  d=php(r'''
  $o=(new PhorgeProjectContentGrantConfigOptions())->getOptions();$flag=head($o);try{PhorgeProjectContentGrantService::assertEnabled();throw new Exception('default enabled');}catch(RuntimeException$e){if($e->getMessage()!=='content_grant_disabled'){throw $e;}}echo json_encode(array('execution'=>PhorgeProjectContentGrantService::executionStatus(),'default'=>$flag->getDefault(),'registered_default'=>(new PhabricatorConfigDefaultSource())->getKeys(array('governance.project-content-grants.enabled'))['governance.project-content-grants.enabled']));
  ''');self.assertFalse(d['execution']['can_execute']);self.assertFalse(d['default']);self.assertFalse(d['registered_default'])
 def test_new_schema_is_metadata_only_and_revocation_has_no_recipient_eligibility_gate(self):
  sql=(ROOT/'resources/sql/autopatches/20261110.project.contentgrants.sql').read_text();self.assertEqual(sql.count('CREATE TABLE'),3);self.assertEqual(sql.count('ENGINE=InnoDB'),3);self.assertNotIn('UPDATE ',sql);self.assertNotIn('DELETE ',sql)
  store=(ROOT/'src/infrastructure/governance/content/PhorgeProjectContentGrantStore.php').read_text();revoke=store.split("if ($r['action']==='revoke') {",1)[1].split('return PhorgeProjectContentSource::subject',1)[0];self.assertNotIn('lockIdentityPHID',revoke);self.assertNotIn('eligible',revoke)
  self.assertIn("$this->scope->validateCredential($d['requester'],true)",store);self.assertIn('governance_contentaudit',store)
if __name__=='__main__':unittest.main()

"""Offline actual native config and strict durable descriptor tests; no SQL proof."""
import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
class PartialComposition(unittest.TestCase):
 def php(self,code):
  p=subprocess.run(['php','-r',code,str(ROOT)],capture_output=True,text=True,timeout=15)
  self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');return json.loads(p.stdout)
 def test_config_review_digest_tracks_real_selected_values_across_objects(self):
  d=self.php(r'''ob_start();require$argv[1].'/support/governance/recovery/tests/application-config-facts.php';ob_end_clean();
list($stack,$dict)=governance_config_facts_prepared();$one=PhorgeRecoveryApplicationConfigFacts::capture()->finish();
list($stack,$dict)=governance_config_facts_prepared();$two=PhorgeRecoveryApplicationConfigFacts::capture()->finish();
$dict->setKeys(['auth.require-approval'=>true]);$three=PhorgeRecoveryApplicationConfigFacts::capture()->finish();
echo json_encode(['stable'=>$one['source_digest']===$two['source_digest'],'changed'=>$one['source_digest']!==$three['source_digest'],'complete'=>$three['complete'],'leak'=>str_contains(json_encode($three),'SYNTHETIC_SECRET')]);''')
  self.assertEqual(d,dict(stable=True,changed=True,complete=False,leak=False))
 def test_composition_calls_only_compatible_adapters_and_preserves_block(self):
  s=(ROOT/'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreAssessment.php').read_text()
  for name in ('ApplicationConfigFacts::capture()', 'ProjectSourceFacts::capture($conn,$subject)', 'SpacesSourceFacts::capture($conn)', 'RestorePolicySource::capture($conn)'):
   self.assertIn(name,s)
  for name in ('TaskOwnerSource::capture','FileSourceFacts::capture','setIsDisabled','saveTransaction','openTransaction'):
   self.assertNotIn(name,s)
  self.assertIn("'schema_version'=>6,'credential_readset'=>$credential_readset,'partial_facts'=>$partial",s)
  self.assertIn('recovery_restore_sources_incomplete',s)
 def test_durable_source_profiles_reject_missing_mode_scope_digest_and_completion(self):
  d=self.php(r'''require$argv[1].'/support/runtime/src/object/Phobject.php';require$argv[1].'/src/infrastructure/governance/recovery/PhorgeRecoveryRestoreRequest.php';
$keys=['schema_version','action','subject','native_stop_transaction','category','event_digest','identity','runtime_inventory_digest','families','media','organization','credentials','native_web_API_disposition','old_media_credential_disposition','all_sources_complete','restoration_authorized'];$s=array_fill_keys($keys,null);$s['schema_version']=3;$s['all_sources_complete']=false;$s['restoration_authorized']=false;$s['selected_producer_profile']='native-authsession-insert-fence-v1-not-all-producers';$s['restore_lineage']=['provider_schema'=>1,'scope'=>'isolated-current-configuration-binding-only','physical_restore_rehearsal_proved'=>false,'complete'=>false,'configured_incarnation_digest'=>str_repeat('a',64)];
$profiles=['application-config'=>['selected-config-dictionaries-v1','process-local-endpoint-recapture'],'project-relationships'=>['subject-project-rows-selected-history-v1','owned-writer-current-locking-reads'],'spaces'=>['selected-space-rows-request-cache-v1','owned-writer-current-locking-reads'],'policy'=>['selected-policy-namedpolicy-space-row-digests-v1','owned-writer-current-locking-reads']];foreach($profiles as$f=>$p){$s['partial_facts'][$f]=['provider_schema'=>1,'scope'=>$p[0],'mode'=>$p[1],'source_digest'=>str_repeat('b',64),'complete'=>false];}
$ok=PhorgeRecoveryRestoreRequest::sourceVersion($s)===3;$negative=[];foreach(['missing','mode','scope','digest','complete','extra','version']as$case){$bad=$s;switch($case){case'missing':unset($bad['partial_facts']['policy']);break;case'mode':$bad['partial_facts']['policy']['mode']='owned-RR';break;case'scope':$bad['partial_facts']['policy']['scope']='all-policy';break;case'digest':$bad['partial_facts']['policy']['source_digest']='';break;case'complete':$bad['partial_facts']['policy']['complete']=true;break;case'extra':$bad['partial_facts']['policy']['permit']=true;break;case'version':$bad['partial_facts']['policy']['provider_schema']=2;break;}try{PhorgeRecoveryRestoreRequest::sourceVersion($bad);$negative[]=false;}catch(RuntimeException$e){$negative[]=true;}}
unset($s['partial_facts']);$s['schema_version']=2;$legacy=PhorgeRecoveryRestoreRequest::sourceVersion($s)===2;echo json_encode(['positive'=>$ok,'negative'=>$negative,'legacy'=>$legacy]);''')
  self.assertEqual(d,dict(positive=True,negative=[True]*7,legacy=True))

 def test_actual_composer_propagates_failure_and_refuses_claimed_completion(self):
  d=self.php(r'''require$argv[1].'/support/runtime/src/object/Phobject.php';require$argv[1].'/src/infrastructure/governance/recovery/PhorgeRecoveryRestoreRequest.php';
class AphrontDatabaseConnection{}class PhorgeRecoveryRestoreProfile{static function assertOwnedWriter($c){}}
class PartialStub{function __construct(private$f){}function finish(){return ['complete'=>$GLOBALS['bad_complete']??false,'source_digest'=>hash('sha256',$this->f.($GLOBALS['generation']??0))];}}
class PhorgeRecoveryApplicationConfigFacts{static function capture(){return new PartialStub('config');}}
class PhorgeRecoveryProjectSourceFacts{static function capture($c,$s){if(isset($GLOBALS['original'])){throw$GLOBALS['original'];}return new PartialStub('project');}}
class PhorgeRecoverySpacesSourceFacts{static function capture($c){return new PartialStub('spaces');}}
class PhorgeRecoveryRestorePolicySource{static function capture($c){return ['complete'=>false,'rows'=>['generation'=>$GLOBALS['generation']??0]];}}
require$argv[1].'/src/infrastructure/governance/recovery/PhorgeRecoveryRestoreAssessment.php';$method=new ReflectionMethod(PhorgeRecoveryRestoreAssessment::class,'capturePartialFacts');$conn=new AphrontDatabaseConnection();$a=$method->invoke(null,$conn,'synthetic');$GLOBALS['generation']=1;$b=$method->invoke(null,$conn,'synthetic');$changed=[];foreach($a as$f=>$entry){$changed[]=$entry['source_digest']!==$b[$f]['source_digest'];}$GLOBALS['bad_complete']=true;$denied=false;try{$method->invoke(null,$conn,'synthetic');}catch(RuntimeException$e){$denied=$e->getMessage()==='recovery_restore_partial_facts_invalid';}unset($GLOBALS['bad_complete']);$GLOBALS['original']=new RuntimeException('synthetic-budget-failure');$same=false;try{$method->invoke(null,$conn,'synthetic');}catch(RuntimeException$e){$same=$e===$GLOBALS['original'];}echo json_encode(['changed'=>$changed,'denied'=>$denied,'original'=>$same,'SQL_executed'=>false]);''')
  self.assertEqual(d,dict(changed=[True]*4,denied=True,original=True,SQL_executed=False))

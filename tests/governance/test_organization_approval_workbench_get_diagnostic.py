"""Failure projection and actual native view types, never SQL/HTTP proof."""
from pathlib import Path
import json,subprocess,unittest
ROOT=Path(__file__).resolve().parents[2]
class Diagnostic(unittest.TestCase):
 def php(self,body):
  code="ob_start();require$argv[1].'/support/governance/organization/tests/approval-workbench-controls.php';ob_end_clean();require$argv[1].'/support/governance/organization/approval-workbench-cases.php';"+body
  p=subprocess.run(['php','-r',code,str(ROOT)],text=True,capture_output=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');return json.loads(p.stdout)
 def test_actual_native_page_dialog_and_redirect_class_projection(self):
  d=self.php("$r=(new AphrontRequest('fixture.invalid','/'))->setUser(new PhabricatorUser());$c=(new PhorgeOrganizationApprovalController())->setRequest($r);echo json_encode(array_map('governance_approval_workbench_page_kind',array($c->newPage(),new AphrontDialogView(),(new ReflectionClass(AphrontRedirectResponse::class))->newInstanceWithoutConstructor(),new stdClass(),null))); ")
  self.assertEqual(d,['standard-page','dialog','redirect','other-object','non-object'])
 def test_error_sentinel_is_not_leaked_and_unknown_class_fixed(self):
  d=self.php("$ok=governance_approval_workbench_probe(static fn()=>true);$bad=governance_approval_workbench_probe(static function(){throw new RuntimeException('SYNTHETIC_PRIVATE_PHID_SQL_ARGS');});$unknown=governance_approval_workbench_probe(static function(){throw new LogicException('SECRET');});echo json_encode(array($ok,$bad,$unknown));")
  self.assertEqual(d[0],{'completed':True,'error_class':'none','source_file':'none','source_line':0});self.assertFalse(d[1]['completed']);self.assertEqual(d[1]['error_class'],'RuntimeException');self.assertEqual(d[2]['error_class'],'other');self.assertNotIn('SECRET',json.dumps(d));self.assertNotIn('PHID_SQL_ARGS',json.dumps(d))
 def test_actual_private_native_helper_failure_has_safe_source_location(self):
  d=self.php("$c=new PhorgeOrganizationApprovalController();$rows=array();for($i=0;$i<101;$i++){$rows[]=array('target_user'=>'PHID-USER-'.str_pad((string)$i,20,'0',STR_PAD_LEFT),'requester'=>null,'executor'=>null,'approver'=>null);}$probe=governance_approval_workbench_probe(static fn()=>(new ReflectionMethod($c,'peopleLabels'))->invoke($c,$rows));echo json_encode($probe);")
  self.assertFalse(d['completed']);self.assertEqual(d['error_class'],'RuntimeException');self.assertEqual(d['source_file'],'PhorgeOrganizationApprovalController.php');self.assertGreater(d['source_line'],0);self.assertNotIn('PHID',json.dumps(d))
 def test_exact_projection_accepts_real_observers_then_rejects_unknown_types(self):
  d=self.php("""$probe=governance_approval_workbench_probe(static fn()=>true);$v=array('list_kind'=>'dialog','detail_kind'=>'standard-page','list_standard'=>false,'detail_standard'=>true,'may_approve_false'=>true,'may_execute_false'=>true,'fingerprint_equal'=>true,'probes'=>array('people-labels'=>$probe,'detail-render'=>$probe));$accepted=governance_approval_workbench_get_projection($v)===$v;$refused=array();foreach(array('bool','class','raw','probe','errno','path','longpath','errorclass')as$case){$bad=$v;switch($case){case'bool':$bad['fingerprint_equal']=1;break;case'class':$bad['list_kind']='SECRET';break;case'raw':$bad['secret']='SECRET';break;case'probe':$bad['probes']['extra']=$probe;break;case'errno':$bad['probes']['detail-render']['source_line']=true;break;case'path':$bad['probes']['detail-render']['source_file']='/private/SECRET.php';break;case'longpath':$bad['probes']['detail-render']['source_file']=str_repeat('x',97).'.php';break;case'errorclass':$bad['probes']['detail-render']['error_class']='SECRET';break;}$caught=null;try{governance_approval_workbench_get_projection($bad);}catch(RuntimeException$error){$caught=$error;}$refused[]=$caught!==null;}echo json_encode(array($accepted,$refused));""")
  self.assertEqual(d,[True,[True]*8])
if __name__=='__main__':unittest.main()

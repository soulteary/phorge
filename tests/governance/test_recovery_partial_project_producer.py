"""Actual fixture and native Project final-effects body; collaborator seams, no SQL."""
from pathlib import Path
import subprocess
import json
import unittest
import test_recovery_project_source_facts as facts
project, edge, USER, L = facts.project, facts.edge, facts.USER, facts.L
ROOT = Path(__file__).resolve().parents[2]

class PartialProjectProducer(unittest.TestCase):
    def test_actual_fixture_uses_native_membership_final_effects(self):
        code = r'''
require $argv[1].'/support/runtime/src/object/Phobject.php';
require $argv[1].'/support/runtime/src/utils/utils.php';
require $argv[1].'/src/applications/transactions/constants/PhabricatorTransactions.php';
class PhabricatorEdgeType extends Phobject{}
require $argv[1].'/src/applications/project/edge/PhabricatorProjectProjectHasMemberEdgeType.php';
class PhabricatorProjectTransactionType extends Phobject{}
foreach(['Name','Type','Parent','Milestone']as$name)require $argv[1].'/src/applications/project/xaction/PhabricatorProject'.$name.'Transaction.php';
class PhabricatorLiskDAO extends Phobject{}
class PhabricatorProject extends PhabricatorLiskDAO {
 static function initializeNewProject($actor){$GLOBALS['actor_init']=$actor;return new self();}
 function supportsMilestones(){return false;}
}
class PhabricatorApplicationTransactionEditor {
 function setActor($v){$GLOBALS['actor_edit']=$v;return $this;}
 function setContentSource($v){return $this;}
 function applyTransactions($object,array $xs){$GLOBALS['xs']=$xs;return $this->applyFinalEffects($object,$xs);}
 protected function applyFinalEffects(PhabricatorLiskDAO $object,array $xs){$GLOBALS['parent_effects']=true;}
}
// Execute the REAL editor final-effects implementation; only persistence and
// the materializer are collaborators. This does not certify its SQL effects.
require $argv[1].'/src/applications/project/editor/PhabricatorProjectTransactionEditor.php';
class PhabricatorProjectsMembershipIndexEngineExtension {
 function rematerialize($object){$GLOBALS['materialized'][]=$object;}
}
class PhabricatorContentSource{static function newForSource($source,array $v){if($source!=='console'||$v!==[])throw new Exception('wrong source');return new self();}}
class PhabricatorProjectTransaction extends Phobject {
 private $type;private $metadata=[];private $value;
 function setTransactionType($v){$this->type=$v;return $this;}
 function getTransactionType(){return $this->type;}
 function setMetadataValue($k,$v){$this->metadata[$k]=$v;return $this;}
 function getMetadataValue($k){return $this->metadata[$k]??null;}
 function setNewValue($v){$this->value=$v;return $this;}
 function getNewValue(){return $this->value;}
}
class SubjectSeam {function getPHID(){return 'PHID-USER-'.str_repeat('u',20);}}
require $argv[1].'/support/governance/recovery/restore-review-cases.php';
$GLOBALS['materialized']=[];$actor=new stdClass();$subject=new SubjectSeam();
$object=governance_recovery_restore_partial_project($actor,$subject,'fixedsuffix');
$xs=$GLOBALS['xs'];$member=$xs[1];
$good=$GLOBALS['actor_init']===$actor&&$GLOBALS['actor_edit']===$actor&&count($xs)===2&&
 $xs[0]->getNewValue()==='Synthetic partial review fixedsuffix'&&
 $member->getTransactionType()===PhabricatorTransactions::TYPE_EDGE&&
 $member->getMetadataValue('edge:type')===PhabricatorProjectProjectHasMemberEdgeType::EDGECONST&&
 $member->getNewValue()===['+'=>[$subject->getPHID()=>$subject->getPHID()]]&&
 $GLOBALS['materialized']===[$object]&&$GLOBALS['parent_effects']===true;
$method=new ReflectionMethod(PhabricatorProjectTransactionEditor::class,'applyFinalEffects');
$negative=[];
foreach(['wrong-type','wrong-edge']as$case){
 $tx=new PhabricatorProjectTransaction();$tx->setTransactionType($case==='wrong-type'?'edge':PhabricatorTransactions::TYPE_EDGE)->setMetadataValue('edge:type',$case==='wrong-edge'?14:13);
 $GLOBALS['materialized']=[];$method->invoke(new PhabricatorProjectTransactionEditor(),$object,[$tx]);$negative[]=count($GLOBALS['materialized']);
}
echo json_encode(['native_final_effects_called'=>$good,'wrong_producer_materialization_counts'=>$negative,'SQL_executed'=>false]);
'''
        p = subprocess.run(['php', '-r', code, str(ROOT)], capture_output=True, text=True, timeout=10)
        self.assertEqual(p.returncode, 0, p.stderr)
        self.assertEqual(p.stderr, '')
        self.assertEqual(json.loads(p.stdout), {'native_final_effects_called': True, 'wrong_producer_materialization_counts': [0, 0], 'SQL_executed': False})

    def test_real_adapter_still_refuses_direct_inverse_without_materialization(self):
        # Same single leaf topology the actual restore fixture creates.
        row = project(2, L)
        x = dict(subject_row=dict(id='9', phid=USER), projects={L: row}, selected={L: True}, children={L: []}, user_edges=[edge(USER, 14, L)], project_edges=[edge(L, 13, USER)], history=[])
        control = facts.ProjectSourceFacts()
        denied = control.run_php(x)
        self.assertEqual(denied, {'accepted': False, 'reason': 'recovery_project_materialized_inconsistent'})
        x['project_edges'].append(edge(L, 60, USER))
        admitted = control.run_php(x)
        self.assertTrue(admitted['accepted'])
        self.assertFalse(admitted['facts']['complete'])
        self.assertFalse(admitted['facts']['restoration_authorized'])

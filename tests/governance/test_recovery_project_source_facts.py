"""Actual PHP row validator with synthetic data; not SQL/ProjectQuery permission proof."""
from pathlib import Path
import copy,json,subprocess,unittest
ROOT=Path(__file__).resolve().parents[2]
USER='PHID-USER-'+'u'*20
R='PHID-PROJ-'+'r'*20
L='PHID-PROJ-'+'l'*20
M='PHID-PROJ-'+'m'*20
def project(id,phid,parent=None,milestone=None,status='0'):
 key={R:'rrrr',L:'llll',M:'mmmm'}[phid]
 return dict(id=str(id),phid=phid,status=status,parentProjectPHID=parent,milestoneNumber=milestone,
  projectPath=('rrrr'if parent else '')+key,projectPathKey=key,projectDepth='1'if parent else '0',
  hasSubprojects='1'if phid==R else '0',hasMilestones='1'if phid==R else '0',
  viewPolicy='users',editPolicy='admin',joinPolicy='users',spacePHID=None,subtype='default',isMembershipLocked='0')
def edge(src,t,dst):return dict(src=src,type=str(t),dst=dst,dateCreated='1',seq='0',dataID=None)
def snapshot(retained=False):
 projects={R:project(1,R),L:project(2,L,R,status='100'),M:project(3,M,R,'1')}
 direct=R if retained else L
 material=[M]if retained else [L,R]
 return dict(subject_row=dict(id='9',phid=USER),projects=projects,selected={k:True for k in (R,L,M)},children={R:[M,L],L:[],M:[]},
  user_edges=[edge(USER,14,direct)],project_edges=[edge(direct,13,USER)]+[edge(p,60,USER)for p in material],history=[])
class ProjectSourceFacts(unittest.TestCase):
 def run_php(self,x):
  code="require $argv[1].'/support/runtime/src/object/Phobject.php';require $argv[1].'/src/infrastructure/governance/recovery/PhorgeRecoveryProjectSourceFacts.php';$x=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);try{echo json_encode(array('accepted'=>true,'facts'=>PhorgeRecoveryProjectSourceFacts::describeUntrustedRows($x['subject'],$x['snapshot'])));}catch(RuntimeException$e){echo json_encode(array('accepted'=>false,'reason'=>$e->getMessage()));}"
  p=subprocess.run(['php','-r',code,str(ROOT)],input=json.dumps(dict(subject=USER,snapshot=x)),capture_output=True,text=True,check=True)
  self.assertEqual('',p.stderr);self.assertNotIn('PHID-',p.stdout);self.assertNotIn('PRIVATE_RELATION',p.stdout)
  return json.loads(p.stdout)
 def test_archived_leaf_ancestor_and_milestone_semantics_are_distinct(self):
  for retained in (False,True):
   out=self.run_php(snapshot(retained));self.assertTrue(out['accepted']);self.assertFalse(out['facts']['complete']);self.assertFalse(out['facts']['actual_SQL_queries_executed'])
  # Milestone materialized rows derive from parent direct13, not parent60.
  x=snapshot();x['project_edges'].append(edge(M,60,USER));self.assertFalse(self.run_php(x)['accepted'])
  # Retained nonleaf direct13/14 remains a live direct-policy source, not leaf aggregation.
  x=snapshot(True);x['project_edges'].append(edge(R,60,USER));self.assertFalse(self.run_php(x)['accepted'])
 def test_inverse_materialized_unknown_data_and_edge_mutations_refused(self):
  self.assertTrue(self.run_php(snapshot())['accepted'])
  cases=[]
  x=snapshot();x['user_edges']=[];cases.append(x)
  x=snapshot();x['project_edges'].pop();cases.append(x)
  x=snapshot();x['project_edges'][0]['type']=999;cases.append(x)
  for bad in (1,'1',False,0.0):
   x=snapshot();x['project_edges'][0]['dataID']=bad;cases.append(x)
  x=snapshot();x['project_edges'].append(copy.deepcopy(x['project_edges'][0]));cases.append(x)
  for x in cases:self.assertFalse(self.run_php(x)['accepted'])
 def test_cycle_path_flags_and_identity_budget_refused(self):
  cases=[]
  x=snapshot();x['projects'][R]['parentProjectPHID']=L;cases.append(x)
  x=snapshot();x['projects'][L]['projectPath']='PRIVATE_RELATION';cases.append(x)
  x=snapshot();x['projects'][R]['hasSubprojects']='0';cases.append(x)
  x=snapshot();x['projects'][L]['projectPathKey']='rrrr';cases.append(x)
  x=snapshot();x['projects'][L]['id']='1';cases.append(x)
  x=snapshot();x['projects'][L]['subtype']='custom-provider';cases.append(x)
  x=snapshot();x['user_edges']*=257;cases.append(x)
  for x in cases:self.assertFalse(self.run_php(x)['accepted'])
 def test_historical_values_never_create_current_membership(self):
  x=snapshot();x['history']=[dict(id='1',phid='PHID-XACT-PROJ-'+'x'*15,objectPHID=R,authorPHID=USER,transactionType='edge',oldValue='[]',newValue='PRIVATE_RELATION',metadata='{}',dateCreated='1')]
  self.assertTrue(self.run_php(x)['accepted'])
  x['project_edges'].pop();self.assertFalse(self.run_php(x)['accepted'])
 def test_read_only_subject_indexes_and_private_binding_contract(self):
  s=(ROOT/'src/infrastructure/governance/recovery/PhorgeRecoveryProjectSourceFacts.php').read_text()
  for forbidden in ('queryfx(', 'setIsDisabled(', 'PhabricatorEdgeType::getByConstant(', 'INSERT ', 'UPDATE ', 'DELETE '):self.assertNotIn(forbidden,s)
  for literal in ('FORCE INDEX (key_dst)','FORCE INDEX (PRIMARY)','FORCE INDEX (key_phid)','FORCE INDEX (key_milestone)','FORCE INDEX (key_object)'):self.assertIn(literal,s)
  self.assertIn("hash_hmac('sha256'",s)
  self.assertNotIn('getBinding(',s)
 def test_real_fixture_entry_calls_native_producers_not_seeded_edges(self):
  source=(ROOT/'support/governance/recovery/project-source-focused.php').read_text()
  for actual in ('new PhabricatorProjectTransactionEditor()', 'new PhabricatorEdgeEditor()',
                 'new PhabricatorProjectsMembershipIndexEngineExtension()', 'new PhabricatorProjectQuery()',
                 'governance_native_request($actor,new PhabricatorAuthSessionEngine())',
                 'PhorgeRecoveryProjectSourceFacts::capture($writer,$subject->getPHID())'):
   self.assertIn(actual,source)
  self.assertLess(source.index('$actor=(new PhabricatorUser())'),source.index("'governance.account-recovery.enabled'=>true"))
  self.assertNotIn('setIsDisabled(',source);self.assertNotIn('INSERT INTO',source)
  driver=(ROOT/'support/governance/native-organization/test-native-organization-mysql.php').read_text()
  self.assertIn("'--project-source-facts'=>'project-source-facts'",driver)
  self.assertIn('governance_recovery_project_source_focused($fixture,$check)',driver)
if __name__=='__main__':unittest.main()

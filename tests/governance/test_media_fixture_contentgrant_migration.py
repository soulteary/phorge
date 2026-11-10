"""Actual native patch admission without executing SQL or manufacturing ledger rows."""
import hashlib,json,re,subprocess,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
PATCH='20261110.project.contentgrants.sql'
PIN='16f043e830f29b246efd12c22569e66bf0d2b629f4b9dbd062ab0f30a487d27e'
def php(code):
 pre='require '+json.dumps(str(ROOT/'support/runtime/src/__phutil_library_init__.php'))+';phutil_load_library('+json.dumps(str(ROOT/'src'))+');'
 p=subprocess.run(['php','-r',pre+code],cwd=ROOT,capture_output=True,text=True,timeout=20)
 if p.returncode:raise AssertionError(p.stderr)
 return json.loads(p.stdout)
class ContentGrantMigration(unittest.TestCase):
 def test_actual_native_patch_SQL_parser_preserves_comment_and_four_statements(self):
  raw=(ROOT/'resources/sql/autopatches'/PATCH).read_bytes()
  old=raw.replace(b'metadata, no native',b'metadata; no native',1)
  self.assertEqual(hashlib.sha256(old).hexdigest(),'2dbbd2c1305a467c164470fd9e7b2830e32ad282fa2cc6a1ec74e8bef0d7a249')
  with tempfile.TemporaryDirectory()as td:
   previous=Path(td)/'old.sql';previous.write_bytes(old)
   d=php(r"""
   // The real native applyPatchSQL performs file read, statement splitting,
   // namespace/charset replacement and queryfx formatting. Only final execution
   // is intercepted by an in-memory connection. No database implementation or
   // credentials are installed; any attempt to open a connection fails closed.
   final class NativePatchSQLCapture extends AphrontDatabaseConnection {
    public $queries=[];
    public function openConnection(){throw new LogicException('capture_no_database');}
    public function close(){}
    public function getInsertID(){return 0;}
    public function getAffectedRows(){return 0;}
    public function selectAllResults(){throw new LogicException('capture_no_results');}
    public function executeRawQueries(array $queries){throw new LogicException('capture_no_raw_bypass');}
    public function simulateErrorOnNextQuery($error){throw new LogicException('capture_no_simulation');}
    public function escapeUTF8String($v){return str_replace("'","''",$v);}
    public function escapeBinaryString($v){return $this->escapeUTF8String($v);}
    public function escapeColumnName($v){return '`'.str_replace('`','``',$v).'`';}
    public function escapeMultilineComment($v){return '';}
    public function escapeStringForLikeClause($v){return $v;}
    public function executeQuery(PhutilQueryString $query){$this->queries[]=$query->getUnmaskedString();}
   }
   $results=[];
   foreach([ROOT_CURRENT,ROOT_OLD]as$file){
    $capture=new NativePatchSQLCapture();
    $api=(new PhabricatorStorageManagementAPI())->setHost('capture-only')->setUser('capture-only')->setNamespace('capture_only');
    (new ReflectionProperty(PhabricatorStorageManagementAPI::class,'conns'))->setValue($api,['capture-only'=>['capture-only'=>['capture_only_'=>$capture]]]);
    $api->applyPatchSQL($file);
    $results[]=$capture->queries;
   }
   echo json_encode(['queries'=>$results,'Lisk_connections'=>(new ReflectionProperty(LiskDAO::class,'connections'))->getValue()]);
   """.replace('ROOT_CURRENT',json.dumps(str(ROOT/'resources/sql/autopatches'/PATCH))).replace('ROOT_OLD',json.dumps(str(previous))))
  self.assertEqual(d['Lisk_connections'],[])
  current,previous=d['queries'];self.assertEqual(len(current),4);self.assertEqual(len(previous),5)
  def statement_kind(query):
   executable=re.sub(r'^\s*--[^\n]*(?:\n|$)','',query).lstrip()
   self.assertRegex(executable,r'^(?:INSERT INTO|CREATE TABLE) ')
   return executable.split()[0]
  self.assertEqual([statement_kind(q)for q in current],['INSERT','CREATE','CREATE','CREATE'])
  self.assertTrue(previous[1].startswith('no native capability or role migration.'))
  with self.assertRaises(AssertionError):[statement_kind(q)for q in previous]
 def test_real_patch_is_live_and_quickstart_does_not_forge_its_ledger(self):
  d=php("$p=PhabricatorSQLPatchList::buildAllPatches();$x=$p['phabricator:"+PATCH+"'];echo json_encode(['dead'=>$x->isDead(),'type'=>$x->getType(),'name'=>basename($x->getName()),'sha256'=>hash_file('sha256',$x->getName())]);")
  self.assertEqual(d,{'dead':False,'type':'sql','name':PATCH,'sha256':PIN})
  self.assertNotIn(PATCH,(ROOT/'resources/sql/quickstart.sql').read_text())
 def test_actual_fixture_admits_reviewed_patch_before_context_gate_unknown_still_refused(self):
  d=php(r"""
  require 'support/governance/completion/fixture.php';
  $fixture=(new ReflectionClass(PhorgeGovernanceCompletionFixture::class))->newInstanceWithoutConstructor();
  (new ReflectionProperty(PhorgeGovernanceCompletionFixture::class,'root'))->setValue($fixture,getcwd());
  $patches=PhabricatorSQLPatchList::buildAllPatches();
  // Force the pre-connection context refusal after actual path/hash admission.
  // No setup credential or DB handle exists; the method must never reach SQL.
  $stack=(new PhabricatorConfigStackSource())->pushSource(new PhabricatorConfigDefaultSource());
  (new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);
  $method=new ReflectionMethod(PhorgeGovernanceCompletionFixture::class,'applyPendingPatches');$results=[];
  foreach(['phabricator:20261110.project.contentgrants.sql','phabricator:20990101.unreviewed.sql','other:20261110.project.contentgrants.sql','phabricator:20261110.project.contentgrants.sql.extra']as$key){
   try{$method->invoke($fixture,$patches,[],[$key]);throw new Exception('unexpected acceptance');}
   catch(RuntimeException$e){$results[]=$e->getMessage();}
  }
  $audit=(new ReflectionProperty(PhorgeGovernanceCompletionFixture::class,'patchAudit'))->getValue($fixture);
  echo json_encode(['results'=>$results,'hashes'=>$audit['migration_source_hashes'],'connections'=>(new ReflectionProperty(LiskDAO::class,'connections'))->getValue()]);
  """)
  self.assertEqual(d['results'],['Inherited migration context refused.']+['Unreviewed current-source migration refused.']*3)
  self.assertEqual(d['hashes'],{'phabricator:'+PATCH:PIN});self.assertEqual(d['connections'],[])
 def test_real_fixture_hash_is_not_downgraded_by_historical_source_views(self):
  import importlib.util,sys
  sys.path.insert(0,str(ROOT/'scripts/governance'))
  import project_content_grant_source_delta as n05
  import recovery_oauth_client_lifecycle_delta as lifecycle
  relative='support/governance/completion/fixture.php';raw=(ROOT/relative).read_bytes();expected='8c69fdbc292b6b42f7764c0258e1edb671190d97f9977cf6aee57eac9e5ae09b'
  self.assertEqual(hashlib.sha256(raw).hexdigest(),expected)
  self.assertEqual(n05.reduce_if_selected(relative,raw),raw);self.assertEqual(lifecycle.reduce_if_selected(relative,raw),raw)
  names=['check_recovery_assessment_credentials','check_recovery_assessment_credentials_v2','check_recovery_borrowed_session','check_recovery_conduit_producer','check_recovery_oauth_access_producer','check_recovery_oauth_access_producer_v2','check_recovery_oauth_access_producer_v3','check_recovery_oauth_authorization_producer_v1','check_recovery_oauth_code_producer_v1','check_recovery_session_lease','check_recovery_oauth_client_lifecycle']
  for name in names:
   gate=__import__(name);self.assertEqual(gate.SOURCE_INPUTS[relative],expected,name)
   reader=gate.native_source if name=='check_recovery_oauth_client_lifecycle'else gate.read_source_checked
   self.assertEqual(hashlib.sha256(reader(ROOT/relative)).hexdigest(),expected,name)
 def test_exact_metadata_only_DDL_matches_schema_columns_and_indexes(self):
  raw=(ROOT/'resources/sql/autopatches'/PATCH).read_bytes();self.assertEqual(hashlib.sha256(raw).hexdigest(),PIN)
  sql=raw.decode();spec=(ROOT/'src/infrastructure/governance/content/PhorgeProjectContentGrantSchemaSpec.php').read_text()
  tables=re.findall(r'CREATE TABLE \{\$NAMESPACE\}_project\.(\w+) \((.*?)\) ENGINE=InnoDB;',sql,re.S)
  self.assertEqual(len(tables),3)
  for table,body in tables:
   section=spec.split("$this->buildRawSchema('project','"+table+"',array(",1)[1].split("));",1)[0]
   columns=section.split('),array(',1)[0]
   types=dict(re.findall(r"'([^']+)'=>'([^']+)'",columns))
   actual={}
   for name,kind,size,nullable,auto in re.findall(r'^  (\w+) (VARBINARY|BIGINT UNSIGNED|MEDIUMTEXT)(?:\((\d+)\))? (NOT NULL|NULL)( AUTO_INCREMENT)?',body,re.M):
    actual[name]=('varbytes'+size if kind=='VARBINARY' else 'auto64' if auto else 'uint64' if kind=='BIGINT UNSIGNED' else 'text')+('?'if nullable=='NULL'else'')
   self.assertEqual(actual,types,table)
   keys={'PRIMARY': [next(n for n in types if re.search(r'^  '+n+r' .*PRIMARY KEY',body,re.M))]}
   keys.update({name:cols.split(',')for name,cols in re.findall(r'KEY (\w+) \(([^)]+)\)',body)})
   self.assertIn("'PRIMARY'=>array('columns'=>array("+','.join(repr(c)for c in keys['PRIMARY'])+"),'unique'=>true)",section)
   for name,cols in keys.items():self.assertIn("'"+name+"'=>array('columns'=>array("+','.join(repr(c)for c in cols)+")",section)
  self.assertIn("foreach (array('bindingJSON','approverBindingJSON') as $column)",spec);self.assertIn("->setColumnType('mediumtext')",spec)
  self.assertEqual(sql.count('INSERT INTO'),1);self.assertNotRegex(sql,r'(?i)\b(?:UPDATE|DELETE|ALTER|DROP)\b')
if __name__=='__main__':unittest.main()

"""Native File controls and actual prototype functions; no SQL/lease proof."""
from pathlib import Path
import json,subprocess,unittest
ROOT=Path(__file__).resolve().parents[2]
class FileSource(unittest.TestCase):
 def php(self,code,case=None):
  p=subprocess.run(['php','-r',code,str(ROOT)],input=json.dumps(case or {}),capture_output=True,text=True,timeout=10)
  self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');return json.loads(p.stdout)
 def test_actual_native_File_semantics_and_indexes(self):
  p=subprocess.run(['php',str(ROOT/'support/governance/recovery/tests/file-source-native-controls.php'),str(ROOT)],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');d=json.loads(p.stdout);self.assertEqual(len(d['checks']),7);self.assertTrue(d['passed']);self.assertFalse(d['SQL_executed']);self.assertFalse(d['FileQuery_or_parent_policy_authorized']);self.assertNotIn('PHID-',p.stdout)
 def test_actual_untrusted_normalizer_and_callback_rejection(self):
  code=r'''require$argv[1].'/support/runtime/src/object/Phobject.php';require$argv[1].'/src/applications/phid/PhabricatorPHIDConstants.php';require$argv[1].'/src/infrastructure/governance/recovery/PhorgeRecoveryFileSourceFacts.php';$v=json_decode(stream_get_contents(STDIN),true);$subject='PHID-USER-'.str_repeat('u',20);$f='PHID-FILE-'.str_repeat('f',20);$other='PHID-FILE-'.str_repeat('o',20);$file=['id'=>'1','phid'=>$f,'authorPHID'=>$subject,'viewPolicy'=>'no-one','isPartial'=>'0','isDeleted'=>'0','dateModified'=>'1'];$files=[$file];$attachments=[['id'=>'2','objectPHID'=>'PHID-TASK-'.str_repeat('t',20),'filePHID'=>$f,'attacherPHID'=>null,'attachmentMode'=>'attach','dateModified'=>'1']];$transforms=[['id'=>'3','originalPHID'=>$f,'transform'=>'opaque-native-name','transformedPHID'=>$other]];$GLOBALS['called']=false;$resource=null;class MagicFileValue{function __serialize(){$GLOBALS['called']=true;return[];}function __toString(){$GLOBALS['called']=true;return'sentinel';}}
if(isset($v['file'])){$files[0]=array_replace($file,$v['file']);}if(isset($v['attachment'])){$attachments[0]=array_replace($attachments[0],$v['attachment']);}if(isset($v['transform'])){$transforms[0]=array_replace($transforms[0],$v['transform']);}
if(isset($v['void'])){$transforms[0]['originalPHID']=PhabricatorPHIDConstants::PHID_VOID;$transforms[0]['transformedPHID']=$f;}if(isset($v['mixedvoid'])){$transforms[]=['id'=>'4','originalPHID'=>PhabricatorPHIDConstants::PHID_VOID,'transform'=>'builtin','transformedPHID'=>$f];}if(isset($v['same-transform'])){$transforms[]=$transforms[0];}if(isset($v['changed-transform'])){$changed=$transforms[0];$changed['transform']='changed';$transforms[]=$changed;}if(isset($v['object'])){$files[0]['viewPolicy']=new MagicFileValue();}if(isset($v['closure'])){$files[0]['viewPolicy']=function(){$GLOBALS['called']=true;};}if(isset($v['resource'])){$resource=fopen('php://memory','r');$files[0]['viewPolicy']=$resource;}if(isset($v['deep'])){$files[0]['viewPolicy']=[[[new MagicFileValue()]]];}if(isset($v['rows65'])){$files=array_fill(0,65,$file);}if(isset($v['relations257'])){$attachments=array_fill(0,257,$attachments[0]);}if(isset($v['duplicate'])){$files[]=$file;}if(isset($v['cycle'])){$transforms[]=['id'=>'4','originalPHID'=>$other,'transform'=>'opaque','transformedPHID'=>$f];}if(isset($v['bytes'])){$attachments=[];$transforms=[];for($n=1;$n<=256;$n++){$transforms[]=['id'=>(string)$n,'originalPHID'=>$f,'transform'=>str_repeat('t',512),'transformedPHID'=>$other];}}
$accepted=false;$summary=null;try{$summary=PhorgeRecoveryFileSourceFacts::describeUntrustedRows($files,$attachments,$transforms,$subject);$accepted=true;}catch(RuntimeException$error){}finally{if($resource){fclose($resource);}}echo json_encode(['accepted'=>$accepted,'callback'=>$GLOBALS['called'],'summary'=>$summary]);'''
  controls=[({},True),({'void':True},True),({'mixedvoid':True},True),({'same-transform':True},True),({'changed-transform':True},False),({'attachment':{'attachmentMode':'reference'}},True),({'attachment':{'attachmentMode':'detach'}},True),({'file':{'authorPHID':None}},False),({'file':{'isDeleted':False}},False),({'file':{'id':'01'}},False),({'file':{'phid':'PHID-FILE-'+'x'*15}},False),({'attachment':{'objectPHID':'PHID-PROJ-'+'p'*20}},False),({'attachment':{'attachmentMode':'unknown'}},False),({'attachment':{'filePHID':'PHID-FILE-'+'x'*20}},False),({'transform':{'transform':'x'*513}},False),({'object':True},False),({'closure':True},False),({'resource':True},False),({'deep':True},False),({'rows65':True},False),({'relations257':True},False),({'duplicate':True},False),({'cycle':True},False),({'bytes':True},False)]
  for value,accepted in controls:
   d=self.php(code,value);self.assertEqual(d['accepted'],accepted,value);self.assertFalse(d['callback'])
   if accepted:
    self.assertFalse(d['summary']['actual_SQL_reads_executed']);self.assertFalse(d['summary']['complete']);self.assertFalse(d['summary']['restoration_authorized']);self.assertNotIn('PHID-',json.dumps(d['summary']))
 def test_actual_schema_function_refuses_unknown_metadata(self):
  code=r'''require$argv[1].'/support/runtime/src/object/Phobject.php';require$argv[1].'/src/infrastructure/governance/recovery/PhorgeRecoveryFileSourceFacts.php';$case=json_decode(stream_get_contents(STDIN),true)['case'];$GLOBALS['mdl']=0;$GLOBALS['queried']=0;
function queryfx_one($conn,$sql,...$args){global$case;if($GLOBALS['mdl']!==3){throw new Exception('metadata before all MDL');}return['ENGINE'=>$case==='engine'?'MyISAM':'InnoDB'];}
function queryfx_all($conn,$sql,...$args){global$case;if(str_contains($sql,'WHERE 1=0')){$GLOBALS['mdl']++;return[];}$GLOBALS['queried']++;if(str_contains($sql,'STATISTICS')){$index=$args[2];$spec=['PRIMARY'=>[['id'],0],'phid'=>[['phid'],0],'authorPHID'=>[['authorPHID'],1],'key_object'=>[['objectPHID','filePHID'],0],'key_file'=>[['filePHID'],1],'originalPHID'=>[['originalPHID','transform'],0],'transformedPHID'=>[['transformedPHID'],1]][$index];$rows=[];foreach($spec[0]as$i=>$name){$rows[]=['COLUMN_NAME'=>$case==='column'?'wrong':$name,'NON_UNIQUE'=>$case==='unique'?1-$spec[1]:(string)$spec[1],'SEQ_IN_INDEX'=>$case==='sequence'?'0':(string)($i+1),'SUB_PART'=>$case==='prefix'?'4':null];}return$case==='missing'?[]:$rows;}
$table=$args[1];$names=['file'=>['id','phid','authorPHID','viewPolicy','isPartial','isDeleted','dateModified'],'file_attachment'=>['id','objectPHID','filePHID','attacherPHID','attachmentMode','dateModified'],'file_transformedfile'=>['id','originalPHID','transform','transformedPHID']][$table];$rows=[];foreach($names as$name){$type=in_array($name,['id','dateModified'])?'int':(in_array($name,['isPartial','isDeleted'])?'tinyint':(in_array($name,['attachmentMode','transform'])?'varchar':'varbinary'));$length=$name==='attachmentMode'?32:($name==='transform'?128:64);$rows[]=['COLUMN_NAME'=>$name,'DATA_TYPE'=>$type,'COLUMN_TYPE'=>$type==='int'?'int unsigned':$type,'CHARACTER_MAXIMUM_LENGTH'=>(string)$length,'CHARACTER_OCTET_LENGTH'=>$case==='oversize'?'65536':(string)($type==='varchar'?$length*4:64),'IS_NULLABLE'=>($table==='file'&&$name==='authorPHID')||$name==='attacherPHID'?'YES':'NO'];}return$rows;}
$accepted=false;try{(new ReflectionMethod(PhorgeRecoveryFileSourceFacts::class,'schema'))->invoke(null,new stdClass(),'fixed');$accepted=true;}catch(RuntimeException$error){}echo json_encode(['accepted'=>$accepted,'all_mdl_first'=>$GLOBALS['mdl']===3,'SQL_executed'=>false]);'''
  for case in ('valid','engine','missing','column','unique','sequence','prefix','oversize'):
   d=self.php(code,{'case':case});self.assertEqual(d['accepted'],case=='valid');self.assertTrue(d['all_mdl_first']);self.assertFalse(d['SQL_executed'])
 def test_registered_native_case_and_no_production_wiring(self):
  self.assertEqual((ROOT/'tests/governance/run.php').read_text().count("'PhorgeRecoveryFileSourceFactsTestCase'"),1)
  self.assertNotIn('PhorgeRecoveryFileSourceFacts',(ROOT/'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreAssessment.php').read_text())
  s=(ROOT/'src/infrastructure/governance/recovery/PhorgeRecoveryFileSourceFacts.php').read_text();self.assertNotIn('SELECT *',s);self.assertNotIn('SELECT metadata',s);self.assertNotIn('SET isDisabled',s)

 def test_actual_snapshot_per_query_sentinel_and_global_cross_file_budget(self):
  for direction in ('attachment','originalPHID','transformedPHID'):
   for size in (128,129):
    case=direction+str(size);p=subprocess.run(['php',str(ROOT/'support/governance/recovery/tests/file-source-query-seam.php'),str(ROOT),case],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');d=json.loads(p.stdout)
    self.assertEqual(d['accepted'],size==128,case);self.assertFalse(d['SQL_executed']);self.assertFalse(d['physical_scope_proved'])
    if size==128:self.assertEqual(d['attachment_rows']if direction=='attachment'else d['transform_rows'],128)
    else:
     self.assertEqual(d['reason'],'recovery_file_query_truncated');self.assertEqual(d['dependency_queries'][-1],direction)
     self.assertEqual(len(d['dependency_queries']),('attachment','originalPHID','transformedPHID').index(direction)+1)
  p=subprocess.run(['php',str(ROOT/'support/governance/recovery/tests/file-source-query-seam.php'),str(ROOT),'global257'],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);d=json.loads(p.stdout);self.assertFalse(d['accepted']);self.assertEqual(d['reason'],'recovery_file_row_budget');self.assertEqual(d['dependency_queries'],['attachment','originalPHID','transformedPHID']*2+['attachment'])

 def test_actual_registered_native_test_methods_without_mapper_or_SQL(self):
  code=r"""ob_start();require$argv[1].'/support/governance/recovery/tests/file-source-native-controls.php';ob_end_clean();require$argv[1].'/src/infrastructure/governance/recovery/__tests__/PhorgeRecoveryFileSourceFactsTestCase.php';$case=new PhorgeRecoveryFileSourceFactsTestCase();$methods=[];foreach(get_class_methods($case)as$method){if(str_starts_with($method,'test')){$case->$method();$methods[]=$method;}}echo json_encode(['methods'=>count($methods),'passed'=>true,'supported_mapper'=>false,'SQL_executed'=>false]);"""
  self.assertEqual(self.php(code),{'methods':3,'passed':True,'supported_mapper':False,'SQL_executed':False})

 def test_actual_native_query_wrapper_zero_one_invalid_and_original_error(self):
  code=r'''$case=json_decode(stream_get_contents(STDIN),true)['case'];class PhabricatorFile{function __construct(private$phid){}function getPHID(){return$this->phid;}}class PhabricatorFileQuery{function setViewer($v){return$this;}function withPHIDs($v){$GLOBALS['wanted']=$v[0];return$this;}function withIsDeleted($v){if($v!==false){throw new Exception('wrong');}return$this;}function setRaisePolicyExceptions($v){if($v!==false){throw new Exception('wrong');}return$this;}function executeOne(){throw new Exception('executeOne forces exceptions');}function execute(){global$case;if($case==='error'){throw$GLOBALS['original'];}return match($case){'zero'=>[],'one'=>[new PhabricatorFile($GLOBALS['wanted'])],'two'=>[new PhabricatorFile($GLOBALS['wanted']),new PhabricatorFile($GLOBALS['wanted'])],'foreign'=>[new PhabricatorFile('foreign')],'type'=>[new stdClass()],default=>false};}}require$argv[1].'/support/governance/recovery/file-source-cases.php';$GLOBALS['original']=new RuntimeException('fixed-control');$caught=null;$result=null;try{$result=governance_recovery_file_source_view(['file'=>new PhabricatorFile('synthetic')],new stdClass());}catch(Throwable$e){$caught=$e;}echo json_encode(['result'=>$result,'refused'=>$caught!==null,'original'=>$caught===$GLOBALS['original']]);'''
  for case in ('zero','one','two','foreign','type','invalid','error'):
   d=self.php(code,{'case':case});self.assertEqual(d['result'],False if case=='zero'else True if case=='one'else None);self.assertEqual(d['refused'],case not in ('zero','one'));self.assertEqual(d['original'],case=='error')
 def test_actual_wait_matcher_requires_exact_schema_and_owner_index(self):
  code=r'''require$argv[1].'/support/governance/recovery/file-source-cases.php';$schema='governance_native_'.str_repeat('a',16).'_file';$r=['requester'=>'11','blocker'=>'12','schema_name'=>$schema,'table_name'=>'file_attachment','index_name'=>'key_file','mode'=>'X,GAP,INSERT_INTENTION','status'=>'WAITING'];$positive=governance_recovery_file_source_wait_rows([$r],'11','12',$schema,'file_attachment','key_file');$negative=[];foreach(['requester'=>'12','blocker'=>'11','schema_name'=>'governance_native_'.str_repeat('b',16).'_file','table_name'=>'file','index_name'=>'PRIMARY','mode'=>'S','status'=>'GRANTED']as$k=>$v){$bad=$r;$bad[$k]=$v;$negative[]=governance_recovery_file_source_wait_rows([$bad],'11','12',$schema,'file_attachment','key_file');}$negative[]=governance_recovery_file_source_wait_rows([$r,$r],'11','12',$schema,'file_attachment','key_file');echo json_encode(['positive'=>$positive,'negative'=>$negative]);'''
  self.assertEqual(self.php(code),{'positive':True,'negative':[False]*8})

 def test_actual_raw_finally_unknown_scope_never_mutates_and_preserves_primary(self):
  code=r'''require$argv[1].'/support/runtime/src/error/PhutilAggregateException.php';require$argv[1].'/support/governance/recovery/file-source-cases.php';$GLOBALS['primary']=new RuntimeException('primary-control');$GLOBALS['mutations']=0;$GLOBALS['reads']=0;
class FixtureObject{function __construct(private$p,private$id=1){}function getPHID(){return$this->p;}function getID(){return$this->id;}}
class ResultSeam{function fetch_assoc(){return['id'=>'1','phid'=>'PHID-FILE-'.str_repeat('f',20),'authorPHID'=>'PHID-USER-'.str_repeat('u',20)];}function fetch_all($mode){return[];}}
class SetupSeam{function real_escape_string($value){return$value;}function query($sql){if(!str_starts_with($sql,'SELECT ')){$GLOBALS['mutations']++;throw new Exception('unexpected mutation');}$GLOBALS['reads']++;return new ResultSeam();}}
class AuxSessionResult{function fetch_assoc(){return['ac'=>'1'];}}class AuxSessionSeam{function query($sql){return new AuxSessionResult();}}
class FixtureSeam{function assertOwned(){}function getAdmin(){return new SetupSeam();}function newOwnedSetupConnection(){return new AuxSessionSeam();}function table($app,$table){return'fixed_table';}}
class PhorgeRecoveryOwnedRRScope{static function begin($observer){return new self();}}
class PhorgeRecoveryFileSourceFacts{static function capture($scope,$subject){throw$GLOBALS['primary'];}}
function governance_task_owner_abort($scope){throw new RuntimeException('scope-cleanup-unproved-control');}
$state=['file'=>new FixtureObject('PHID-FILE-'.str_repeat('f',20)),'derived'=>new FixtureObject('PHID-FILE-'.str_repeat('d',20)),'task'=>new FixtureObject('PHID-TASK-'.str_repeat('t',20)),'users'=>['subject'=>new FixtureObject('PHID-USER-'.str_repeat('u',20)),'other'=>new FixtureObject('PHID-USER-'.str_repeat('o',20))]];$caught=null;try{governance_recovery_file_source_raw_cases(new FixtureSeam(),new stdClass(),$state,function(){throw new Exception('unexpected check');});}catch(PhutilAggregateException$error){$caught=$error->getExceptions();}echo json_encode(['caught'=>$caught!==null,'primary_kept'=>($caught['primary']??null)===$GLOBALS['primary'],'scope_retained'=>isset($caught['scope']),'synthetic_deferred'=>isset($caught['synthetic']),'mutations'=>$GLOBALS['mutations'],'reads'=>$GLOBALS['reads'],'SQL_executed'=>false]);'''
  self.assertEqual(self.php(code),{'caught':True,'primary_kept':True,'scope_retained':True,'synthetic_deferred':True,'mutations':0,'reads':3,'SQL_executed':False})

 def test_actual_transform_insert_builder_covers_native_required_columns(self):
  # Derive requirements from the real DDL, independently of builder literals.
  import re,csv,io
  ddl=(ROOT/'resources/sql/quickstart.sql').read_text();block=re.search(r'CREATE TABLE `file_transformedfile` \((.*?)\n\) ENGINE=',ddl,re.S).group(1)
  schema={}
  for line in block.splitlines():
   match=re.match(r'\s*`([^`]+)`\s+(.+?)(?:,)?$',line)
   if match:schema[match.group(1)]=match.group(2)
  required={name for name,spec in schema.items()if 'NOT NULL'in spec and 'DEFAULT'not in spec and 'AUTO_INCREMENT'not in spec}
  code=r'''ob_start();require$argv[1].'/support/governance/recovery/tests/file-source-native-controls.php';ob_end_clean();require$argv[1].'/support/governance/recovery/file-source-cases.php';class SQLBuilderAdmin{function real_escape_string($value){return str_replace("'","\\'",$value);}}class SQLBuilderFixture{function assertOwned(){}function getAdmin(){return new SQLBuilderAdmin();}function table($app,$table){if($app!=='file'||$table!=='file_transformedfile'){throw new RuntimeException('wrong table');}return'`fixture`.`file_transformedfile`';}}$state=['file'=>(new PhabricatorFile())->setPHID('PHID-FILE-'.str_repeat('f',20)),'derived'=>(new PhabricatorFile())->setPHID('PHID-FILE-'.str_repeat('d',20))];$dao=new PhabricatorTransformedFile();echo json_encode(['SQL'=>governance_recovery_file_transform_insert_sql(new SQLBuilderFixture(),$state),'native_timestamps'=>$dao->getConfigOption(LiskDAO::CONFIG_TIMESTAMPS),'SQL_executed'=>false]);'''
  result=self.php(code);self.assertIs(result['native_timestamps'],True);self.assertFalse(result['SQL_executed'])
  match=re.fullmatch(r'INSERT INTO `fixture`\.`file_transformedfile` \(([^)]*)\) VALUES \((.*)\)',result['SQL']);self.assertIsNotNone(match)
  columns=match[1].split(',');values=next(csv.reader(io.StringIO(match[2]),delimiter=',',quotechar="'",escapechar='\\',strict=True))
  def validate(names,values):
   if len(names)!=len(values)or len(names)!=len(set(names))or not set(names)<=set(schema)or not required<=set(names):raise ValueError('required native insert fields missing')
   for name,value in zip(names,values):
    if re.match(r'int(?:\(\d+\))? unsigned',schema[name]):
     if not re.fullmatch(r'(?:0|[1-9][0-9]*)',value)or int(value)>4294967295:raise ValueError('native uint field refused')
   return True
  self.assertTrue(validate(columns,values));self.assertEqual(dict(zip(columns,values))['dateCreated'],'1');self.assertEqual(dict(zip(columns,values))['dateModified'],'1')
  for name in required:
   index=columns.index(name)
   with self.subTest(missing_required=name),self.assertRaises(ValueError):validate(columns[:index]+columns[index+1:],values[:index]+values[index+1:])
  old_columns=[name for name in columns if name not in ('dateCreated','dateModified')];old_values=[value for name,value in zip(columns,values)if name in old_columns]
  with self.assertRaises(ValueError):validate(old_columns,old_values)

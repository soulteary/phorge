<?php
/** Actual native User/Task/File models + PhorgeGlobalPolicyRule + consumer.
 * Query execution and SQL/scope ownership are collaborators; no physical SQL. */
$root=$argv[1];$case=$argv[2];
function phutil_register_library_map($map){foreach($map['class']as$class=>$path){$GLOBALS['admin_paths'][$class]=$GLOBALS['admin_base'].'/'.$path;}}
foreach(array('/support/runtime/src','/src')as$base){$GLOBALS['admin_base']=$root.$base;require$root.$base.'/__phutil_library_map__.php';}
spl_autoload_register(function($class){if(isset($GLOBALS['admin_paths'][$class])){require_once$GLOBALS['admin_paths'][$class];}});
require_once$root.'/support/runtime/src/utils/utils.php';
require_once$root.'/src/infrastructure/governance/recovery/PhorgeRecoveryFileSourceFacts.php';
class PhorgeRecoveryOwnedRRScope{function getFacts(){return array();}}
class ParentQuerySeam {
 function setViewer($v){$GLOBALS['viewer_exact'][]=$v===$GLOBALS['viewer'];return $this;}
 function withPHIDs($v){$GLOBALS['queries'][]=[static::class,$v];return $this;}
 function setLimit($v){if($v!==2)throw new Exception('unbounded');return $this;}
 function setRaisePolicyExceptions($v){if($v!==false)throw new Exception('raises');return $this;}
 function executeOne(){throw new Exception('executeOne refused');}
 function execute(){
  $task=(new ManiphestTask())->setID(1)->setPHID(file_seam_phid('TASK',1))
    ->setOwnerPHID(null)->setViewPolicy($GLOBALS['policy_mode'])->setEditPolicy('no-one')
    ->setSpacePHID(null)->setDateModified(1);
  $allowed=(bool)(new PhorgeGlobalPolicyRule())->applyRule($GLOBALS['viewer'],$GLOBALS['policy_mode'],$task);
  $GLOBALS['actual_rule_results'][]=$allowed;
  if(!$allowed){return array();}
  if(static::class==='ManiphestTaskQuery'){return array($task);}
  return array((new PhabricatorFile())->setID(1)->setPHID(file_seam_phid('FILE',1))
    ->setAuthorPHID(file_seam_phid('USER',1))->setViewPolicy('no-one')
    ->setDateModified(1)->setIsPartial(0)->setIsDeleted(0));
 }
}

class ManiphestTaskQuery extends ParentQuerySeam{}
class PhabricatorFileQuery extends ParentQuerySeam {function withIsDeleted($v){if($v!==false)throw new Exception('deleted');return $this;}}

class PhorgeRecoveryRestoreProfile{static function assertOwnedWriter($conn){}}
$GLOBALS['file_seam_queries']=array();$GLOBALS['file_seam_roots']=array();
function file_seam_phid($type,$id){return'PHID-'.$type.'-'.str_pad((string)$id,20,'0',STR_PAD_LEFT);}
function queryfx_one($conn,$sql,...$args){
 if(str_contains($sql,'isApproved,isDisabled')){$row=['id'=>'2','phid'=>file_seam_phid('USER',2),'isAdmin'=>$GLOBALS['SQL_admin'],'isApproved'=>'1','isDisabled'=>'0','isSystemAgent'=>'0','isMailingList'=>'0'];if(!str_contains($sql,'phid,isAdmin,')){unset($row['isAdmin']);}return$row;}
 if(str_contains($sql,'maniphest_task')){if($GLOBALS['case']==='deleted-parent')return null;return ['id'=>'1','phid'=>file_seam_phid('TASK',1),'ownerPHID'=>null,'viewPolicy'=>$GLOBALS['policy_mode'],'editPolicy'=>'no-one','spacePHID'=>null,'dateModified'=>'1'];}
 if(str_contains($sql,'DATABASE()')){return array('db'=>'governance_native_'.str_repeat('a',16).'_user');}
 if(str_contains($sql,'information_schema.TABLES')){return array('ENGINE'=>'InnoDB');}
 if(str_contains($sql,'SELECT id,phid')){return array('id'=>'1','phid'=>file_seam_phid('USER',1));}
 return array('id'=>'1');
}
function queryfx_all($conn,$sql,...$args){
 global$case;
 if(str_contains($sql,'WHERE 1=0')){return array();}
 if(str_contains($sql,'STATISTICS')){
  $spec=array('PRIMARY'=>array(array('id'),0),'phid'=>array(array('phid'),0),'authorPHID'=>array(array('authorPHID'),1),'key_object'=>array(array('objectPHID','filePHID'),0),'key_file'=>array(array('filePHID'),1),'originalPHID'=>array(array('originalPHID','transform'),0),'transformedPHID'=>array(array('transformedPHID'),1))[$args[2]];
  $out=array();foreach($spec[0]as$i=>$column){$out[]=array('COLUMN_NAME'=>$column,'NON_UNIQUE'=>(string)$spec[1],'SEQ_IN_INDEX'=>(string)($i+1),'SUB_PART'=>null);}return$out;
 }
 if(str_contains($sql,'information_schema.COLUMNS')){
  $table=$args[1];$names=array('file'=>array('id','phid','authorPHID','viewPolicy','isPartial','isDeleted','dateModified'),'file_attachment'=>array('id','objectPHID','filePHID','attacherPHID','attachmentMode','dateModified'),'file_transformedfile'=>array('id','originalPHID','transform','transformedPHID'))[$table];$rows=array();
  foreach($names as$name){$type=in_array($name,array('id','dateModified'))?'int':(in_array($name,array('isPartial','isDeleted'))?'tinyint':(in_array($name,array('attachmentMode','transform'))?'varchar':'varbinary'));$length=$name==='attachmentMode'?32:($name==='transform'?128:64);$rows[]=array('COLUMN_NAME'=>$name,'DATA_TYPE'=>$type,'COLUMN_TYPE'=>$type==='int'?'int unsigned':$type,'CHARACTER_MAXIMUM_LENGTH'=>(string)$length,'CHARACTER_OCTET_LENGTH'=>(string)($type==='varchar'?$length*4:64),'IS_NULLABLE'=>($table==='file'&&$name==='authorPHID')||$name==='attacherPHID'?'YES':'NO');}return$rows;
 }
 if(str_contains($sql,'FROM %T.file FORCE')){
  $count=$case==='global257'?3:1;$out=array();for($i=1;$i<=$count;$i++){$phid=file_seam_phid('FILE',$i);$GLOBALS['file_seam_roots'][$phid]=$i;$out[]=array('id'=>(string)$i,'phid'=>$phid,'authorPHID'=>file_seam_phid('USER',1),'viewPolicy'=>'no-one','isPartial'=>'0','isDeleted'=>'0','dateModified'=>'1');}return$out;
 }
 $attachment=str_contains($sql,'file_attachment');$direction=$attachment?'attachment':$args[1];$file=$attachment?$args[1]:$args[3];$ordinal=$GLOBALS['file_seam_roots'][$file];$GLOBALS['file_seam_queries'][]=$direction;
 $count=$attachment?1:0;if($case==='no-parent')$count=0;if($case==='global257'&&$attachment){$count=array(86,86,85)[$ordinal-1];}elseif($case===$direction.'128'){$count=128;}elseif($case===$direction.'129'){$count=129;}
 $rows=array();for($i=1;$i<=$count;$i++){
  $id=($ordinal-1)*128+$i;
  if($attachment){$rows[]=array('id'=>(string)$id,'objectPHID'=>file_seam_phid($case==='unsupported-parent'?'PROJ':'TASK',$id),'filePHID'=>$file,'attacherPHID'=>null,'attachmentMode'=>($GLOBALS['phase']&&$case==='topology-mutation'?'reference':'attach'),'dateModified'=>'1');}
  else{$external=file_seam_phid('FILE',1000+$id);$rows[]=array('id'=>(string)$id,'originalPHID'=>$direction==='originalPHID'?$file:$external,'transform'=>'t'.$id,'transformedPHID'=>$direction==='transformedPHID'?$file:$external);}
 }return$rows;
}


$stack=new PhabricatorConfigStackSource();$namespace='governance_native_'.str_repeat('a',16);
$stack->pushSource(new PhabricatorConfigDictionarySource(array('storage.default-namespace'=>$namespace)));
(new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);
(new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
$conn=new AphrontIsolatedDatabaseConnection(array());
(new ReflectionProperty(LiskDAO::class,'connections'))->setValue(null,array($namespace.'_user:w'=>$conn));
$GLOBALS['phase']=false;$GLOBALS['queries']=array();$GLOBALS['viewer_exact']=array();$GLOBALS['actual_rule_results']=array();
$GLOBALS['policy_mode']=in_array($case,array('binding-drift','bind-admin','bind-member'),true)?PhabricatorPolicies::POLICY_USER:PhabricatorPolicies::POLICY_ADMIN;
$GLOBALS['SQL_admin']=in_array($case,array('stale-admin','fresh-member','bind-member','malformed-viewer'),true)?0:1;
if($case==='malformed-sql'){$GLOBALS['SQL_admin']=2;}
$object_admin=in_array($case,array('fresh-member','bind-member'),true)?0:1;
if($case==='malformed-viewer'){$object_admin='truthy-noncanonical';}
$GLOBALS['viewer']=$viewer=(new PhabricatorUser())->setID(2)->setPHID(file_seam_phid('USER',2))
  ->setIsAdmin($object_admin)->setIsApproved(1)->setIsDisabled(0)->setIsSystemAgent(0)->setIsMailingList(0);
foreach(array('malformed-disabled'=>'setIsDisabled','malformed-approved'=>'setIsApproved',
 'malformed-system'=>'setIsSystemAgent','malformed-mail'=>'setIsMailingList')as$name=>$setter) {
 if($case===$name){$viewer->$setter('truthy-noncanonical');}
}
$task=new ManiphestTask();$rule=new PhorgeGlobalPolicyRule();
$rule_before=(bool)$rule->applyRule($viewer,$GLOBALS['policy_mode'],$task);
$result=null;$error=null;$queries_at_capture=null;
try {
 $capture=PhorgeRecoveryFileSourceFacts::captureParentPolicies(new PhorgeRecoveryOwnedRRScope(),file_seam_phid('USER',1),$viewer);
 $queries_at_capture=count($GLOBALS['queries']);
 if($case==='late-malformed-viewer'){$viewer->setIsAdmin('truthy-noncanonical');}
 if(in_array($case,array('downgrade-during','binding-drift'),true)){$GLOBALS['SQL_admin']=0;}
 if($case==='binding-drift'){$viewer->setIsAdmin(0);}
 $result=$capture->finishParentPolicies();
}catch(Throwable$e){$error=$e->getMessage();}
$rule_after=(bool)$rule->applyRule($viewer,$GLOBALS['policy_mode'],$task);
echo json_encode(array('result'=>$result,'error'=>$error,'native_global_rule_before'=>$rule_before,
 'native_global_rule_after'=>$rule_after,'native_queries'=>count($GLOBALS['queries']),
 'native_queries_at_capture'=>$queries_at_capture,'actual_rule_results'=>$GLOBALS['actual_rule_results'],
 'SQL_executed'=>false,'native_models_and_global_rule_are_actual'=>true))."\n";

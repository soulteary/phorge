<?php
/** Execute actual snapshot/query bounds against explicit synthetic SQL seams.
 * The scope/DAO names below are stand-ins, never physical transaction evidence. */
$root=$argv[1];$case=$argv[2];
require$root.'/support/runtime/src/object/Phobject.php';require$root.'/support/runtime/src/utils/utils.php';require$root.'/src/applications/phid/PhabricatorPHIDConstants.php';require$root.'/src/infrastructure/governance/recovery/PhorgeRecoveryFileSourceFacts.php';
class PhorgeRecoveryOwnedRRScope{function getFacts(){return array();}}
class PhabricatorUser {
 function establishConnection($mode){return new stdClass();}
 function isLoggedIn(){return $GLOBALS['case']!=='anonymous';}
 function isOmnipotent(){return $GLOBALS['case']==='omnipotent';}
 function getPHID(){return file_seam_phid('USER',2);}
 function getIsAdmin(){return 0;} function getIsApproved(){return 1;} function getIsDisabled(){return 0;}
 function getIsSystemAgent(){return 0;} function getIsMailingList(){return 0;}
}
class ManiphestTask {
 function getPHID(){return file_seam_phid('TASK',1);} function getID(){return 1;}
 function getOwnerPHID(){return null;} function getViewPolicy(){return $GLOBALS['case']==='stale-native'?'users':'no-one';}
 function getEditPolicy(){return 'no-one';} function getSpacePHID(){return null;}
 function getDateModified(){return 1;}
}
class PhabricatorFile {
 function getPHID(){return file_seam_phid('FILE',1);} function getID(){return 1;}
 function getAuthorPHID(){return file_seam_phid('USER',1);} function getViewPolicy(){return 'no-one';}
 function getDateModified(){return 1;} function getIsPartial(){return 0;} function getIsDeleted(){return 0;}
 function getIsProfileImage(){return $GLOBALS['case']==='profile';} function isBuiltin(){return false;}
}
class ParentQuerySeam {
 function setViewer($v){$GLOBALS['viewer_exact'][]=$v===$GLOBALS['viewer'];return $this;}
 function withPHIDs($v){$GLOBALS['queries'][]=[static::class,$v];return $this;}
 function setLimit($v){if($v!==2)throw new Exception('unbounded');return $this;}
 function setRaisePolicyExceptions($v){if($v!==false)throw new Exception('raises');return $this;}
 function executeOne(){throw new Exception('executeOne refused');}
 function execute(){
  if($GLOBALS['case']==='query-error')throw $GLOBALS['original'];
  $class=static::class==='ManiphestTaskQuery'?'ManiphestTask':'PhabricatorFile';
  if(in_array($GLOBALS['case'],['denied','policy-mutation','deleted-parent'])||($GLOBALS['case']==='parent-denied'&&static::class==='ManiphestTaskQuery'))return [];
  if($GLOBALS['case']==='duplicate')return [new $class(),new $class()];
  if($GLOBALS['case']==='foreign')return [new stdClass()];
  return [new $class()];
 }
}
class ManiphestTaskQuery extends ParentQuerySeam{}
class PhabricatorFileQuery extends ParentQuerySeam {function withIsDeleted($v){if($v!==false)throw new Exception('deleted');return $this;}}

class PhorgeRecoveryRestoreProfile{static function assertOwnedWriter($conn){}}
$GLOBALS['file_seam_queries']=array();$GLOBALS['file_seam_roots']=array();
function file_seam_phid($type,$id){return'PHID-'.$type.'-'.str_pad((string)$id,20,'0',STR_PAD_LEFT);}
function queryfx_one($conn,$sql,...$args){
 if(str_contains($sql,'isApproved,isDisabled')){return ['id'=>'2','phid'=>file_seam_phid('USER',2),'isAdmin'=>'0','isApproved'=>'1','isDisabled'=>$GLOBALS['case']==='disabled'?'1':'0','isSystemAgent'=>'0','isMailingList'=>'0'];}
 if(str_contains($sql,'maniphest_task')){if($GLOBALS['case']==='deleted-parent')return null;return ['id'=>'1','phid'=>file_seam_phid('TASK',1),'ownerPHID'=>null,'viewPolicy'=>$GLOBALS['phase']&&$GLOBALS['case']==='policy-mutation'?'users':'no-one','editPolicy'=>'no-one','spacePHID'=>null,'dateModified'=>'1'];}
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

$GLOBALS['phase']=false;$GLOBALS['queries']=[];$GLOBALS['viewer_exact']=[];
$GLOBALS['viewer']=$viewer=new PhabricatorUser();$GLOBALS['original']=new RuntimeException('original-query-error');
$result=null;$error=null;$same=false;
try {
 $capture=PhorgeRecoveryFileSourceFacts::captureParentPolicies(new PhorgeRecoveryOwnedRRScope(),file_seam_phid('USER',1),$viewer);
 $GLOBALS['phase']=true;
 $result=$capture->finishParentPolicies();
 try{$capture->finishParentPolicies();throw new Exception('reuse accepted');}catch(RuntimeException $e){if($e->getMessage()!=='recovery_file_parent_finished')throw $e;}
} catch(Throwable $e){$error=$e->getMessage();$same=$e===$GLOBALS['original'];}
echo json_encode(['result'=>$result,'error'=>$error,'original'=>$same,'queries'=>$GLOBALS['queries'],'viewer_exact'=>$GLOBALS['viewer_exact'],'SQL_executed'=>false])."\n";

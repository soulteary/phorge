<?php
/** Execute actual snapshot/query bounds against explicit synthetic SQL seams.
 * The scope/DAO names below are stand-ins, never physical transaction evidence. */
$root=$argv[1];$case=$argv[2];
require$root.'/support/runtime/src/object/Phobject.php';require$root.'/support/runtime/src/utils/utils.php';require$root.'/src/applications/phid/PhabricatorPHIDConstants.php';require$root.'/src/infrastructure/governance/recovery/PhorgeRecoveryFileSourceFacts.php';
class PhorgeRecoveryOwnedRRScope{function getFacts(){return array();}}
class PhabricatorUser{function establishConnection($mode){return new stdClass();}}
class PhorgeRecoveryRestoreProfile{static function assertOwnedWriter($conn){}}
$GLOBALS['file_seam_queries']=array();$GLOBALS['file_seam_roots']=array();
function file_seam_phid($type,$id){return'PHID-'.$type.'-'.str_pad((string)$id,20,'0',STR_PAD_LEFT);}
function queryfx_one($conn,$sql,...$args){
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
 $count=0;if($case==='global257'&&$attachment){$count=array(86,86,85)[$ordinal-1];}elseif($case===$direction.'128'){$count=128;}elseif($case===$direction.'129'){$count=129;}
 $rows=array();for($i=1;$i<=$count;$i++){
  $id=($ordinal-1)*128+$i;
  if($attachment){$rows[]=array('id'=>(string)$id,'objectPHID'=>file_seam_phid('TASK',$id),'filePHID'=>$file,'attacherPHID'=>null,'attachmentMode'=>'attach','dateModified'=>'1');}
  else{$external=file_seam_phid('FILE',1000+$id);$rows[]=array('id'=>(string)$id,'originalPHID'=>$direction==='originalPHID'?$file:$external,'transform'=>'t'.$id,'transformedPHID'=>$direction==='transformedPHID'?$file:$external);}
 }return$rows;
}
$accepted=false;$reason=null;$facts=null;
try{$facts=(new ReflectionMethod(PhorgeRecoveryFileSourceFacts::class,'snapshot'))->invoke(null,new PhorgeRecoveryOwnedRRScope(),file_seam_phid('USER',1));$accepted=true;}catch(RuntimeException$error){$reason=$error->getMessage();}
echo json_encode(array('accepted'=>$accepted,'reason'=>$reason,'dependency_queries'=>$GLOBALS['file_seam_queries'],'attachment_rows'=>$accepted?count($facts['source']['attachments']):null,'transform_rows'=>$accepted?count($facts['source']['transforms']):null,'SQL_executed'=>false,'physical_scope_proved'=>false))."\n";

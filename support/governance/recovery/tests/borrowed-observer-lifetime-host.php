<?php
/** Actual support wrapper + actual opaque owner; explicit SQL and core-seam collaborators, no SQL/network. */
$root=$argv[1];$mode=$argv[2];require$root.'/support/runtime/src/error/PhutilAggregateException.php';
class Phobject{}
class R extends mysqli_result {private $rows;function __construct($rows){$this->rows=$rows;}public function fetch_assoc():array|null|false{return$this->rows?array_shift($this->rows):null;}public function fetch_all(int $mode=MYSQLI_NUM):array{return$this->rows;}}
class C extends mysqli {
 public $present=false,$attributes=null,$created=0,$dropped=0,$grants=array(),$queries=0,$threadReads=0;
 function __construct(){}
 public function real_escape_string(string $string):string{return addslashes($string);}
 public function query(string $query,int $result_mode=MYSQLI_STORE_RESULT):mysqli_result|bool{
  $this->queries++;
  if(str_starts_with($query,'SHOW GRANTS FOR')){
   $to=' TO `gov_c_writer_'.str_repeat('b',12).'`@`%`';$ns='governance_native_'.str_repeat('a',16);$grants=array('GRANT USAGE ON *.*'.$to);
   preg_match_all('/^CREATE DATABASE .*`\{\$NAMESPACE\}_([a-z_]+)`/m',file_get_contents($GLOBALS['root'].'/resources/sql/quickstart.sql'),$matches);
   foreach($matches[1]as$app){$grants[]='GRANT SELECT, INSERT, UPDATE, DELETE ON `'.str_replace('_','\\_',$ns.'_'.$app).'`.*'.$to;}$grants[]='GRANT SELECT ON `'.str_replace('_','\\_',$ns.'_governance').'`.*'.$to;
   return new R(array_map(fn($g)=>array($g),$grants));
  }
  if(str_starts_with($query,'SELECT COUNT(*) n FROM mysql.user')){return new R(array(array('n'=>$GLOBALS['mode']==='preexisting'?'1':($this->present?'1':'0'))));}
  if(str_starts_with($query,'SELECT ATTRIBUTE attributes')){return new R(array(array('attributes'=>json_encode($this->attributes))));}
  if(str_starts_with($query,'SELECT COUNT(*) n FROM performance_schema.threads')){$this->threadReads++;return new R(array(array('n'=>$GLOBALS['mode']==='live'?'1':'0')));}
  if(preg_match("/\\ACREATE USER '(gov_c_metadata_[a-f0-9]{16})'@'%' IDENTIFIED BY '[a-f0-9]{48}' ATTRIBUTE '(.*)'\\z/D",$query,$m)){
   $GLOBALS['metadata_user']=$m[1];$this->created++;$this->present=true;$this->attributes=json_decode(stripslashes($m[2]),true);
   if($GLOBALS['mode']==='wrong-owner'){$this->attributes['governance_fixture_owner']=str_repeat('d',64);}
   if($GLOBALS['mode']==='create-ACK')throw new RuntimeException('host_create_ACK_lost');return true;
  }
  if(str_starts_with($query,'GRANT ')){
   if(!preg_match("/\\AGRANT (?:SELECT ON `performance\\\\_schema`\\.\\*|PROCESS ON \\*\\.\\*) TO 'gov_c_metadata_[a-f0-9]{16}'@'%'\\z/D",$query)){throw new RuntimeException('host_unexpected_or_writer_GRANT');}$this->grants[]=$query;return true;
  }
  if(preg_match("/\\ADROP USER 'gov_c_metadata_[a-f0-9]{16}'@'%'\\z/D",$query)){$this->dropped++;$this->present=false;return true;}
  throw new RuntimeException('host_unexpected_owner_SQL');
 }
}
class PhorgeGovernanceCompletionFixture {
 private $control,$ownerNonce;function __construct($c){$this->control=$c;$this->ownerNonce=str_repeat('e',64);}function assertOwned(){}function getNamespace(){return'governance_native_'.str_repeat('a',16);}function getWriterConfig(){return array('host'=>'127.0.0.1','port'=>3306,'namespace'=>$this->getNamespace(),'user'=>'gov_c_writer_'.str_repeat('b',12));}
}

class PhutilOpaqueEnvelope {function __construct($v){}}
class AphrontDatabaseConnection{}
final class AphrontMySQLiDatabaseConnection extends AphrontDatabaseConnection {
 function __construct($c){}public function close(){$GLOBALS['closes']++;if($GLOBALS['mode']==='close-failure')throw new RuntimeException('host_close_failure');}
}
class PhorgeRecoveryRestoreService {private static $borrowedObserver;private static $borrowedOwner;}
function queryfx_one($c,$sql){return ['principal'=>$GLOBALS['metadata_user'].'@%','role'=>'NONE','proxy'=>null];}
function queryfx_all($c,$sql,...$args){return [['attributes'=>json_encode($GLOBALS['control']->attributes)]];}
require$root.'/src/infrastructure/governance/recovery/PhorgeRecoverySessionProducerFence.php';
require$root.'/support/governance/recovery/borrowed-session-observer.php';
putenv('PHORGE_GOVERNANCE_ISOLATED_TEST=1');putenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION='.str_repeat('f',40));
$control=new C();$fixture=new PhorgeGovernanceCompletionFixture($control);$closes=0;$primary=new RuntimeException('host_primary');$same=false;$error=null;$children=[];
try{governance_recovery_borrowed_with_observer($fixture,$control,static function($observer){
 $GLOBALS['governance_recovery_borrowed_cleanup_proved']=$GLOBALS['mode']!=='unknown';
 if(in_array($GLOBALS['mode'],['primary','unknown','close-failure'],true))throw$GLOBALS['primary'];return'good';
});}catch(Throwable$e){$same=$e===$primary;$error=$e->getMessage();if($e instanceof PhutilAggregateException)foreach($e->getExceptions()as$k=>$v)$children[$k]=$v->getMessage();}
echo json_encode(['mode'=>$mode,'error'=>$error,'same_primary'=>$same,'aggregate'=>$children,'creates'=>$control->created,'grants'=>$control->grants,'drops'=>$control->dropped,'closes'=>$closes,'account_retained'=>$control->present,'observer_retained'=>(new ReflectionProperty(PhorgeRecoveryRestoreService::class,'borrowedObserver'))->getValue()!==null,'cleanup'=>$GLOBALS['governance_recovery_borrowed_fixture_cleanup'],'physical_SQL_executed'=>false]);

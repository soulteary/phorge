<?php
/** Actual opaque owner with declared SQL/fixture collaborators. No SQL/network. */
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
   $this->created++;$this->present=true;$this->attributes=json_decode(stripslashes($m[2]),true);
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
require$root.'/src/infrastructure/governance/recovery/PhorgeRecoverySessionProducerFence.php';require$root.'/support/governance/recovery/session-lease-observer.php';
putenv('PHORGE_GOVERNANCE_ISOLATED_TEST=1');putenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION='.str_repeat('f',40));
$c=new C();$owner=null;$error=null;$cleanupError=null;$facts=null;
try{$owner=GovernanceSessionLeaseObserverOwner::prepare(new PhorgeGovernanceCompletionFixture($c),$c,str_repeat('f',40));$config=$owner->configuration();if(!preg_match('/\Agov_c_metadata_[a-f0-9]{16}\z/D',$config['observer']['user']))throw new RuntimeException('host_bad_owner_configuration');}
catch(Throwable$e){$error=$e->getMessage();}
$owner=$owner??($GLOBALS['governance_session_lease_observer_owner']??null);
if($owner!==null){try{$facts=$owner->cleanup();}catch(Throwable$e){$cleanupError=$e->getMessage();}}
echo json_encode(array('error'=>$error,'cleanup_error'=>$cleanupError,'cleanup'=>$facts,'created'=>$c->created,'dropped'=>$c->dropped,'grant_count'=>count($c->grants),'thread_reads'=>$c->threadReads,'account_retained'=>$c->present,'physical_SQL_executed'=>false));

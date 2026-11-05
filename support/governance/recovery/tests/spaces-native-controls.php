<?php
/** Actual native classes via fixed source maps; synthetic rows/cache, no SQL. */
$root=$argv[1];$GLOBALS['spaces_control_paths']=array();
function phutil_register_library_map($map){foreach($map['class']as$class=>$path){$GLOBALS['spaces_control_paths'][$class]=$GLOBALS['spaces_control_base'].'/'.$path;}}
foreach(array('/support/runtime/src','/src')as$base){$GLOBALS['spaces_control_base']=$root.$base;require $root.$base.'/__phutil_library_map__.php';}
spl_autoload_register(function($class){if(isset($GLOBALS['spaces_control_paths'][$class])){require_once $GLOBALS['spaces_control_paths'][$class];}});
require $root.'/support/runtime/src/utils/utils.php';
require $root.'/src/infrastructure/governance/recovery/PhorgeRecoverySpacesSourceFacts.php';
function spaces_control_row($n=1,$default=1,$archived=0){return array('id'=>$n,'phid'=>'PHID-SPCE-'.str_repeat(chr(96+$n),20),'namespaceName'=>'Synthetic Space','viewPolicy'=>'users','editPolicy'=>'admin','isDefaultNamespace'=>$default,'dateCreated'=>1,'dateModified'=>1,'description'=>'SYNTHETIC_SECRET_DESCRIPTION','isArchived'=>$archived);}
function spaces_control_expect($value){if(!$value){throw new RuntimeException('spaces_control_failed');}}
$property=new ReflectionProperty(PhabricatorCaches::class,'requestCache');$original=$property->getValue();$checks=array();
try{
 $row=spaces_control_row();$space=(new PhabricatorSpacesNamespace())->loadFromArray($row);$rows=array($row['phid']=>$row);
 $method=new ReflectionMethod(PhorgeRecoverySpacesSourceFacts::class,'cacheFacts');$property->setValue(null,null);
 spaces_control_expect($method->invoke(null,$rows)===array('state'=>'uninitialized'));spaces_control_expect($property->getValue()===null);$checks[]='actual-uninitialized-cache-no-creation';
 $cache=new PhutilInRequestKeyValueCache();$property->setValue(null,$cache);
 spaces_control_expect((new ReflectionProperty(PhutilInRequestKeyValueCache::class,'limit'))->getValue($cache)===0);
 spaces_control_expect($method->invoke(null,$rows)===array('state'=>'native-request-cache','selected'=>array()));$checks[]='actual-initialized-empty-native-cache-and-default-limit';
 $cache->setKey(PhabricatorSpacesNamespaceQuery::KEY_ALL,array());$property->setValue(null,$cache);
 spaces_control_expect(PhabricatorSpacesNamespaceQuery::getAllSpaces()===array());spaces_control_expect(PhabricatorSpacesNamespaceQuery::getDefaultSpace()===null);
 spaces_control_expect($method->invoke(null,array())['selected'][PhabricatorSpacesNamespaceQuery::KEY_DEFAULT]===null);$checks[]='actual-empty-query-default-null';
 $filter=new PhabricatorPolicyFilter();$selection=new ReflectionMethod(PhabricatorPolicyFilter::class,'canViewerSeeObjectsInSpace');$omni=PhabricatorUser::getOmnipotentUser();
 spaces_control_expect($selection->invoke($filter,$omni,null)===true);spaces_control_expect($selection->invoke($filter,$omni,'PHID-SPCE-'.str_repeat('m',20))===false);$checks[]='actual-PolicyFilter-implicit-null-versus-explicit-missing';

 $cache->setKey(PhabricatorSpacesNamespaceQuery::KEY_ALL,array($row['phid']=>$space));$cache->deleteKeys(array(PhabricatorSpacesNamespaceQuery::KEY_DEFAULT));
 spaces_control_expect(PhabricatorSpacesNamespaceQuery::getDefaultSpace()===$space);$method->invoke(null,$rows);$checks[]='actual-query-default-and-private-row-binding';
 spaces_control_expect($selection->invoke($filter,$omni,null)===true);spaces_control_expect($selection->invoke($filter,$omni,$row['phid'])===true);spaces_control_expect($selection->invoke($filter,$omni,'PHID-SPCE-'.str_repeat('m',20))===false);$checks[]='actual-PolicyFilter-default-and-explicit-selection-omnipotent-not-ordinary-VIEW';

 $before=array((new ReflectionProperty(PhutilInRequestKeyValueCache::class,'cache'))->getValue($cache),(new ReflectionProperty(PhutilInRequestKeyValueCache::class,'ttl'))->getValue($cache));
 $method->invoke(null,$rows);
 spaces_control_expect($before===array((new ReflectionProperty(PhutilInRequestKeyValueCache::class,'cache'))->getValue($cache),(new ReflectionProperty(PhutilInRequestKeyValueCache::class,'ttl'))->getValue($cache)));
 spaces_control_expect($space->getNamespaceName()===$row['namespaceName']);$checks[]='collector-preserves-native-cache-and-object-state';
 $space->setIsArchived(1);$row['isArchived']=1;$rows=array($row['phid']=>$row);
 spaces_control_expect(PhabricatorSpacesNamespaceQuery::getAllSpaces()[$row['phid']]->getIsArchived()===1);$method->invoke(null,$rows);$checks[]='archived-remains-in-all-spaces';
 $cache->setKey('spaces.viewer(u.public)',array());$method->invoke(null,$rows);
 $cache->setKey('spaces.viewer(u.omnipotent)',array($row['phid']=>$space));$cache->setKey('spaces.viewer(u.PHID-USER-'.str_repeat('u',20).')',array());
 $method->invoke(null,$rows);$checks[]='viewer-subset-observed-not-policy-attested';
 foreach(array('unknown','ttl','stale','stale-default','provider','wrong-viewer-key','nondefault-limit')as$kind){
  $known=new PhutilInRequestKeyValueCache();$known->setKey('spaces.all',array($row['phid']=>$space));$property->setValue(null,$known);
  if($kind==='unknown'){$known->setKey('spaces.unknown',array());}
  if($kind==='ttl'){$known->setKey('spaces.all',array($row['phid']=>$space),10);}
  if($kind==='stale'){$wrong=clone$space;$wrong->setNamespaceName('stale');$known->setKey('spaces.all',array($row['phid']=>$wrong));}
  if($kind==='stale-default'){$known->setKey('spaces.default',null);}
  if($kind==='wrong-viewer-key'){$known->setKey('spaces.viewer(u.admin)',array());}
  if($kind==='nondefault-limit'){$known->setLimit(1);}
  if($kind==='provider'){$property->setValue(null,new stdClass());}
  $refused=false;try{$method->invoke(null,$rows);}catch(RuntimeException$error){$refused=str_starts_with($error->getMessage(),'recovery_spaces_');}spaces_control_expect($refused);
 }$checks[]='unknown-expiring-stale-default-viewer-provider-limit-refused';
 echo json_encode(array('passed'=>true,'checks'=>$checks,'SQL_executed'=>false,'production_authority'=>false))."\n";
}finally{$property->setValue(null,$original);}

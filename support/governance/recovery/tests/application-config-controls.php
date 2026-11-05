<?php
// Shared actual-native-class controls; explicitly synthetic backing, no SQL.
final class UnknownConfigFactsSource extends PhabricatorConfigSource {
 public function getKeys(array $keys){throw new Exception('GETTER_MUST_NOT_RUN');}
 public function getAllKeys(){throw new Exception('GETTER_MUST_NOT_RUN');}
}
function governance_config_facts_expect($yes){if(!$yes){throw new Exception('config_facts_host_assertion');}}
function governance_config_facts_refused(callable $call,$reason){try{$call();}catch(RuntimeException$e){governance_config_facts_expect($e->getMessage()===$reason);return;}throw new Exception('missing_refusal');}
function governance_config_facts_prepared(){
 $values=array('auth.require-email-verification'=>false,'auth.require-approval'=>true,
 'auth.email-domains'=>array(),'auth.lock-config'=>false,'account.editable'=>true,
 'account.minimum-password-length'=>8,'security.require-https'=>false,
 'security.require-multi-factor-auth'=>false,'governance.account-recovery.enabled'=>false,
 'governance.account-recovery.incarnation'=>null,'private.unselected'=>'SYNTHETIC_SECRET');
 $dictionary=new PhabricatorConfigDictionarySource($values);
 // Do not instantiate DefaultSource: its constructor loads arbitrary option providers.
 $default=(new ReflectionClass(PhabricatorConfigDefaultSource::class))->newInstanceWithoutConstructor();
 (new ReflectionProperty(PhabricatorConfigProxySource::class,'source'))->setValue($default,$dictionary);
 $stack=new PhabricatorConfigStackSource();$stack->pushSource($default);
 (new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);
 return array($stack,$dictionary);
}

function governance_recovery_config_facts_controls() {
$property=new ReflectionProperty(PhabricatorEnv::class,'sourceStack');$original=$property->getValue();$checks=array();
$cache_property=new ReflectionProperty(PhabricatorEnv::class,'cache');$original_cache=$cache_property->getValue();
try{
 list($stack,$dict)=governance_config_facts_prepared();$facts=PhorgeRecoveryApplicationConfigFacts::capture();$out=$facts->finish();
 governance_config_facts_expect($out['source_count']===1&&$out['selected_option_count']===10&&$out['complete']===false);
 governance_config_facts_expect(strpos(json_encode($out),'SYNTHETIC_SECRET')===false);$checks[]='native-private-values-public-summary';
 governance_config_facts_refused(fn()=>$facts->finish(),'recovery_config_facts_finished');$checks[]='terminal-reentry';
 foreach(array(null,'')as$value){list($stack,$dict)=governance_config_facts_prepared();$top=new PhabricatorConfigDictionarySource(array('governance.account-recovery.incarnation'=>$value));$stack->pushSource($top);
  $facts=PhorgeRecoveryApplicationConfigFacts::capture();$top->setKeys(array('governance.account-recovery.incarnation'=>$value===null?'':null));
  governance_config_facts_refused(fn()=>$facts->finish(),'recovery_config_facts_changed');}
 $checks[]='null-empty-distinct-first-source-wins';
 list($stack,$dict)=governance_config_facts_prepared();$dict->deleteKeys(array('auth.lock-config'));governance_config_facts_refused(fn()=>PhorgeRecoveryApplicationConfigFacts::capture(),'recovery_config_facts_dependency_missing');$checks[]='missing-not-null';
 list($stack,$dict)=governance_config_facts_prepared();$dict->setKeys(array('auth.lock-config'=>0));governance_config_facts_refused(fn()=>PhorgeRecoveryApplicationConfigFacts::capture(),'recovery_config_facts_type_unknown');$checks[]='bool-not-int';
 list($stack,$dict)=governance_config_facts_prepared();$stack->pushSource(new UnknownConfigFactsSource());governance_config_facts_refused(fn()=>PhorgeRecoveryApplicationConfigFacts::capture(),'recovery_config_facts_source_unknown');$checks[]='unknown-no-getter';
 list($stack,$dict)=governance_config_facts_prepared();$stack->pushSource((new ReflectionClass(PhabricatorConfigFileSource::class))->newInstanceWithoutConstructor());governance_config_facts_refused(fn()=>PhorgeRecoveryApplicationConfigFacts::capture(),'recovery_config_facts_source_unknown');$checks[]='dynamic-PHP-source-refused';
 list($stack,$dict)=governance_config_facts_prepared();$facts=PhorgeRecoveryApplicationConfigFacts::capture();$dict->setKeys(array('private.unselected'=>'CHANGED'));
 governance_config_facts_refused(fn()=>$facts->finish(),'recovery_config_facts_changed');$dict->setKeys(array('private.unselected'=>'SYNTHETIC_SECRET'));
 governance_config_facts_refused(fn()=>$facts->finish(),'recovery_config_facts_finished');$checks[]='unselected-change-terminal-failure';
 list($stack,$dict)=governance_config_facts_prepared();$stack->pushSource(new PhabricatorConfigDictionarySource(array('auth.lock-config'=>false)));
 $facts=PhorgeRecoveryApplicationConfigFacts::capture();$one=$stack->popSource();$stack->pushSource(new PhabricatorConfigDictionarySource(array('auth.lock-config'=>false)));
 governance_config_facts_refused(fn()=>$facts->finish(),'recovery_config_facts_changed');$checks[]='identical-values-object-replacement';
 list($stack,$dict)=governance_config_facts_prepared();$facts=PhorgeRecoveryApplicationConfigFacts::capture();governance_config_facts_refused(fn()=>serialize($facts),'recovery_config_facts_serialization_refused');
 ob_start();var_dump($facts);$debug=ob_get_clean();governance_config_facts_expect(strpos($debug,'SYNTHETIC_SECRET')===false);$checks[]='no-debug-or-serialized-secret';
 list($stack,$dict)=governance_config_facts_prepared();$stack->pushSource((new ReflectionClass(PhabricatorDeploymentConfigSource::class))->newInstanceWithoutConstructor());
 governance_config_facts_refused(fn()=>PhorgeRecoveryApplicationConfigFacts::capture(),'recovery_config_facts_source_unknown');$checks[]='actual-deployment-source-refused';
 list($stack,$dict)=governance_config_facts_prepared();$cache_property->setValue(null,array('auth.require-approval'=>false));
 governance_config_facts_expect(PhabricatorEnv::getEnvConfig('auth.require-approval')===false);
 $facts=PhorgeRecoveryApplicationConfigFacts::capture();$cache_property->setValue(null,array('auth.require-approval'=>true));
 governance_config_facts_expect($facts->finish()['complete']===false);$checks[]='native-cache-distinct-from-source-facts';
 list($stack,$dict)=governance_config_facts_prepared();$autoloads=0;$loader=function($class)use(&$autoloads){$autoloads++;};spl_autoload_register($loader);
 try{$dict->setKeys(array('private.callable'=>'NeverAutoloadConfig::method','private.callable-array'=>array('NeverAutoloadConfig','method')));
  governance_config_facts_expect(PhorgeRecoveryApplicationConfigFacts::capture()->finish()['complete']===false&&$autoloads===0);
 }finally{spl_autoload_unregister($loader);}$checks[]='primitive-callable-data-no-execution-or-autoload';
}finally{$property->setValue(null,$original);$cache_property->setValue(null,$original_cache);}
return $checks;

}

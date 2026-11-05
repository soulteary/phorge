<?php
// Actual native classes, synthetic dictionaries. No bootstrap extensions or SQL.
// The collector calls no Env getter; host controls call it for cache comparison.
$root=$argv[1];
require $root.'/support/runtime/src/object/Phobject.php';
foreach(array('PhabricatorConfigSource','PhabricatorConfigProxySource','PhabricatorConfigDictionarySource',
 'PhabricatorConfigStackSource','PhabricatorConfigDefaultSource','PhabricatorConfigLocalSource',
 'PhabricatorConfigDatabaseSource','PhabricatorConfigFileSource','PhabricatorDeploymentConfigSource','PhabricatorEnv')as$class){
 require $root.'/src/infrastructure/env/'.$class.'.php';}
require $root.'/src/infrastructure/governance/recovery/PhorgeRecoveryApplicationConfigFacts.php';
require __DIR__.'/application-config-controls.php';
echo json_encode(array('passed'=>true,'checks'=>governance_recovery_config_facts_controls(),'SQL_executed'=>false),JSON_THROW_ON_ERROR)."\n";

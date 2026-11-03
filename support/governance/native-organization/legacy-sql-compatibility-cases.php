<?php

/** Focused actual legacy-editor SQL profile, independent of optional sources. */
function governance_native_legacy_sql_compatibility_cases($fixture, callable $check) {
  $fixture->assertOwned();
  $conn=(new PhabricatorUser())->establishConnection('w');
  $version=queryfx_one($conn,'SELECT VERSION() version')['version'];
  $old_org=PhabricatorEnv::getEnvConfigIfExists('governance.organization.enabled',false);
  $old_media=PhabricatorEnv::getEnvConfigIfExists('governance.media-exchange.enabled',false);
  $epochs=array();
  try {
    PhabricatorEnv::overrideConfig('governance.organization.enabled',false);
    PhabricatorEnv::overrideConfig('governance.media-exchange.enabled',false);
    $suffix=substr($fixture->getNamespace(),-10);
    $actor=(new PhabricatorUser())->setUsername('compatadmin'.$suffix)
      ->setRealName('Synthetic Compatibility Admin')->setIsApproved(1)->setIsAdmin(1)->save();
    foreach (array('agent'=>'makeSystemAgentUser','mail'=>'makeMailingListUser') as $kind=>$method) {
      $user=(new PhabricatorUser())->setUsername('compat'.$kind.$suffix)
        ->setRealName('Synthetic Compatibility '.$kind)->setIsApproved(1)->save();
      $initial=queryfx_one($conn,'SELECT epoch FROM organization_identityepoch WHERE userPHID=%s',$user->getPHID());
      $check($initial===null,'native-legacy-sql-'.$kind.'-starts-with-known-no-epoch-row');
      foreach (array(true,false) as $value) {
        $current=(new PhabricatorUser())->loadOneWhere('phid=%s',$user->getPHID());
        (new PhabricatorUserEditor())->setActor($actor)->$method($current,$value);
        $check(!$conn->isInsideTransaction() && !$conn->isWriteLocking() && !$conn->isReadLocking(),
          'native-legacy-sql-'.$kind.'-'.($value?'set':'clear').'-no-transaction-or-lock-leak');
      }
      $row=queryfx_one($conn,'SELECT isSystemAgent,isMailingList FROM %R WHERE BINARY phid=BINARY %s',new PhabricatorUser(),$user->getPHID());
      $epoch=queryfx_one($conn,'SELECT epoch FROM organization_identityepoch WHERE userPHID=%s',$user->getPHID());
      $check((string)$row['isSystemAgent']==='0' && (string)$row['isMailingList']==='0' && (string)$epoch['epoch']==='3',
        'native-legacy-sql-'.$kind.'-actual-native-role-roundtrip-and-monotonic-epoch');
      $epochs[$kind]=(string)$epoch['epoch'];
    }
    require_once dirname(__DIR__).'/native-execution/config-epoch-cases.php';
    $config_epoch=governance_project_config_epoch_cases($fixture,$check,$actor);
    $check(PhabricatorEnv::getEnvConfig('governance.organization.enabled')===false &&
      PhabricatorEnv::getEnvConfig('governance.media-exchange.enabled')===false,
      'native-legacy-sql-epoch-path-active-with-optional-features-disabled');
  } finally {
    PhabricatorEnv::overrideConfig('governance.organization.enabled',$old_org);
    PhabricatorEnv::overrideConfig('governance.media-exchange.enabled',$old_media);
  }
  return array('server_version'=>$version,'vendor'=>stripos($version,'MariaDB')!==false?'MariaDB':'MySQL',
    'actual_native_config_generations'=>$config_epoch,'actual_native_legacy_editors_executed'=>true,'independent_legacy_role_epochs'=>$epochs,
    'optional_backend_compatibility_claimed'=>false,'production_configuration_changed'=>false);
}

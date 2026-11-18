<?php
// Mandatory fast boundary checks. No deployment boot, SQL, or feature fixture.
$root=dirname(__DIR__,2);
$report=array('schema'=>'atomic-core-native-guard-v1','passed'=>false,'flags'=>array(),'tests'=>array(),'scope'=>'defaults-off and native boundary only; not optional-feature qualification');
try {
  foreach(array('local.json','deployment.json','ENVIRONMENT')as$name){if(file_exists($root.'/conf/local/'.$name)){throw new RuntimeException('deployment_configuration_refused');}}
  if(getenv('PHABRICATOR_ENV')){throw new RuntimeException('deployment_environment_refused');}
  require_once $root.'/support/runtime/bootstrap-cli.php';phutil_load_library($root.'/src/');
  $keys=array('governance.organization.enabled','governance.media-exchange.http-enabled','governance.media-exchange.enabled','governance.project-approval.enabled','governance.notifications.enabled','governance.private-mail.enabled','governance.account-recovery.enabled','governance.account-recovery.owned-rr-scope.enabled','governance.account-recovery.session-lease.enabled','governance.project-content-grants.enabled','governance.task-content-grants.enabled','governance.recovery-stop-ledger.enabled');
  $options=PhabricatorApplicationConfigOptions::loadAllOptions();$defaults=(new PhabricatorConfigDefaultSource())->getKeys($keys);
  foreach($keys as$key){if(!isset($options[$key])||$options[$key]->getType()!=='bool'||$options[$key]->getDefault()!==false||$options[$key]->getLocked()!==true||!array_key_exists($key,$defaults)||$defaults[$key]!==false){throw new RuntimeException('default_OFF_boundary_refused');}$report['flags'][$key]=false;}
  // Reuse the original small, self-contained native tests. Calling the public
  // original method preserves its assertions; every Throwable (including skip)
  // is a failure here. No test body, factory or production class is replaced.
  $selected=array(
    array('PhorgeGovernanceMediaExchangeServiceTestCase','testConfigurationIsRegisteredDisabledAndLocked'),
    array('PhorgeGovernanceNoticeEventTestCase','testNativeRemovalAndNoopDoNotReadNoticeConfiguration'),
    array('PhorgeGovernanceNoticeEventTestCase','testNoticeSwitchIsActuallyRegisteredFalseAndLocked'),
    array('PhorgeRecoveryGuardTestCase','testEmergencyDisableDoesNotReadRecoveryConfiguration'),
    array('PhorgeProjectExecutionTestCase','testDefaultOptionAndPrivateShapeClassifier'),
    array('PhorgeProjectPolicyFailureTestCase','testNativeMFAAndCSRFFlowAndBackendFailuresRemainUnmapped'),
    array('PhorgeRecoveryFileSourceFactsTestCase','testNativeFileAuthorAutomaticIsNotAccountOrPolicyAdmission'),
    array('PhorgeRecoverySpacesSourceFactsTestCase','testNativePolicyFilterDefaultAndExplicitMissingSelection'),
  );
  $assertions=new ReflectionProperty(PhutilTestCase::class,'assertions');
  foreach($selected as$entry){list($class,$method)=$entry;$test=new $class();$r=new ReflectionMethod($class,$method);
    if(!$test instanceof PhutilTestCase||$test instanceof PhabricatorTestCase||!$r->isPublic()||$r->isStatic()||$r->getNumberOfRequiredParameters()!==0){throw new RuntimeException('native_core_test_selection_refused');}
    $test->setWorkingCopy(ArcanistWorkingCopyIdentity::newFromPath($root));$test->setEnableCoverage(false);
    $test->$method();$count=$assertions->getValue($test);if(!is_int($count)||$count<1){throw new RuntimeException('native_core_test_no_assertions');}
    $report['tests'][]=array('id'=>$class.'::'.$method,'result'=>'pass','assertions'=>$count);
  }
  if(count($report['tests'])!==count($selected)||count($report['flags'])!==12){throw new RuntimeException('native_core_incomplete');}
  $report['passed']=true;
}catch(Throwable $error){$report['error_class']=get_class($error);$report['error_message']=$error->getMessage();}
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
exit($report['passed']?0:1);

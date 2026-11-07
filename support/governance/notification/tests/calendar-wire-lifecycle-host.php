<?php
/** Actual cleanup function, explicit service-stop collaborators; no processes. */
require dirname(__DIR__,3).'/runtime/src/error/PhutilAggregateException.php';
require dirname(__DIR__).'/calendar-wire-hold.php';
$tests=0;$ok=static function($v)use(&$tests){if($v!==true){throw new RuntimeException('lifecycle_host_failed');}$tests++;};
$good=array('process_stopped'=>true,'private_storage_removed'=>true);$queue=(object)array('role'=>'queue');$sink=(object)array('role'=>'sink');
$seen=array();$scope=new stdClass();$result=governance_calendar_wire_cleanup($scope,array('queue'=>$queue,'capture'=>$sink),null,
 static function($handle)use(&$seen,$good){$seen[]=$handle;return$good;});
$ok($scope===null&&$seen===array($queue,$sink)&&$result===array('queue'=>$good,'capture'=>$good));
// Partial startup never adopts a different handle or attempts null capture.
$seen=array();$scope=null;$primary=new Error('primary-before-sink');
try{governance_calendar_wire_cleanup($scope,array('queue'=>$queue,'capture'=>null),$primary,
 static function($handle)use(&$seen,$good){$seen[]=$handle;return$good;});throw new RuntimeException('missing_primary');}
catch(Throwable $error){$ok($error===$primary&&$seen===array($queue));}
$queue_error=new Error('queue-stop');$sink_error=new RuntimeException('sink-stop');$seen=array();$scope=null;
try{governance_calendar_wire_cleanup($scope,array('queue'=>$queue,'capture'=>$sink),$primary,
 static function($handle)use(&$seen,$queue,$queue_error,$sink_error){$seen[]=$handle;throw $handle===$queue?$queue_error:$sink_error;});
 throw new RuntimeException('missing_aggregate');}
catch(PhutilAggregateException $error){$ok($error->getExceptions()===array('queue'=>$queue_error,'capture'=>$sink_error,'primary'=>$primary));
 $ok($seen===array($queue,$sink));}
// Unknown/partial/boolean-alias cleanup states must never authorize release.
foreach(array(null,array(),array('process_stopped'=>1,'private_storage_removed'=>true),
 array('process_stopped'=>true,'private_storage_removed'=>false))as$bad){$scope=null;
 try{governance_calendar_wire_cleanup($scope,array('queue'=>$queue),null,static fn($v)=>$bad);throw new RuntimeException('missing_denial');}
 catch(PhutilAggregateException $error){$ok(array_keys($error->getExceptions())===array('queue'));}}
$scope_failure=new Error('scope-close');$scope=new class($scope_failure){function __construct(public $failure){}function __destruct(){throw$this->failure;}};
$seen=array();try{governance_calendar_wire_cleanup($scope,array('queue'=>$queue,'capture'=>$sink),$primary,
 static function($v)use(&$seen,$good){$seen[]=$v;return$good;});throw new RuntimeException('missing_scope_denial');}
catch(PhutilAggregateException $error){$ok($error->getExceptions()===array('scope'=>$scope_failure,'primary'=>$primary));
 $ok($seen===array($queue,$sink));}
echo json_encode(array('passed'=>$tests,'SQL'=>false,'SMTP'=>false,'browser'=>false,'actual_process_cleanup'=>false),JSON_THROW_ON_ERROR)."\n";

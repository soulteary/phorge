<?php
/** Fixed owner signal only: the transaction-owning hold process performs SQL. */
try{
  $root=dirname(__DIR__,3);require_once$root.'/support/runtime/php-version.php';phorge_assert_supported_php_version(PHP_VERSION);
  foreach(array('PHABRICATOR_ENV','PHORGE_DEPLOYMENT_CONFIG','PHORGE_CONTROL_PLANE')as$key){$v=getenv($key);if($v!==false&&$v!==''){throw new RuntimeException('private_mail_pending_deployment_refused');}}
  foreach(array('local.json','deployment.json','ENVIRONMENT')as$name){if(file_exists($root.'/conf/local/'.$name)){throw new RuntimeException('private_mail_pending_deployment_refused');}}
  require_once __DIR__.'/pending-wire-protocol.php';list($directory,$owner)=governance_pending_wire_scope();
  if(count($argv)!==2||!in_array($argv[1],array('commit','rollback','aged','revoke'),true)){throw new RuntimeException('private_mail_pending_action_refused');}
  $state=governance_pending_wire_read($directory,$owner,'pending-state.json');
  if(!governance_pending_wire_allowed($state['case']??null,$state['state']??null,$argv[1])){throw new RuntimeException('private_mail_pending_action_refused');}
  governance_pending_wire_write($directory,$owner,'pending-command.json',array('owner'=>$owner,'case'=>$state['case'],
    'expected_state'=>$state['state'],'action'=>$argv[1],'request_id'=>bin2hex(random_bytes(16))));
  echo json_encode(array('passed'=>true,'action'=>$argv[1],'signal_queued'=>true,'sql_performed_by_cli'=>false),JSON_THROW_ON_ERROR)."\n";
}catch(Throwable$error){echo '{"passed":false,"reason":"private_mail_pending_control_refused"}'."\n";exit(1);}

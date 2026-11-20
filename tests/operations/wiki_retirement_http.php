<?php
$root = dirname(__DIR__, 2);
require $root.'/support/runtime/bootstrap-cli.php';
phutil_load_library($root.'/src/');
require $root.'/support/governance/completion/fixture.php';
require $root.'/support/governance/completion/pipeline-gorge.php';
if (getenv('PHORGE_GOVERNANCE_ISOLATED_TEST') !== '1') {
  throw new RuntimeException('isolated scope required');
}
foreach (array('PHABRICATOR_ENV','PHORGE_DEPLOYMENT_CONFIG','PHORGE_CONTROL_PLANE') as $key) {
  if (getenv($key)) { throw new RuntimeException('deployment selector refused'); }
}
foreach (array('local.json','deployment.json','ENVIRONMENT') as $name) {
  if (file_exists($root.'/conf/local/'.$name)) {
    throw new RuntimeException('deployment configuration refused');
  }
}
$control = new mysqli('127.0.0.1', getenv('PHORGE_GOVERNANCE_FIXTURE_USER'),
  getenv('PHORGE_GOVERNANCE_FIXTURE_PASSWORD'), '', 3306);
$fixture = new PhorgeGovernanceCompletionFixture($control, $root, '127.0.0.1', 3306);
$process = null;
$file_service = null;
$queue_service = null;
$private = sys_get_temp_dir().'/wiki-http-'.bin2hex(random_bytes(12));
mkdir($private, 0700);
$checks = array();
try {
  ob_start();
  try { $fixture->create(); } finally { ob_end_clean(); }
  $file_service = governance_completion_start_gorge($fixture, 'file',
    static function($ok, $name) { if (!$ok) { throw new RuntimeException($name); } });
  $queue_service = governance_completion_start_gorge($fixture, 'taskqueue',
    static function($ok, $name) { if (!$ok) { throw new RuntimeException($name); } });
  $writer = $fixture->getWriterConfig();
  $dictionary = array('mysql.host'=>$writer['host'], 'mysql.port'=>$writer['port'],
    'mysql.user'=>$writer['user'], 'mysql.pass'=>$writer['password'],
    'storage.default-namespace'=>$writer['namespace'],
    'phabricator.cache-namespace'=>$writer['namespace'], 'cluster.databases'=>array(),
    'policy.allow-public'=>false, 'auth.require-approval'=>true,
    'gorge.taskqueue.owner'=>'gorge', 'cluster.search'=>array(),
    'gorge.taskqueue.uri'=>$queue_service['uri'], 'gorge.taskqueue.token'=>$queue_service['token'],
    'metamta.gorge-delivery-mode'=>'native',
    'cluster.mailers'=>array(array('key'=>'isolated-serialization','type'=>'gorge',
      'options'=>array('uri'=>$queue_service['uri'],'token'=>$queue_service['token']))),
    'gorge.file.uri'=>$file_service['uri'], 'gorge.file.token'=>$file_service['token']);
  $socket = stream_socket_server('tcp://127.0.0.1:0');
  $address = stream_socket_get_name($socket, false);
  fclose($socket);
  $dictionary['phabricator.base-uri']='http://'.$address.'/';
  $stack = new PhabricatorConfigStackSource();
  $stack->pushSource(new PhabricatorConfigDefaultSource());
  $stack->pushSource(new PhabricatorConfigDictionarySource($dictionary));
  (new ReflectionProperty(PhabricatorEnv::class, 'sourceStack'))->setValue(null, $stack);
  (new ReflectionProperty(PhabricatorEnv::class, 'cache'))->setValue(null, array());
  $guard = new AphrontWriteGuard(static function() use ($fixture) { $fixture->assertOwned(); });
  $user = (new PhabricatorUser())->setUsername('wikiacceptance')->setRealName('Synthetic acceptance')
    ->setIsApproved(1)->setIsEmailVerified(1)->save();
  $session = (new PhabricatorAuthSessionEngine())->establishSession(
    PhabricatorAuthSession::TYPE_WEB, $user->getPHID(), false);
  $task = ManiphestTask::initializeNewTask($user)->setTitle('Retained task smoke')
    ->setOwnerPHID($user->getPHID())->save();
  (new PhabricatorUserEmail())->setUserPHID($user->getPHID())
    ->setAddress('wiki-acceptance@example.invalid')->setIsPrimary(1)->setIsVerified(1)->save();
  $file=PhabricatorFile::newFromFileData('Synthetic retained file',array(
    'name'=>'retained.txt','authorPHID'=>$user->getPHID(),'viewPolicy'=>'users'));
  $queue_checks=0;
  foreach (array(false,true) as $policy) {
    $scope=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.private-mail.enabled',$policy);
    try {
      $mail=(new PhabricatorMetaMTAMail())->setPrivateMailProducer('PhabricatorFileEditor')
        ->setRelatedPHID($file->getPHID())->setFrom($user->getPHID())
        ->addTos(array($user->getPHID()))->setSubject('Synthetic historical mail')
        ->setBody('Synthetic mail payload')->save();
      $old_snapshot=$mail->prepareGorgeDelivery();
      if (!isset($old_snapshot['deliveryID']) || $mail->authorizeGorgeDelivery()!==array('changed'=>false)) {
        throw new RuntimeException('real prepared snapshot required');
      }
      $mail->setPrivateMailProducer('PhrictionTransactionEditor')
        ->setRelatedPHID('PHID-WIKI-aaaaaaaaaaaaaaaaaaaa')->save();
      if ($mail->authorizeGorgeDelivery()!==array('changed'=>true)) {
        throw new RuntimeException('retired prepared authorization accepted');
      }
      if ($mail->prepareGorgeDelivery()!==array('state'=>'void')) {
        throw new RuntimeException('retired queue payload accepted');
      }
      $stored=(new PhabricatorMetaMTAMail())->load($mail->getID());
      if ($stored->getPrivateMailPolicyValue('governance.delivery-quarantined')!==true ||
          $stored->getParameters()['gorge.delivery-snapshot']!==$old_snapshot ||
          $stored->prepareGorgeDelivery()!==array('state'=>'void')) {
        throw new RuntimeException('retired queue quarantine or evidence changed');
      }
      $queue_checks++;
    } finally { unset($scope); }
  }
  $nonce = $fixture->getAdmin()->query('SELECT owner_nonce FROM '.$fixture->table('governance','fixture_guard').' WHERE id=1')->fetch_assoc()['owner_nonce'];
  $paths = array('/w/', '/w/retired/', '/phriction/', '/T'.$task->getID(), '/maniphest/', '/project/');
  file_put_contents($private.'/config.json', json_encode(array('dictionary'=>$dictionary,
    'host'=>$address, 'paths'=>$paths, 'nonce'=>$nonce)));
  chmod($private.'/config.json',0600);
  $env = getenv();
  $env['WIKI_RETIREMENT_HTTP_FIXTURE']=$private;
  $process=proc_open(array(PHP_BINARY,'-S',$address,__DIR__.'/wiki_retirement_http_router.php'),
    array(0=>array('pipe','r'),1=>array('file',$private.'/server.log','a'),2=>array('file',$private.'/server.log','a')),
    $pipes,$root,$env);
  fclose($pipes[0]);
  for ($attempt=0;$attempt<50;$attempt++) {
    $connection=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);
    if ($connection) { fclose($connection); break; }
    usleep(100000);
  }
  foreach ($paths as $path) {
    $curl=curl_init('http://'.$address.$path);
    curl_setopt_array($curl,array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,
      CURLOPT_PROXY=>'',CURLOPT_HTTPHEADER=>array('Cookie: phusr='.$user->getUsername().'; phsid='.$session)));
    $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);unset($curl);
    $expected=in_array($path,array('/w/','/w/retired/','/phriction/'),true)?404:200;
    if ($status!==$expected || str_contains((string)$body,'isolated_http_fixture_failed')) {
      if (is_file($private.'/failure.json')) { fwrite(STDERR, file_get_contents($private.'/failure.json')."\n"); }
      throw new RuntimeException('HTTP regression '.$path.' status '.$status.' expected '.$expected);
    }
    if ((str_starts_with($path,'/T') || $path==='/maniphest/') &&
        !str_contains((string)$body,'Retained task smoke')) {
      throw new RuntimeException('authenticated task content missing');
    }
    if ($path==='/project/' && !str_contains((string)$body,'Projects')) {
      throw new RuntimeException('project page content missing');
    }
    $checks[]=$path;
  }
  echo json_encode(array('passed'=>true,'http_checks'=>count($checks),'historical_queue_checks'=>$queue_checks,'synthetic_data_only'=>true))."\n";
} finally {
  if (is_resource($process)) { proc_terminate($process); proc_close($process); }
  if (isset($guard)) { $guard->dispose(); }
  LiskDAO::closeAllConnections();
  if ($queue_service) { governance_completion_stop_gorge($queue_service); }
  if ($file_service) { governance_completion_stop_gorge($file_service); }
  $fixture->cleanup();
  foreach (array('config.json','server.log','failure.json') as $name) { if (is_file($private.'/'.$name)) { unlink($private.'/'.$name); } }
  rmdir($private);
  $control->close();
}

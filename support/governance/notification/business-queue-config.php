<?php
/** Fixture-only child profile: genuine native mail adoption, no delivery worker. */
function governance_private_business_queue_scope($directory,$owner){
  $path=$directory.'/queue.json';
  if(!is_file($path)||is_link($path)||(fileperms($path)&0777)!==0600||filesize($path)>1024){
    throw new RuntimeException('business_publication_queue_config_refused');
  }
  $data=json_decode(file_get_contents($path),true,4,JSON_THROW_ON_ERROR);
  if(!is_array($data)||array_keys($data)!==array('owner','uri','token')||$data['owner']!==$owner||
    !is_string($data['uri'])||!preg_match('/\Ahttp:\/\/127\.0\.0\.1:([1-9][0-9]{0,4})\z/D',$data['uri'],$match)||
    (int)$match[1]>65535||!is_string($data['token'])||!preg_match('/\A[a-f0-9]{64}\z/D',$data['token'])){
    throw new RuntimeException('business_publication_queue_config_refused');
  }
  $scope=PhabricatorEnv::beginScopedEnv()
    ->overrideEnvConfig('gorge.taskqueue.owner','gorge')
    ->overrideEnvConfig('gorge.taskqueue.uri',$data['uri'])
    ->overrideEnvConfig('gorge.taskqueue.token',$data['token']);
  if(!PhabricatorGorgeTaskQueueClient::isConfigured()||
    !is_array((new PhabricatorGorgeTaskQueueClient())->getStats())){
    throw new RuntimeException('business_publication_queue_http_refused');
  }
  return $scope;
}

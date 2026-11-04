<?php
/** Isolated fixture extension; never a production configuration loader. */
require_once __DIR__.'/owned-config.php';
function governance_notice_wire_case($case){
  if(!is_string($case)||!in_array($case,array('allow','admin-revoked','disabled','disable-enable',
    'primary-changed','source-changed','between-attempts','config-notice-error','config-private-mail-error'),true)){
    throw new RuntimeException('notice_wire_case_refused');}
  return$case;
}
function governance_notice_wire_json($directory,$name,$limit=65536){
  if(!in_array($name,array('notice.json','notice-control.json','notice-fault.json'),true)){throw new RuntimeException('notice_wire_file_refused');}
  $path=$directory.'/'.$name;
  if(!is_file($path)||is_link($path)||(fileperms($path)&0777)!==0600||filesize($path)>$limit){
    throw new RuntimeException('notice_wire_private_file_refused');}
  return json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
}
function governance_notice_wire_config($directory){
  $base=governance_private_mail_config($directory);$extra=governance_notice_wire_json($directory,'notice.json');
  $keys=array_keys($extra);sort($keys);
  if($keys!==array('case','owner','queue_token','queue_uri','stable_capture_version')||
    ($extra['owner']??null)!==$base['owner']||($extra['stable_capture_version']??null)!=='v0.14.0'||
    !is_string($extra['queue_token'])||!preg_match('/\A[a-f0-9]{64}\z/D',$extra['queue_token'])||
    !is_string($extra['queue_uri'])||!preg_match('@\Ahttp://127\.0\.0\.1:([1-9][0-9]{0,4})\z@D',$extra['queue_uri'],$port)||
    (int)$port[1]>65535){throw new RuntimeException('notice_wire_config_refused');}
  governance_notice_wire_case($extra['case']);return array('base'=>$base,'notice'=>$extra,'directory'=>$directory);
}
/** Real config-source failure, with no synthetic authorization decision. */
function governance_notice_wire_install_env(array$config){
  governance_private_mail_install_env($config['base']);
  governance_notice_wire_apply_overrides($config['notice']);
}
function governance_notice_wire_apply_overrides(array$notice){
  $overrides=array('governance.notifications.enabled'=>true,'governance.organization.enabled'=>true,
    'governance.account-recovery.enabled'=>false,'governance.recovery-stop-ledger.enabled'=>false,
    'gorge.taskqueue.owner'=>'gorge','gorge.taskqueue.uri'=>$notice['queue_uri'],
    'gorge.taskqueue.token'=>$notice['queue_token'],
    'gorge.service-policies'=>array('file'=>'required','mailer'=>'required','conduit'=>'required','taskqueue'=>'required'));
  foreach($overrides as$key=>$value){PhabricatorEnv::overrideConfig($key,$value);}
}

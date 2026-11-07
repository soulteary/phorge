<?php
/** Isolated remote-only native fixture profile. Does not authorize public routing. */
function governance_media_remote_validate_profile(array $value){
  $keys=array_keys($value);sort($keys);
  if($keys!==array('app_origin','file_origin','origin_review_sha256','scope')||
    $value['scope']!=='owned-private-media-remote-native-fixture'||
    !is_string($value['origin_review_sha256'])||preg_match('/\A[a-f0-9]{64}\z/D',$value['origin_review_sha256'])!==1){
    throw new RuntimeException('media_remote_profile_refused');}
  foreach(array('app_origin','file_origin')as$key){
    $origin=PhorgeGovernanceMediaHTTPProtocol::origin($value[$key]);
    if($origin!==$value[$key]){throw new RuntimeException('media_remote_noncanonical_origin');}
    $host=(new PhutilURI($origin))->getDomain();
    if($host==='localhost'||str_ends_with($host,'.localhost')||filter_var($host,FILTER_VALIDATE_IP)!==false){
      throw new RuntimeException('media_remote_dedicated_host_required');}
  }
  PhorgeGovernanceMediaHTTPProtocol::assertSeparateOrigins($value['app_origin'],$value['file_origin']);
  return $value;
}
function governance_media_remote_profile(){
  if(PHP_SAPI!=='cli'||getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')!=='1'){
    throw new RuntimeException('media_remote_cli_scope_refused');}
  return governance_media_remote_validate_profile(array('scope'=>'owned-private-media-remote-native-fixture',
    'app_origin'=>getenv('PHORGE_GOVERNANCE_HTTP_REMOTE_APP_ORIGIN'),
    'file_origin'=>getenv('PHORGE_GOVERNANCE_HTTP_REMOTE_FILE_ORIGIN'),
    'origin_review_sha256'=>getenv('PHORGE_GOVERNANCE_HTTP_REMOTE_ORIGIN_REVIEW_SHA256')));
}
function governance_media_remote_release_after_SQL_zero($fixture){
  $writer=$fixture->getWriterConfig();$query=$GLOBALS['control']->prepare('SELECT COUNT(*) n FROM information_schema.PROCESSLIST WHERE USER=?');
  $name=$writer['user'];$query->bind_param('s',$name);
  try{$query->execute();$row=$query->get_result()->fetch_assoc();}finally{$query->close();}
  if(!is_array($row)||!in_array($row['n']??null,array(0,'0'),true)){
    throw new RuntimeException('media_remote_writer_SQL_not_zero');}
  $GLOBALS['governance_workbench_http_cleanup_permitted']=true;
}
function governance_media_remote_self_process_identity(){
  $pid=getmypid();$raw=file_get_contents('/proc/self/stat');$end=strrpos($raw,')');
  if($end===false){throw new RuntimeException('media_remote_process_identity_unknown');}
  $fields=explode(' ',substr($raw,$end+2));$start=$fields[19]??null;
  if(!is_string($start)||preg_match('/\A[1-9][0-9]{0,19}\z/D',$start)!==1){
    throw new RuntimeException('media_remote_process_identity_unknown');}
  return array('pid'=>(string)$pid,'start'=>$start);
}

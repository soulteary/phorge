<?php
/** Owner-only fixture signaling. Never an HTTP or production control API. */
function governance_pending_wire_scope(){
  if(PHP_SAPI!=='cli'||getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')!=='1'){throw new RuntimeException('private_mail_pending_scope_refused');}
  $directory=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_DIR');$owner=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_OWNER');
  if(!is_string($directory)||is_link($directory)||realpath($directory)!==$directory||realpath(dirname($directory))!==realpath(sys_get_temp_dir())||
    !preg_match('/\Aphorge-private-mail-wire-[a-f0-9]{24}\z/D',basename($directory))||(fileperms($directory)&0777)!==0700||
    !is_string($owner)||!preg_match('/\A[a-f0-9]{64}\z/D',$owner)){throw new RuntimeException('private_mail_pending_owner_refused');}
  $path=$directory.'/owner';if(!is_file($path)||is_link($path)||(fileperms($path)&0777)!==0600||filesize($path)!==64||!hash_equals($owner,file_get_contents($path))){throw new RuntimeException('private_mail_pending_owner_refused');}
  return array($directory,$owner);
}
function governance_pending_wire_read($directory,$owner,$name){
  if(!in_array($name,array('pending-state.json','pending-command.json'),true)){throw new RuntimeException('private_mail_pending_file_refused');}
  $path=$directory.'/'.$name;if(!is_file($path)||is_link($path)||(fileperms($path)&0777)!==0600||filesize($path)>4096){throw new RuntimeException('private_mail_pending_file_refused');}
  $data=json_decode(file_get_contents($path),true,8,JSON_THROW_ON_ERROR);
  if(!is_array($data)||($data['owner']??null)!==$owner){throw new RuntimeException('private_mail_pending_file_owner_refused');}
  return$data;
}
function governance_pending_wire_write($directory,$owner,$name,array $data,$replace=false){
  if(!in_array($name,array('pending-state.json','pending-command.json'),true)||($data['owner']??null)!==$owner){throw new RuntimeException('private_mail_pending_file_refused');}
  $target=$directory.'/'.$name;if(file_exists($target)){if(!$replace){throw new RuntimeException('private_mail_pending_existing_file');}governance_pending_wire_read($directory,$owner,$name);}
  $temporary=$directory.'/.pending-'.bin2hex(random_bytes(16)).'.tmp';$stream=null;
  try{$stream=fopen($temporary,'x');if(!$stream||!chmod($temporary,0600)){throw new RuntimeException('private_mail_pending_write_failed');}
    $json=json_encode($data,JSON_THROW_ON_ERROR);if(fwrite($stream,$json)!==strlen($json)){throw new RuntimeException('private_mail_pending_write_failed');}
    fclose($stream);$stream=null;if(!rename($temporary,$target)){throw new RuntimeException('private_mail_pending_write_failed');}
  }finally{if(is_resource($stream)){fclose($stream);}if(is_file($temporary)){unlink($temporary);}}
}
function governance_pending_wire_allowed($case,$state,$action){
  return($case==='commit'&&$state==='holding'&&$action==='commit')||
    ($case==='rollback'&&$state==='holding'&&$action==='rollback')||($case==='rollback'&&$state==='rolled-back'&&$action==='aged')||
    (in_array($case,array('recipient-revoked','admin-revoked'),true)&&$state==='holding'&&$action==='revoke');
}

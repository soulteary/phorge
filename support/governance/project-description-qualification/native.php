<?php
/** Source-only entry: Root must create a reviewed successor with exact profile pin. */
const N05_DESCRIPTION_ROOT_PROFILE_SHA256=null;
if(defined('N05_DESCRIPTION_QUALIFICATION_LIBRARY_ONLY')&&N05_DESCRIPTION_QUALIFICATION_LIBRARY_ONLY===true){require __DIR__.'/cases.php';return;}
try {
 if($argc!==3||N05_DESCRIPTION_ROOT_PROFILE_SHA256===null)throw new RuntimeException('description_qualification_not_admitted');
 [$script,$root,$profile]=$argv;
 if(realpath($root)!==$root||realpath($profile)!==$profile||hash_file('sha256',$profile)!==N05_DESCRIPTION_ROOT_PROFILE_SHA256)throw new RuntimeException('description_qualification_not_admitted');
 define('N05_QUALIFICATION_LIBRARY_ONLY',true);require __DIR__.'/../project-content-grants-qualification/native.php';
 $raw=qprivate($profile);qneed(hash('sha256',$raw)===N05_DESCRIPTION_ROOT_PROFILE_SHA256);$p=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
 qneed($p['schema']==='N05-native-description-qualification-v1'&&$p['source_root']===$root&&$p['isolated_features_authorized']===true);
 qneed(preg_match('/\A[a-f0-9]{32}\z/D',$p['owner'])&&$p['namespace']==='n05_grants_'.$p['owner']);
 qneed($p['HTTP_authorized']===false&&$p['worker_delivery_authorized']===false);
 // Source/file pins are verified BEFORE native initialization or mutation.
 foreach($p['source_sha256']as$path=>$digest){qneed(is_string($path)&&preg_match('/\A(?:src|support|resources)\/[a-zA-Z0-9_.\/-]+\z/D',$path)&&!str_contains($path,'..')&&realpath($root.'/'.$path)===$root.'/'.$path&&preg_match('/\A[a-f0-9]{64}\z/D',$digest)&&hash_file('sha256',$root.'/'.$path)===$digest);}
 qneed(isset($p['source_sha256']['src/infrastructure/governance/content/PhorgeProjectDescriptionExecutor.php'])&&isset($p['source_sha256']['support/governance/project-description-qualification/cases.php']));
 $receipt=qprivate($p['owned_db_receipt_path']);qneed(hash('sha256',$receipt)===$p['owned_db_receipt_sha256']);$db=json_decode($receipt,true,8,JSON_THROW_ON_ERROR);
 qneed($db['schema']==='Root-owned-local-MySQL-binding-v1'&&$db['owner']===$p['owner']&&$db['host_ip']==='127.0.0.1'&&$db['server_uuid']===$p['server_uuid']&&preg_match('/\A[a-f0-9]{64}\z/D',$db['container_id']));
 $argv=array($script);$argc=1;require $root.'/scripts/init/init-script.php';require __DIR__.'/cases.php';
 qneed(PHP_VERSION==='8.5.12'&&in_array(ini_get('opcache.jit'),array('','0','disable','off'),true)&&(string)ini_get('opcache.jit_buffer_size')==='0'&&(string)ini_get('opcache.enable_cli')==='0');
 qneed(PhabricatorEnv::getEnvConfig('mysql.host')==='127.0.0.1'&&PhabricatorEnv::getEnvConfig('mysql.port')===$db['host_port']&&PhabricatorEnv::getEnvConfig('load-libraries')===array());
 qneed(PhabricatorEnv::getEnvConfig('governance.organization.enabled')===true&&PhabricatorEnv::getEnvConfig('governance.project-approval.enabled')===true&&PhabricatorEnv::getEnvConfig('governance.project-content-grants.enabled')===true);
 $c=qconnection($p);qneed(queryfx_one($c,'SELECT VERSION() v')['v']==='8.4.13');
 PhabricatorWorker::setRunAllTasksInProcess(false);$unguarded=AphrontWriteGuard::beginScopedUnguardedWrites();
 // Existing two admins were created and empowered via standard native bootstrap.
 // Create ONLY an authentic nonadmin recipient token using the native factory.
 $adminraw=qprivate($p['admin_actors_path']);qneed(hash('sha256',$adminraw)===$p['admin_actors_sha256']);$actors=json_decode($adminraw,true,8,JSON_THROW_ON_ERROR);qneed(array_keys($actors)===array('requester','approver'));
 $p['actors_path']=$p['admin_actors_path'];$p['actors_sha256']=$p['admin_actors_sha256'];dactor($p,'requester');dactor($p,'approver');
 $recipient=(new PhabricatorUser())->loadOneWhere('phid=%s',$p['subjects']['user']);qneed($recipient!==null&&$recipient->getIsApproved()&&!$recipient->getIsDisabled()&&!$recipient->getIsAdmin());dcapabilities($recipient,dproject($p));
 $token=PhabricatorConduitToken::initializeNewToken($recipient->getPHID(),PhabricatorConduitToken::TYPE_COMMANDLINE);$token->save();
 $actors['recipient']=array('user_phid'=>$recipient->getPHID(),'token'=>$token->getToken());$out=$p['recipient_actors_output'];qneed(realpath(dirname($out))===dirname($out)&&!file_exists($out));$dir=lstat(dirname($out));qneed($dir&&($dir['mode']&0177777)===0040700&&$dir['uid']===posix_geteuid());
 $old=umask(0077);try{$h=fopen($out,'xb');}finally{umask($old);}qneed($h!==false);$bytes=json_encode($actors,JSON_THROW_ON_ERROR);try{qneed(fwrite($h,$bytes)===strlen($bytes)&&fflush($h)&&fsync($h));}finally{fclose($h);}
 $p['actors_path']=$out;$p['actors_sha256']=hash('sha256',$bytes);qneed(qprivate($out)===$bytes);
 try{$result=dcases($p);}finally{unset($unguarded);}
 echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
}catch(Throwable$e){
 // No raw exception, token, source root, target/user IDs or SQL text is public.
 fwrite(STDERR,"description_qualification_refused_retain_no_retry\n");exit(1);
}

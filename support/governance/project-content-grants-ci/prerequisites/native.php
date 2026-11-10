<?php
/** Fixed native bootstrap only. No fabricated actor source or grant-table writes. */
function nneed($v){if(!$v)throw new RuntimeException('native_prerequisites_refused');}
function nprivate($p){
 nneed(realpath($p)===$p);$s=lstat($p);nneed($s&&($s['mode']&0177777)===0100600&&$s['uid']===posix_geteuid()&&$s['nlink']===1&&$s['size']>0&&$s['size']<=65536);
 $h=fopen($p,'rb');nneed($h!==false);try{$b=stream_get_contents($h,65537);$f=fstat($h);clearstatcache(true,$p);$n=lstat($p);foreach(['dev','ino','mode','uid','gid','nlink','size','mtime','ctime']as$k)nneed($s[$k]===$f[$k]&&$s[$k]===$n[$k]);nneed(strlen($b)===$s['size']);return$b;}finally{fclose($h);}
}
function nwrite($p,$raw){
 nneed(realpath(dirname($p))===dirname($p)&&!file_exists($p));$old=umask(0077);try{$h=fopen($p,'xb');}finally{umask($old);}nneed($h!==false);
 try{$i=0;while($i<strlen($raw)){$n=fwrite($h,substr($raw,$i));nneed($n>0);$i+=$n;}nneed(fflush($h)&&fsync($h));}finally{fclose($h);}nneed(nprivate($p)===$raw);
}
function nnames($owner){nneed(preg_match('/\A[a-f0-9]{32}\z/D',$owner));$d=[];foreach(['requester','approver','recipient']as$r)$d[$r]='n05_'.$r.'_'.$owner;return$d;}
function nnewuser($username){
 nneed(PhabricatorUser::validateUsername($username));
 return[(new PhabricatorUser())->setUsername($username)->setRealName('Isolated N05 '.$username),(new PhabricatorUserEmail())->setAddress($username.'@fixture.invalid')->setIsVerified(0)];
}
function ncredentials(array$p){$raw=nprivate($p['db_credentials_path']);nneed(hash('sha256',$raw)===$p['db_credentials_sha256']);$d=json_decode($raw,true,8,JSON_THROW_ON_ERROR);nneed($d['owner']===$p['owner']);return$d;}
function nempty(array$p){
 // Actual metadata reads only. All schema creation belongs to standard bin/storage.
 $d=ncredentials($p);mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$c=new mysqli('127.0.0.1',$d['user'],$d['password'],'',$p['db_port']);
 try{$r=$c->query('SELECT @@server_uuid uuid')->fetch_assoc();nneed($r['uuid']===$p['server_uuid']);$prefix=$p['namespace'].'_';$len=strlen($prefix);$s=$c->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE LEFT(SCHEMA_NAME,?)=?');try{$s->bind_param('is',$len,$prefix);$s->execute();nneed($s->get_result()->num_rows===0);}finally{$s->close();}}finally{$c->close();}
 return['owned_namespace_absent'=>true,'execution_authorized'=>false];
}
function nconnection(array$p,$table){
 $c=$table->establishConnection('w');nneed(get_class($c)===AphrontMySQLiDatabaseConnection::class);$r=queryfx_one($c,'SELECT @@server_uuid uuid,DATABASE() db');nneed($r['uuid']===$p['server_uuid']&&str_starts_with($r['db'],$p['namespace'].'_'));return$c;
}
function nprofile(array$p){
 $d=ncredentials($p);nneed(PhabricatorEnv::getEnvConfig('storage.default-namespace')===$p['namespace']&&PhabricatorEnv::getEnvConfig('mysql.host')==='127.0.0.1'&&PhabricatorEnv::getEnvConfig('mysql.port')===$p['db_port']&&PhabricatorEnv::getEnvConfig('mysql.user')===$d['user']&&PhabricatorEnv::getEnvConfig('mysql.pass')===$d['password']);
 nneed(PhabricatorEnv::getEnvConfig('cluster.databases')===[]&&PhabricatorEnv::getEnvConfig('load-libraries')===[]&&PhabricatorEnv::getEnvConfig('governance.project-approval.enabled')===false&&PhabricatorEnv::getEnvConfig('governance.organization.enabled')===true);
 nneed(PhabricatorEnv::getEnvConfigIfExists('governance.project-content-grants.enabled',false)===false);
 foreach([new PhabricatorUser(),new PhabricatorConduitToken(),new PhabricatorProject(),new PhorgeOrganizationUnit(),new PhorgeOrganizationMember()]as$t)nconnection($p,$t);
}
function nusers(array$p){
 $ids=[];$actor=PhabricatorUser::getOmnipotentUser(); // Standard local management bootstrap actor, never an app credential.
 foreach(nnames($p['owner'])as$role=>$name){
  nneed((new PhabricatorUser())->loadOneWhere('userName=%s',$name)===null);[$u,$email]=nnewuser($name);
  nneed(!$u->getIsAdmin()&&!$u->getIsApproved());(new PhabricatorUserEditor())->setActor($actor)->createNewUser($u,$email);$ids[$role]=$u->getPHID();
 }
 return['created_users'=>$ids,'execution_authorized'=>false];
}
function nuser(array$p,$role){$names=nnames($p['owner']);nneed(isset($names[$role]));$u=(new PhabricatorUser())->loadOneWhere('userName=%s',$names[$role]);nneed($u!==null&&$u->getIsApproved()&&!$u->getIsDisabled()&&!$u->getIsSystemAgent()&&!$u->getIsMailingList());nneed((bool)$u->getIsAdmin()===($role!=='recipient'));return$u;}
function ntokens(array$p,$out){
 $actors=[];
 foreach(['requester','approver']as$role){$u=nuser($p,$role);$token=PhabricatorConduitToken::initializeNewToken($u->getPHID(),PhabricatorConduitToken::TYPE_COMMANDLINE);$token->save();nneed(preg_match('/\Acli-[a-zA-Z0-9]{28}\z/D',$token->getToken()));$actors[$role]=['user_phid'=>$u->getPHID(),'token'=>$token->getToken()];}
 nneed($actors['requester']['user_phid']!==$actors['approver']['user_phid']);$path=$out.'/actors.private.json';nwrite($path,json_encode($actors,JSON_THROW_ON_ERROR)."\n");return['actors_sha256'=>hash_file('sha256',$path),'execution_authorized'=>false];
}
function nservices(array$p,$out,array$chain){
 $raw=nprivate($out.'/actors.private.json');nneed(hash('sha256',$raw)===$chain['actors_sha256']);$a=json_decode($raw,true,8,JSON_THROW_ON_ERROR);$services=[];
 foreach(['requester','approver']as$r){$u=nuser($p,$r);nneed($a[$r]['user_phid']===$u->getPHID());$ctx=PhorgeGovernanceNativeActorContext::newFromAuthenticatedAPIToken($u,new PhutilOpaqueEnvelope($a[$r]['token']));$service=PhorgeOrganizationService::newForActor($ctx);nneed($service->readActorCapabilities()['manage_units']===true);$services[$r]=$service;}
 return$services;
}
function nteam(array$p){return'n05-team-'.$p['owner'];}
function nprojectname(array$p){return'N05 qualification '.$p['owner'];}
function nstructure(array$p,$out,array$chain){
 $services=nservices($p,$out,$chain);$service=$services['requester'];$team=nteam($p);nneed((new PhorgeOrganizationUnit())->loadOneWhere('unitID=%s',$team)===null);
 $service->createUnit(['operation_id'=>'n05-create-team-'.$p['owner'],'unit_id'=>$team,'kind'=>'team','name'=>'Isolated N05 team','parent_id'=>null]);
 $row=(new PhorgeOrganizationUnit())->loadOneWhere('unitID=%s',$team);nneed($row!==null);$now=(int)queryfx_one(nconnection($p,new PhabricatorUser()),'SELECT UNIX_TIMESTAMP() n')['n'];$recipient=nuser($p,'recipient');
 $service->execute(['operation_id'=>'n05-add-member-'.$p['owner'],'mutations'=>[['action'=>'member.add','approval_id'=>null,'expected_appointment_version'=>0,'expected_member_version'=>0,'expected_team_version'=>(int)$row->getVersion(),'target_user'=>$recipient->getPHID(),'team'=>$team,'valid_from'=>$now,'valid_until'=>null]]]);
 $name=nprojectname($p);nneed((new PhabricatorProject())->loadOneWhere('name=%s',$name)===null);$actor=nuser($p,'requester');$project=PhabricatorProject::initializeNewProject($actor);
 $x=$project->getApplicationTransactionTemplate()->setTransactionType(PhabricatorProjectNameTransaction::TRANSACTIONTYPE)->setNewValue($name);
 $project->getApplicationTransactionEditor()->setActor($actor)->setContentSource(PhabricatorContentSource::newForSource(PhabricatorConsoleContentSource::SOURCECONST))->setContinueOnMissingFields(true)->applyTransactions($project,[$x]);
 nneed($project->getID()&&$project->getPHID());return['target_phid'=>$project->getPHID(),'execution_authorized'=>false];
}
function nreadback(array$p,$out,array$chain){
 nservices($p,$out,$chain);$recipient=nuser($p,'recipient');$project=(new PhabricatorProject())->loadOneWhere('name=%s',nprojectname($p));$team=(new PhorgeOrganizationUnit())->loadOneWhere('unitID=%s',nteam($p));$member=(new PhorgeOrganizationMember())->loadOneWhere('unitID=%s AND userPHID=%s',nteam($p),$recipient->getPHID());
 nneed($project!==null&&$team!==null&&$member!==null&&$team->getKind()==='team'&&$team->getState()==='active'&&$team->getVisibilityModel()==='restricted-admin-v1'&&(string)$team->getVisibilityVersion()==='1'&&$member->getState()==='active');
 $c=nconnection($p,new PhabricatorProject());foreach(['governance_contentgrant','governance_contentapproval','governance_contentaudit']as$n)nneed(queryfx_one($c,'SELECT TABLE_NAME n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$n)===null);
 return['target_phid'=>$project->getPHID(),'subjects'=>['user'=>$recipient->getPHID(),'business-team'=>nteam($p)],'real_native_admin_API_sources'=>2,'native_user_team_member_and_Project_readback'=>true,'N05_grant_tables_absent'=>true,'web_MFA_qualified'=>false,'execution_authorized'=>false];
}
if(defined('N05_PROVISION_LIBRARY_ONLY')&&N05_PROVISION_LIBRARY_ONLY===true)return;
try{
 nneed($argc===8);[$script,$root,$profile,$action,$input,$out,$pin,$unused]=$argv; // fixed reserved argument rejects alternate layouts
 nneed($unused==='native-v1'&&in_array($action,['empty','users','tokens','structure','readback'],true));$raw=nprivate($profile);nneed(hash('sha256',$raw)===$pin);$p=json_decode($raw,true,8,JSON_THROW_ON_ERROR);nneed($p['base_root']===$root&&$p['base_commit']==='4c2245300dc1d78d042b95190dd537eb6b1f9861');$chain=json_decode(nprivate($input),true,8,JSON_THROW_ON_ERROR);
 if($action==='empty')$result=nempty($p);
 else{$argv=[$script];$argc=1;require$root.'/scripts/init/init-script.php';nprofile($p);PhabricatorWorker::setRunAllTasksInProcess(false);$unguarded=AphrontWriteGuard::beginScopedUnguardedWrites();try{$result=match($action){'users'=>nusers($p),'tokens'=>ntokens($p,$out),'structure'=>nstructure($p,$out,$chain),'readback'=>nreadback($p,$out,$chain)};}finally{unset($unguarded);}}
 nneed($result['execution_authorized']===false);echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
}catch(Throwable$e){fwrite(STDERR,"native_prerequisites_refused\n");exit(1);}

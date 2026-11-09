<?php

// Fixed fixture-only native calls. No HTTP listener, fake Query or actor payload.
require_once __DIR__.'/native-cases.php';
require_once __DIR__.'/privacy-cases.php';
require_once __DIR__.'/route-cases.php';
require_once __DIR__.'/directory-avatar-fixture.php';

function governance_directory_transport_api($method,array $params,$token) {
  if(!in_array($method,array('people.organization.query','people.organization.edit'),true)){
    throw new InvalidArgumentException('directory_transport_method_refused');
  }
  $call=new ConduitCall($method,$params);
  $request=$call->getAPIRequest();
  $http=(new AphrontRequest('fixture.invalid','/api/'.$method))->setUser(new PhabricatorUser());
  $controller=(new PhabricatorConduitAPIController())->setRequest($http);
  $authenticate=new ReflectionMethod(PhabricatorConduitAPIController::class,'authenticateUser');
  if($authenticate->invoke($controller,$request,array('token'=>$token),$method)!==null){
    throw new RuntimeException('directory_transport_authentication_refused');
  }
  // Only the actual native authentication branch supplies user and sealed context.
  $call->setUser($request->getUser());
  return $call->execute();
}
function governance_directory_transport_web(array $session,$method,array $params) {
  list($response,$body)=governance_native_route_response($session,$method,$params);
  if(!($response instanceof AphrontJSONResponse)||!is_array($body)){
    throw new RuntimeException('directory_transport_response_shape_refused');
  }
  return array($response->getHTTPResponseCode(),$body);
}
function governance_directory_transport_fingerprint($fixture) {
  $fixture->assertOwned();$conn=(new PhabricatorUser())->establishConnection('w');$rows=array();
  foreach(array('unit','member','appointment','approval','operation','audit')as$table){
    $data=queryfx_all($conn,'SELECT * FROM %T','organization_'.$table);
    usort($data,static fn($a,$b)=>strcmp(phutil_json_encode($a),phutil_json_encode($b)));
    $rows[$table]=$data;
  }
  return hash('sha256',phutil_json_encode($rows));
}
function governance_directory_transport_cases($fixture,callable $check) {
  $fixture->assertOwned();$cookies=$_COOKIE;$server=$_SERVER;$tokens=array();$samples=array();$negative_actions=array();$ordinary_adds=array();
  try{
    $check(PhabricatorEnv::getEnvConfig('governance.media-exchange.enabled')===false &&
      PhabricatorEnv::getEnvConfig('gorge.taskqueue.uri')===null &&
      PhabricatorEnv::getEnvConfig('gorge.notification.outbox')===false,
      'directory-transports-unrelated-media-and-delivery-off');
    $sessions=new PhabricatorAuthSessionEngine();$users=array();$web=array();
    foreach(array('admin','ordinary','parent','child','both','addedweb','addedapi')as$role){
      $user=(new PhabricatorUser())->setUsername('dt'.$role.substr($fixture->getNamespace(),-10))
        ->setRealName('Synthetic Directory '.$role)->setIsApproved(1)->setIsEmailVerified(1)
        ->setIsAdmin($role==='admin'?1:0)->save();
      (new PhabricatorUserEmail())->setUserPHID($user->getPHID())
        ->setAddress($user->getUsername().'@example.invalid')->setIsPrimary(1)->setIsVerified(1)->save();
      $users[$role]=$user;
      if(in_array($role,array('admin','ordinary','parent','child','both'),true)){
        $web[$role]=governance_native_request($user,$sessions);
        $tokens[$role]=PhabricatorConduitToken::initializeNewToken($user->getPHID(),PhabricatorConduitToken::TYPE_STANDARD)->save();
      }
    }
    $avatars=governance_directory_prepare_avatars($fixture,$users,$check);
    $service=PhorgeOrganizationService::newForActor($web['admin']['context']);
    foreach(array('dt-parent'=>null,'dt-child'=>'dt-parent')as$id=>$parent){
      $service->createUnit(array('operation_id'=>'create-'.$id,'unit_id'=>$id,'kind'=>'team','name'=>'Synthetic '.$id,'parent_id'=>$parent));
    }
    $versions=array('dt-parent'=>1,'dt-child'=>1);
    foreach(array('dt-parent'=>array('ordinary','parent','both'),'dt-child'=>array('ordinary','child','both'))as$id=>$roles){
      foreach($roles as$role){
        governance_native_privacy_add($service,'add-'.$id.'-'.$role,$id,$users[$role]->getPHID(),$versions[$id]++);
        if($role!=='ordinary'){governance_native_privacy_seed($fixture,$id,$users[$role]->getPHID());}
      }
    }
    $expected=array('admin'=>array('dt-child','dt-parent'),'ordinary'=>array(),'parent'=>array('dt-parent'),
      'child'=>array('dt-child'),'both'=>array('dt-child','dt-parent'));
    foreach($expected as$role=>$ids){
      $query=new PhorgeOrganizationQuery($web[$role]['context']);
      $check(array_column($query->listUnits(),'unit_id')===$ids,'directory-transports-'.$role.'-explicit-projection');
      $modes=array('list'=>array(),'selector'=>array(),'export'=>array(),'count'=>0,
        'user-teams'=>array('subject'=>$users['ordinary']->getPHID()));
      foreach($modes as$mode=>$unused){
        $subject=$mode==='user-teams'?$users['ordinary']->getPHID():null;
        $direct=PhorgeOrganizationEndpoint::read($web[$role]['context'],$mode,null,$subject,100,null);
        $api=governance_directory_transport_api('people.organization.query',array('mode'=>$mode,'userPHID'=>$subject),$tokens[$role]->getToken());
        list($status,$body)=governance_directory_transport_web($web[$role],'GET',array('json'=>'true','mode'=>$mode,'user_phid'=>$subject));
        $check($status===200 && $body===array('result'=>$direct) && $api===$direct,
          'directory-transports-'.$role.'-'.$mode.'-web-conduit-same-projection');
        $samples[]=array('actor'=>$role,'mode'=>$mode,'unit'=>null,'status'=>200,
          'same_projection'=>true,'row_count'=>$mode==='count'?$direct:count($direct),
          'unit_ids'=>$mode==='count'?array():array_column($direct,'unit_id'),
          'parent_ids'=>$mode==='count'?array():array_column($direct,'parent_id'));
        if($mode!=='count'){
          $check(array_column($direct,'unit_id')===$ids,'directory-transports-'.$role.'-'.$mode.'-exact-ids');
          foreach($direct as$row){
            $check($row['parent_id']===($row['unit_id']==='dt-child'&&in_array('dt-parent',$ids,true)?'dt-parent':null),
              'directory-transports-'.$role.'-'.$mode.'-'.$row['unit_id'].'-parent-redaction');
          }
        }else{$check($direct===count($ids),'directory-transports-'.$role.'-count-exact');}
      }
      foreach(array('dt-parent','dt-child')as$id){
        foreach(array('members','member-count')as$mode){
          if(in_array($id,$ids,true)){
            $direct=PhorgeOrganizationEndpoint::read($web[$role]['context'],$mode,$id,null,100,null);
            $api=governance_directory_transport_api('people.organization.query',array('mode'=>$mode,'unitID'=>$id),$tokens[$role]->getToken());
            list($status,$body)=governance_directory_transport_web($web[$role],'GET',array('json'=>'true','mode'=>$mode,'unit_id'=>$id));
            $check($status===200&&$body===array('result'=>$direct)&&$api===$direct,
              'directory-transports-'.$role.'-'.$id.'-'.$mode.'-visible');
            $samples[]=array('actor'=>$role,'mode'=>$mode,'unit'=>$id,'status'=>200,
              'same_projection'=>true,'row_count'=>$mode==='member-count'?$direct:count($direct),'unit_ids'=>array(),'parent_ids'=>array());
          }else{
            $denied=false;try{governance_directory_transport_api('people.organization.query',array('mode'=>$mode,'unitID'=>$id),$tokens[$role]->getToken());}
            catch(ConduitException$error){if($error->getMessage()!=='ERR-ORGANIZATION'){throw$error;}$denied=true;}
            list($status,$body)=governance_directory_transport_web($web[$role],'GET',array('json'=>'true','mode'=>$mode,'unit_id'=>$id));
            $check($denied&&$status===403&&array_keys($body)===array('error'),
              'directory-transports-'.$role.'-'.$id.'-'.$mode.'-hidden');
            $samples[]=array('actor'=>$role,'mode'=>$mode,'unit'=>$id,'status'=>403,
              'same_projection'=>true,'row_count'=>0,'unit_ids'=>array(),'parent_ids'=>array());
          }
        }
      }
    }
    // A real submitted ticket gets past lock planning; team-admin refusal must
    // not merely be a nonexistent-approval or malformed-payload failure.
    $proposal=array('approval_id'=>'dt-grant','operation_id'=>'dt-grant-op','team'=>'dt-child',
      'action'=>'appointment.grant','target_user'=>$users['ordinary']->getPHID(),'valid_from'=>0,'valid_until'=>null,'ttl_seconds'=>300);
    $service->proposeApproval($proposal);
    $grant=array('operation_id'=>'dt-grant-op','mutations'=>array(array('action'=>'appointment.grant','approval_id'=>'dt-grant',
      'expected_appointment_version'=>0,'expected_member_version'=>1,'expected_team_version'=>$versions['dt-child'],
      'target_user'=>$users['ordinary']->getPHID(),'team'=>'dt-child','valid_from'=>0,'valid_until'=>null)));
    $compound=array('action'=>'member.add-and-appoint','approval_id'=>'dt-compound','operation_id'=>'dt-compound-op',
      'team'=>'dt-child','target_user'=>$users['addedapi']->getPHID(),'expected_team_version'=>$versions['dt-child'],
      'expected_member_version'=>0,'expected_appointment_version'=>0,'impact_acknowledged'=>true,
      'member_from'=>0,'member_until'=>null,'appointment_from'=>0,'appointment_until'=>null,'ttl_seconds'=>300);
    $revoke_proposal=$proposal;$revoke_proposal['approval_id']='dt-revoke';$revoke_proposal['operation_id']='dt-revoke-op';
    $revoke_proposal['action']='appointment.revoke';$revoke_proposal['target_user']=$users['child']->getPHID();
    $revoke_proposal['valid_from']=null;$revoke_proposal['valid_until']=null;$service->proposeApproval($revoke_proposal);
    $revoke=$grant;$revoke['operation_id']='dt-revoke-op';$revoke['mutations'][0]=array_merge($grant['mutations'][0],array(
      'action'=>'appointment.revoke','approval_id'=>'dt-revoke','expected_appointment_version'=>1,
      'target_user'=>$users['child']->getPHID(),'valid_from'=>null,'valid_until'=>null));
    $service->proposeCompoundApproval($compound);$compound_execute=$compound;unset($compound_execute['ttl_seconds']);
    $controls=array(array('grant','members.edit',$grant),array('revoke','members.edit',$revoke),
      array('propose','approval.propose',$proposal),array('compound-propose','compound.propose',$compound),
      array('compound-execute','compound.execute',$compound_execute));
    foreach($controls as$control){list($label,$action,$payload)=$control;
      $before=governance_directory_transport_fingerprint($fixture);
      $denied=false;try{governance_directory_transport_api('people.organization.edit',array('action'=>$action,'payload'=>$payload),$tokens['child']->getToken());}
      catch(ConduitException$error){if($error->getMessage()!=='ERR-ORGANIZATION'){throw$error;}$denied=true;}
      list($status,$body)=governance_directory_transport_web($web['child'],'POST',array('json'=>'true','action'=>$action,
        'payload'=>phutil_json_encode($payload),AphrontRequest::getCSRFTokenName()=>$web['child']['viewer']->getCSRFToken()));
      $check($denied&&$status===403&&array_keys($body)===array('error')&&$before===governance_directory_transport_fingerprint($fixture),
        'directory-transports-team-admin-'.$label.'-denied-unchanged');
      $negative_actions[]=array('action'=>$label,'web_status'=>403,'conduit_generic_error'=>true,'business_unchanged'=>true);
    }
    // Ordinary member additions retain the selected no-extra-approval semantics.
    foreach(array('web'=>'addedweb','conduit'=>'addedapi')as$transport=>$role){
      $unit=(new PhorgeOrganizationQuery($web['child']['context']))->getUnit('dt-child');
      $payload=array('operation_id'=>'dt-ordinary-add-'.$transport,'mutations'=>array(array('action'=>'member.add','approval_id'=>null,
        'expected_appointment_version'=>0,'expected_member_version'=>0,'expected_team_version'=>$unit['version'],
        'target_user'=>$users[$role]->getPHID(),'team'=>'dt-child','valid_from'=>0,'valid_until'=>null)));
      if($transport==='web'){
        list($status,$body)=governance_directory_transport_web($web['child'],'POST',array('json'=>'true','action'=>'members.edit',
          'payload'=>phutil_json_encode($payload),AphrontRequest::getCSRFTokenName()=>$web['child']['viewer']->getCSRFToken()));
        $check($status===200&&isset($body['result']),'directory-transports-ordinary-add-web-native-success');
      }else{
        $result=governance_directory_transport_api('people.organization.edit',array('action'=>'members.edit','payload'=>$payload),$tokens['child']->getToken());
        $check($result['replayed']===false,'directory-transports-ordinary-add-conduit-native-success');
      }
      $rows=(new PhorgeOrganizationQuery($web['child']['context']))->listMembers('dt-child');
      $byid=array_column($rows,null,'user_phid');
      $check(isset($byid[$users[$role]->getPHID()])&&$byid[$users[$role]->getPHID()]['appointment_version']===0,
        'directory-transports-ordinary-add-'.$transport.'-no-appointment');
      $ordinary_adds[]=array('transport'=>$transport,'member_present'=>true,'appointment_version'=>0);
    }
    $unknown=false;try{governance_directory_transport_api('people.organization.query',array('mode'=>'unregistered-mode'),$tokens['child']->getToken());}
    catch(ConduitException$error){if($error->getMessage()!=='ERR-ORGANIZATION'){throw$error;}$unknown=true;}
    list($unknown_status,$unknown_body)=governance_directory_transport_web($web['child'],'GET',array('json'=>'true','mode'=>'unregistered-mode'));
    $check($unknown&&$unknown_status===403&&array_keys($unknown_body)===array('error'),
      'directory-transports-unknown-mode-generic-refusal');
    // Authenticate genuinely then invalidate that concrete token; do not attach a fake actor.
    $token=$tokens['ordinary'];$token->setExpires(1)->save();
    $refused=false;try{governance_directory_transport_api('people.organization.query',array('mode'=>'count'),$token->getToken());}
    catch(RuntimeException$error){if($error->getMessage()!=='directory_transport_authentication_refused'){throw$error;}$refused=true;}
    $check($refused,'directory-transports-expired-token-native-authentication-refused');
    $deleted_token=$token->getToken();$token->delete();
    $refused=false;try{governance_directory_transport_api('people.organization.query',array('mode'=>'count'),$deleted_token);}
    catch(RuntimeException$error){if($error->getMessage()!=='directory_transport_authentication_refused'){throw$error;}$refused=true;}
    $check($refused,'directory-transports-deleted-token-native-authentication-refused');
    return array('schema'=>'native-directory-transports-v1-file-dependency','avatars'=>$avatars,'read_samples'=>$samples,
      'negative_actions'=>$negative_actions,'ordinary_adds'=>$ordinary_adds,'actual_native_ConduitCall'=>true,'actual_native_token_authentication'=>true,
      'actual_native_Web_controller'=>true,'fake_query_or_actor'=>false,'HTTP_server_or_browser'=>false,
      'production_authority'=>false,'native_permission_axes_changed'=>false);
  }finally{
    // Outer completion driver owns all schemas/principals; no local cleanup
    // exception may replace the primary test failure. Tokens live only there.
    $_COOKIE=$cookies;$_SERVER=$server;
  }
}

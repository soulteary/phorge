<?php

/** Dedicated file-origin bridge; never accepts the application's session as media authority. */
final class PhorgeGovernanceMediaResourceController extends PhabricatorController {
  public function shouldRequireLogin(){return false;}
  public function shouldAllowPartialSessions(){return true;}
  public function handleRequest(AphrontRequest $request){
    try{
      $action=$request->getURIData('mediaAction');
      $origins=PhorgeGovernanceMediaHTTPProtocol::assertRequest($request,'files',$action!=='bridge');
      if($action==='bridge'){return $this->bridge($origins);}
      $service=new PhorgeGovernanceMediaExchangeService();
      if($action==='prepare'){
        $data=PhorgeGovernanceMediaHTTPProtocol::payload($request,array('path','client_nonce'));
        $target=PhorgeGovernanceMediaTarget::fromPath($data['path'],PhabricatorEnv::getEnvConfig('cluster.instance')?:null);
        // Origin-checked anonymous POST has its own one-use/browser binding.
        // Native application CSRF is deliberately not shared with this host.
        $unguarded=AphrontWriteGuard::beginScopedUnguardedWrites();
        try{
        $prepared=$service->beginBrowserTransaction(PhorgeGovernanceMediaHTTPProtocol::cookie($request,
          PhorgeGovernanceMediaHTTPProtocol::BROWSER_COOKIE),$target,$request,$data['client_nonce']);
        }finally{unset($unguarded);}
        $response=PhorgeGovernanceMediaHTTPProtocol::json(array('transaction'=>$prepared['transaction_id'],
          'expires_at'=>$prepared['expires_at']));
        return PhorgeGovernanceMediaHTTPProtocol::setCookie($response,PhorgeGovernanceMediaHTTPProtocol::BROWSER_COOKIE,
          $prepared['browser_secret']->openEnvelope(),$prepared['browser_expires_at']);
      }
      if($action==='redeem'){
        $data=PhorgeGovernanceMediaHTTPProtocol::payload($request,array('transaction','code','client_nonce','path'));
        $browser=PhorgeGovernanceMediaHTTPProtocol::cookie($request,PhorgeGovernanceMediaHTTPProtocol::BROWSER_COOKIE);
        if($browser===null){throw new RuntimeException('media_http_browser_cookie_required');}
        $unguarded=AphrontWriteGuard::beginScopedUnguardedWrites();
        try{
        $result=$service->redeem($data['transaction'],$data['code'],$browser,'phorge-files','private-media',$data['path'],$data['client_nonce']);
        }finally{unset($unguarded);}
        return PhorgeGovernanceMediaHTTPProtocol::setCookie(PhorgeGovernanceMediaHTTPProtocol::json(array('redeemed'=>true)),
          PhorgeGovernanceMediaHTTPProtocol::CREDENTIAL_COOKIE,$result['credential']->openEnvelope(),$result['expires_at']);
      }
      if($action==='ready'){
        $data=PhorgeGovernanceMediaHTTPProtocol::payload($request,array('client_nonce'));
        PhorgeGovernanceMediaExchangeService::validateSecret($data['client_nonce']);
        $secret=PhorgeGovernanceMediaHTTPProtocol::cookie($request,PhorgeGovernanceMediaHTTPProtocol::CREDENTIAL_COOKIE);
        if($secret===null){throw new RuntimeException('media_http_credential_cookie_required');}
        $service->authenticate($secret);
        return PhorgeGovernanceMediaHTTPProtocol::json(array('ready'=>true));
      }
      throw new RuntimeException('media_http_action_refused');
    }catch(Throwable $error){return PhorgeGovernanceMediaHTTPProtocol::deny();}
  }
  private function bridge(array $origins){
    $origins['max_path_length']=PhorgeGovernanceMediaTarget::MAX_RETURN_PATH_BYTES;
    $nonce=bin2hex(random_bytes(16));$config=PhorgeGovernanceMediaHTTPProtocol::scriptJSON($origins);
    $script=<<<'JS'
'use strict';
const config=CONFIG; let stage='waiting',binding=null;
const hex=x=>typeof x==='string'&&/^[a-f0-9]{64}$/.test(x);
async function post(action,payload){const body=new URLSearchParams({payload:JSON.stringify(payload)});
 const r=await fetch('/media/session/'+action+'/',{method:'POST',credentials:'same-origin',cache:'no-store',redirect:'error',body});
 if(!r.ok)throw Error('media unavailable');return r.json();}
window.addEventListener('message',async event=>{
 if(window.parent===window||event.source!==window.parent||event.origin!==config.app)return;
 const d=event.data;if(!d||typeof d!=='object'||!hex(d.nonce))return;
 try{
  if(stage==='waiting'&&d.type==='media-init'&&Object.keys(d).sort().join(',')==='nonce,path,type'&&typeof d.path==='string'&&d.path.length<=config.max_path_length){
   stage='preparing';binding={path:d.path,nonce:d.nonce};const result=await post('prepare',{path:d.path,client_nonce:d.nonce});
   if(!hex(result.transaction))throw Error('media unavailable');binding.transaction=result.transaction;stage='prepared';
   window.parent.postMessage({type:'media-prepared',nonce:d.nonce,transaction:result.transaction},config.app);
  }else if(stage==='prepared'&&d.type==='media-code'&&Object.keys(d).sort().join(',')==='code,nonce,type'&&d.nonce===binding.nonce&&hex(d.code)){
   stage='redeeming';await post('redeem',{transaction:binding.transaction,code:d.code,client_nonce:d.nonce,path:binding.path});
   const result=await post('ready',{client_nonce:d.nonce});if(result.ready!==true)throw Error('media unavailable');stage='ready';
   window.parent.postMessage({type:'media-ready',nonce:d.nonce},config.app);binding=null;
  }
 }catch(error){stage='failed';window.parent.postMessage({type:'media-failed',nonce:d.nonce},config.app);binding=null;}
});
JS;
    $script=str_replace('CONFIG',$config,$script);
    return (new PhorgeGovernanceMediaHTTPResponse())->withHeader('Content-Type','text/html; charset=UTF-8')
      ->withHeader('Content-Security-Policy',"default-src 'none'; script-src 'nonce-".$nonce."'; connect-src 'self'; frame-ancestors ".$origins['app']."; base-uri 'none'; form-action 'none'; object-src 'none'")
      ->setBody('<!doctype html><meta charset="utf-8"><title>Media bridge</title><script nonce="'.$nonce.'">'.$script.'</script>');
  }
}

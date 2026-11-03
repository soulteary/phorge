<?php

/** Application-origin session/CSRF issuer and pinned child-frame bootstrap. */
final class PhorgeGovernanceMediaAuthorizeController extends PhabricatorController {
  public function handleRequest(AphrontRequest $request){
    try{
      $action=$request->getURIData('mediaAction');
      $origins=PhorgeGovernanceMediaHTTPProtocol::assertRequest($request,'app',$action==='issue');
      $context=PhorgeGovernanceNativeActorContext::newFromWebRequest($request);
      if($action==='issue'){
        $request->validateCSRF();$data=PhorgeGovernanceMediaHTTPProtocol::payload($request,array('transaction','client_nonce','path'));
        $result=(new PhorgeGovernanceMediaExchangeService())->issue($request,$data['transaction'],'phorge-files','private-media',$data['path'],$data['client_nonce']);
        return PhorgeGovernanceMediaHTTPProtocol::json(array('code'=>$result['exchange_code']->openEnvelope(),'expires_at'=>$result['expires_at']));
      }
      if($action!=='authorize'){throw new RuntimeException('media_http_action_refused');}
      $target=PhorgeGovernanceMediaTarget::fromPath($request->getStr('path',''),PhabricatorEnv::getEnvConfig('cluster.instance')?:null);
      $config=PhorgeGovernanceMediaHTTPProtocol::scriptJSON(array('files'=>$origins['files'],'path'=>$target->getReturnPath(),
        'csrf_name'=>AphrontRequest::getCSRFTokenName(),'csrf'=>$request->getViewer()->getCSRFToken()));
      $nonce=bin2hex(random_bytes(16));
      $script=<<<'JS'
'use strict';const config=CONFIG,frame=document.getElementById('media-bridge'),status=document.getElementById('status');
const bytes=crypto.getRandomValues(new Uint8Array(32)),nonce=Array.from(bytes,x=>x.toString(16).padStart(2,'0')).join('');let stage='loading';
frame.addEventListener('load',()=>{if(stage!=='loading')return;stage='preparing';frame.contentWindow.postMessage({type:'media-init',nonce,path:config.path},config.files);});
window.addEventListener('message',async event=>{
 if(event.source!==frame.contentWindow||event.origin!==config.files)return;
 const d=event.data;if(!d||typeof d!=='object'||d.nonce!==nonce)return;
 try{
  if(stage==='preparing'&&d.type==='media-prepared'&&Object.keys(d).sort().join(',')==='nonce,transaction,type'&&typeof d.transaction==='string'&&/^[a-f0-9]{64}$/.test(d.transaction)){
   stage='issuing';const body=new URLSearchParams({payload:JSON.stringify({transaction:d.transaction,client_nonce:nonce,path:config.path})});body.set(config.csrf_name,config.csrf);
   const response=await fetch('/auth/media/issue/',{method:'POST',credentials:'same-origin',cache:'no-store',redirect:'error',body});
   if(!response.ok)throw Error('media unavailable');const issued=await response.json();
   if(typeof issued.code!=='string'||!/^[a-f0-9]{64}$/.test(issued.code))throw Error('media unavailable');stage='redeeming';
   frame.contentWindow.postMessage({type:'media-code',nonce,code:issued.code},config.files);
  }else if(stage==='redeeming'&&d.type==='media-ready'&&Object.keys(d).sort().join(',')==='nonce,type'){
   stage='ready';status.textContent='Media session ready.';window.location.replace(config.files+config.path);
  }else if(d.type==='media-failed'){throw Error('media unavailable');}
 }catch(error){stage='failed';status.textContent='Media access unavailable. Please sign in again and retry.';}
});
frame.src=config.files+'/media/session/bridge/';
JS;
      $script=str_replace('CONFIG',$config,$script);
      return (new PhorgeGovernanceMediaHTTPResponse())->withHeader('Content-Type','text/html; charset=UTF-8')
        ->withHeader('Content-Security-Policy',"default-src 'none'; script-src 'nonce-".$nonce."'; connect-src 'self'; frame-src ".$origins['files']."; frame-ancestors 'none'; base-uri 'none'; object-src 'none'; form-action 'none'")
        ->setBody('<!doctype html><meta charset="utf-8"><title>Private media access</title><p id="status">Preparing private media access…</p><iframe id="media-bridge" title="Private media bridge" hidden></iframe><script nonce="'.$nonce.'">'.$script.'</script>');
    }catch(Throwable $error){return PhorgeGovernanceMediaHTTPProtocol::deny();}
  }
}

<?php

final class PhorgeProjectHandoffController extends PhabricatorProjectController {
  public function handleRequest(AphrontRequest $request) {
    if(!PhorgeProjectHandoffService::enabled()){return new Aphront404Response();}
    $viewer=$this->getViewer();
    if(!$viewer->getIsAdmin()){return new Aphront404Response();}
    if($request->isHTTPPost()){$request->validateCSRF();}
    $context=PhorgeGovernanceNativeActorContext::newFromWebRequest($request);
    // A confirmed handoff may remove the initiator's object access. Permit
    // only route-bound, currently authenticated exact receipt reconciliation
    // before native view/edit checks; never render project details here.
    if($request->isHTTPPost()&&$request->getStr('handoff_action')==='commit'){
      $raw=$request->getStr('intent');
      if(strlen($raw)>8192){return new Aphront400Response();}
      $intent=json_decode($raw,true,8);
      $target=(new PhabricatorProject())->load((int)$request->getURIData('id'));
      if(!is_array($intent)||!$target||($intent['projectPHID']??null)!==$target->getPHID()){return new Aphront400Response();}
      $intent['acknowledge']=$request->getBool('acknowledge');
      try{$result=PhorgeProjectHandoffService::commit($context,$intent,$request);}
      catch(PhabricatorAuthHighSecurityRequiredException$e){throw$e;}
      catch(Exception$e){return $this->newDialog()->setTitle(pht('Handoff Unavailable'))
        ->appendParagraph(pht('The handoff result could not be confirmed. Check the project history before retrying. An expired or changed preview requires a new review.'))
        ->addCancelButton('/project/');}
      return$this->newPage()->setTitle(pht('Handoff Complete'))->appendChild(
        (new PHUIInfoView())->setTitle(pht('Handoff Complete'))->appendChild(pht('The policy handoff was recorded. Your access now follows the final native policies.')));
    }
    $response=$this->loadProjectForEdit();if($response){return$response;}
    $project=$this->getProject();
    $form=(new AphrontFormView())->setUser($viewer)->setAction($this->getApplicationURI('handoff/'.$project->getID().'/'));$title=pht('Hand Off Project Permissions');
    try{
      if($request->isHTTPPost()){
        if($request->getStr('handoff_action')!=='preview'){throw new RuntimeException('handoff_action');}
        $selection=$request->getArr('recipient');
        if(count($selection)!==1){throw new RuntimeException('handoff_one_recipient_required');}
        $preview=PhorgeProjectHandoffService::preview($context,array('projectPHID'=>$project->getPHID(),
          'recipientPHID'=>head($selection),'operationID'=>bin2hex(random_bytes(16))));
        $intent=array_intersect_key($preview,array_fuse(array('operationID','projectPHID','recipientPHID','expiresAt','expectedDigest')));
        $form->addHiddenInput('handoff_action','commit')->addHiddenInput('intent',json_encode($intent,JSON_THROW_ON_ERROR));
        $form->appendChild(phutil_tag('p',array(),pht('Recipient: %s',$preview['recipientName'])));
        $form->appendChild(phutil_tag('p',array(),pht('Current policies: view=%s, edit=%s.',$preview['before']['view'],$preview['before']['edit'])));
        $form->appendChild(phutil_tag('p',array(),pht('Final view and edit policies: the selected user. Join policy, members, Space and hierarchy stay unchanged.')));
        $form->appendChild(phutil_tag('p',array(),pht('Your final access: view=%s, edit=%s.',
          $preview['actorAfter']['view']?pht('Yes'):pht('No'),$preview['actorAfter']['edit']?pht('Yes'):pht('No'))));
        $form->appendControl((new AphrontFormCheckboxControl())->addCheckbox('acknowledge',1,
          pht('I reviewed the final policies and understand that I may lose access.'),false));
        $form->appendControl((new AphrontFormSubmitControl())->setValue(pht('Confirm Handoff'))->addCancelButton($project->getURI()));
      }else{
        $form->addHiddenInput('handoff_action','preview')->appendControl((new AphrontFormTokenizerControl())
          ->setName('recipient')->setLabel(pht('Recipient'))->setLimit(1)->setDatasource(new PhabricatorPeopleDatasource()));
        $form->appendControl((new AphrontFormSubmitControl())->setValue(pht('Preview Final Permissions'))->addCancelButton($project->getURI()));
      }
    }catch(PhabricatorAuthHighSecurityRequiredException$e){throw$e;}
    catch(Exception$e){return $this->newDialog()->setTitle(pht('Handoff Unavailable'))
      ->appendParagraph(pht('The handoff result could not be confirmed. Check the project history before retrying. An expired or changed preview requires a new review.'))
      ->addCancelButton($project->getURI());}
    return$this->newPage()->setTitle($title)->appendChild((new PHUIObjectBoxView())->setHeaderText($title)->appendChild($form));
  }
}

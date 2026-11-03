<?php

/** Current sysadmin native forms. Typed service methods remain the authority. */
final class PhorgeOrganizationTransferController extends PhabricatorPeopleController {
  public function shouldRequireAdmin(){return false;}
  private function home(){return $this->getApplicationURI('organization/transfer/');}
  private function form(){return(new AphrontFormView())->setUser($this->getViewer())->setAction($this->home());}
  private function one(AphrontRequest $request,$key){$values=$request->getArr($key);if(count($values)!==1||!is_string(head($values))){throw new InvalidArgumentException('organization_transfer_selection_refused');}return head($values);}
  private function ack(AphrontRequest $request){if(!$request->getBool('acknowledge_no_project_permission_change',false)){throw new InvalidArgumentException('organization_transfer_acknowledgement_refused');}}
  private function raw(AphrontRequest $request){$raw=$request->getStr('request','');if(strlen($raw)>32768){throw new InvalidArgumentException('organization_transfer_payload_refused');}$v=phutil_json_decode($raw);if(!is_array($v)){throw new InvalidArgumentException('organization_transfer_payload_refused');}return$v;}
  private function integer(AphrontRequest $request,$key,$default=null){
    $data=$request->getRequestData();$value=array_key_exists($key,$data)?$data[$key]:$default;
    if(is_int($value)){return$value;}
    if(!is_string($value)||!preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D',$value)||$value==='-0'){
      throw new InvalidArgumentException('organization_transfer_number_refused');}
    $number=filter_var($value,FILTER_VALIDATE_INT);if($number===false){throw new InvalidArgumentException('organization_transfer_number_refused');}return$number;
  }
  private function control($name,$label,$value=''){return(new AphrontFormTextControl())->setName($name)->setLabel($label)->setValue($value);}
  private function submit($label){return(new AphrontFormSubmitControl())->setValue($label);}
  private function ackControl(){return(new AphrontFormCheckboxControl())->addCheckbox('acknowledge_no_project_permission_change',1,pht('This records organization work only; native project permissions remain unchanged.'),false);}
  private function ticketURI($family,$id){return(string)(new PhutilURI($this->home()))->setQueryParam('family',$family)->setQueryParam('ticket',$id);}
  private function previewDialog(array $preview,$family,$action,$ttl){
    $form=$this->form()->addHiddenInput('form_action',$family.'.propose')->addHiddenInput('lifecycle_action',$action)
      ->addHiddenInput('request',phutil_json_encode($preview['intent']))->addHiddenInput('ttl_seconds',$ttl);
    $calendar=$preview['calendar'];
    $dialog=$this->newDialog()->setTitle(pht('Review Organization Transfer'))
      ->appendParagraph(pht('A different current system administrator must approve this request. No project authorization is changed.'));
    if($calendar!==null){
      if($calendar['resolution_required']){throw new RuntimeException('organization_transfer_calendar_resolution_required');}
      $form->addHiddenInput('deadline_offset',$calendar['deadline_offset']);
      $dialog->appendParagraph(pht('Deadline: %s (%s); cumulative days: %s',
        $calendar['deadline_local'],$calendar['timezone'],$calendar['cumulative_days']??$calendar['days']));
    }
    if(isset($preview['site_timezone'])){$dialog->appendParagraph(pht('Stored timezone: %s; current site timezone: %s; overdue seconds: %s',
      $preview['stored_timezone'],$preview['site_timezone'],$preview['overdue_seconds']));}
    return$dialog->appendForm($form)->addSubmitButton(pht('Request Independent Approval'))->addCancelButton($this->home());
  }
  public function handleRequest(AphrontRequest $request){
    if(PhabricatorEnv::getEnvConfig('governance.organization.enabled')!==true){return new Aphront404Response();}
    try{
      $context=PhorgeGovernanceNativeActorContext::newFromWebRequest($request);$service=PhorgeOrganizationService::newForActor($context);
      $caps=$service->readActorCapabilities();if($caps['manage_units']!==true){throw new RuntimeException('organization_transfer_current_admin_required');}
      if($request->isHTTPPost()){
        $request->validateCSRF();$action=$request->getStr('form_action','');
        switch($action){
          case 'transfer.preview':
            $this->ack($request);$days=$this->integer($request,'handover_days',14);
            if(!in_array($days,array(0,14,30),true)){throw new InvalidArgumentException('organization_transfer_period_refused');}
            $preview=$service->previewSelectedTransfer($this->one($request,'subject'),$this->one($request,'responsible'),
              $this->one($request,'department'),$days,$request->getStr('reason',''));
            return$this->previewDialog($preview,'transfer','transfer',$this->integer($request,'ttl_seconds',300));
          case 'handover.preview':
            $this->ack($request);$h=$service->readHandoverDetail($request->getStr('handover_id'));$kind=$request->getStr('lifecycle_action');
            $intent=array('operation_id'=>'handover-execute-'.bin2hex(random_bytes(16)),'transfer_id'=>'handover-'.bin2hex(random_bytes(16)),
              'handover_id'=>$h['handover_id'],'expected_version'=>$h['version'],'reason'=>$request->getStr('reason',''),
              'acknowledge_no_project_permission_change'=>true);
            if($kind==='extend'){$intent['handover_days']=$this->integer($request,'handover_days');$intent['exception_reason']=$request->getStr('exception_reason','')?:null;}
            if($kind==='responsible'){$intent['responsible_phid']=$this->one($request,'responsible');}
            $offset=$request->getStr('deadline_offset','');$offset=$offset===''?null:$this->integer($request,'deadline_offset');
            $preview=$service->previewHandover($kind,$intent,$offset);
            return$this->previewDialog($preview,'handover',$kind,$this->integer($request,'ttl_seconds',300));
          case 'transfer.propose':case 'handover.propose':
            $family=$action==='transfer.propose'?'transfer':'handover';$intent=$this->raw($request);$ttl=$this->integer($request,'ttl_seconds');
            $offset=$request->getStr('deadline_offset','');$offset=$offset===''?null:$this->integer($request,'deadline_offset');
            $result=$family==='transfer'?$service->proposeTransfer($intent,$ttl,$offset):$service->proposeHandover($request->getStr('lifecycle_action'),$intent,$ttl,$offset);
            return(new AphrontRedirectResponse())->setURI($this->ticketURI($family,$result['transfer_id']));
          case 'ticket.approve':case 'ticket.execute':
            $family=$request->getStr('family');$id=$request->getStr('ticket');
            if(!in_array($family,array('transfer','handover'),true)){throw new InvalidArgumentException('organization_transfer_family_refused');}
            if($action==='ticket.approve'){
              $digest=$request->getStr('request_digest');
              $family==='transfer'?$service->approveTransfer($id,$digest):$service->approveHandover($id,$digest);
            }else{$this->ack($request);$family==='transfer'?$service->executeTransfer($id,true):$service->executeHandover($id,true);}
            return(new AphrontRedirectResponse())->setURI($this->ticketURI($family,$id));
          case 'project.record':
            $h=$service->readHandoverDetail($request->getStr('handover_id'));$project=$this->one($request,'project');$existing=$service->readHandoverProjects($h['handover_id']);$version=0;
            foreach($existing['rows']as$row){if($row['project_phid']===$project){$version=$row['version'];}}
            $service->recordHandoverProject(array('operation_id'=>$request->getStr('operation_id'),'handover_id'=>$h['handover_id'],
              'handover_version'=>$h['version'],'project_phid'=>$project,'decision'=>$request->getStr('decision'),
              'manual_status'=>$request->getStr('manual_status'),'expected_version'=>$version,'note'=>$request->getStr('note','')));
            break;
          case 'due.record':$service->recordHandoverDue($request->getStr('operation_id'),$request->getStr('handover_id'),$this->integer($request,'expected_version'));break;
          default:throw new InvalidArgumentException('organization_transfer_form_refused');
        }
        return(new AphrontRedirectResponse())->setURI($this->home());
      }
      if(($_SERVER['REQUEST_METHOD']??'')!=='GET'){throw new RuntimeException('organization_transfer_method_refused');}
      return$this->page($request,$service,$context);
    }catch(Throwable$error){return(new AphrontJSONResponse())->setHTTPResponseCode(403)->setContent(array('error'=>pht('The organization request could not be completed.')));}
  }
  private function page(AphrontRequest $request,PhorgeOrganizationService $service,PhorgeGovernanceNativeActorContext $context){
    $page=$this->newPage()->setTitle(pht('Organization Transfers'));
    $page->appendChild((new PHUIInfoView())->setTitle(pht('System Administrator Actions'))->appendChild(
      pht('Department handovers record responsibility and manual follow-up. They never grant or remove native project access.')));
    $family=$request->getStr('family');$ticket=$request->getStr('ticket');
    if($ticket!==null&&$ticket!==''){
      if(!in_array($family,array('transfer','handover'),true)){throw new InvalidArgumentException('organization_transfer_family_refused');}
      $detail=$family==='transfer'?$service->readTransfer($ticket):$service->readHandoverApproval($ticket);$r=$detail['request'];
      $box=(new PHUIObjectBoxView())->setHeaderText(pht('Independent Approval Request'))
        ->appendChild(phutil_tag('p',array(),pht('Request: %s; version: %s; state: %s',$ticket,$r['version'],$this->stateLabel($r['state']))));
      foreach($detail['intent']as$key=>$value){if(in_array($key,array('reason','exception_reason','handover_days','responsible_phid','subject_phid','from_department','to_department','handover_id','expected_version','primary_version','from_version','to_version'),true)){
        $box->appendChild(phutil_tag('p',array(),pht('%s: %s',$this->fieldLabel($key),$value)));}}
      if(isset($detail['change_context'])){$before=$detail['change_context'];$box->appendChild(phutil_tag('p',array(),
        pht('Original handover: %s; subject: %s; responsible: %s; version: %s; deadline: %s (%s)',
          $before['handover_id'],$before['subject_phid'],$before['responsible_phid'],$before['version'],
          phabricator_datetime($before['deadline_at'],$this->getViewer()),$before['timezone'])));}
      if($detail['calendar']!==null){$box->appendChild(phutil_tag('p',array(),pht('Deadline: %s (%s); cumulative days: %s',
        $detail['calendar']['deadline_local'],$detail['calendar']['timezone'],$detail['calendar']['cumulative_days']??$detail['calendar']['days'])));}
      if($detail['can_approve']){$form=$this->form()->addHiddenInput('form_action','ticket.approve')->addHiddenInput('family',$family)->addHiddenInput('ticket',$ticket)
          ->addHiddenInput('request_digest',$detail['request_digest'])->appendControl($this->submit(pht('Approve')));$box->appendChild($form);}
      if(in_array($r['state'],array('approved','executed'),true)){$form=$this->form()->addHiddenInput('form_action','ticket.execute')->addHiddenInput('family',$family)->addHiddenInput('ticket',$ticket)
          ->appendControl($this->ackControl())->appendControl($this->submit($r['state']==='executed'?pht('Retry Committed Request'):pht('Execute Approved Request')));$box->appendChild($form);}
      $page->appendChild($box);
    }
    $form=$this->form()->addHiddenInput('form_action','transfer.preview')
      ->appendControl((new AphrontFormTokenizerControl())->setName('subject')->setLabel(pht('User'))->setLimit(1)->setDatasource(new PhabricatorPeopleDatasource()))
      ->appendControl((new AphrontFormTokenizerControl())->setName('responsible')->setLabel(pht('Responsible Person'))->setLimit(1)->setDatasource(new PhabricatorPeopleDatasource()))
      ->appendControl((new AphrontFormTokenizerControl())->setName('department')->setLabel(pht('New Primary Department'))->setLimit(1)->setDatasource((new PhorgeOrganizationDatasource())->setActorContext($context)->setUnitKind('department')))
      ->appendControl((new AphrontFormSelectControl())->setName('handover_days')->setLabel(pht('Handover Days'))->setOptions(array(0=>pht('Immediate'),14=>pht('14 Days'),30=>pht('30 Days')))->setValue(14))
      ->appendControl($this->control('reason',pht('Reason')))->appendControl($this->control('ttl_seconds',pht('Approval Validity (Seconds)'),300))
      ->appendControl($this->ackControl())->appendControl($this->submit(pht('Preview Transfer')));
    $page->appendChild((new PHUIObjectBoxView())->setHeaderText(pht('New Department Transfer'))->appendChild($form));
    $list=$service->readHandoverPage(25,$request->getStr('after')?:null);
    foreach($list['rows']as$h){$box=(new PHUIObjectBoxView())->setHeaderText(pht('Handover %s',$h['handover_id']))
        ->appendChild(phutil_tag('p',array(),pht('Version: %s; state: %s; cumulative days: %s',$h['version'],$this->stateLabel($h['state']),$h['cumulative_days'])))
        ->appendChild(phutil_tag('p',array(),pht('Deadline: %s (%s)',phabricator_datetime($h['deadline_at'],$this->getViewer()),$h['timezone'])));
      if($h['state']==='active'){
        $form=$this->form()->addHiddenInput('form_action','handover.preview')->addHiddenInput('handover_id',$h['handover_id'])
          ->appendControl((new AphrontFormSelectControl())->setName('lifecycle_action')->setLabel(pht('Change'))->setOptions(array('extend'=>pht('Extend Handover'),'terminate'=>pht('End Handover'),'responsible'=>pht('Replace Responsible Person'))))
          ->appendControl($this->control('handover_days',pht('Additional Calendar Days'),14))
          ->appendControl((new AphrontFormTokenizerControl())->setName('responsible')->setLabel(pht('New Responsible Person'))->setLimit(1)->setDatasource(new PhabricatorPeopleDatasource()))
          ->appendControl($this->control('deadline_offset',pht('Explicit UTC Offset (Seconds)')))
          ->appendControl($this->control('reason',pht('Reason')))->appendControl($this->control('exception_reason',pht('Above-30-Day Exception Reason')))
          ->appendControl($this->control('ttl_seconds',pht('Approval Validity (Seconds)'),300))->appendControl($this->ackControl())->appendControl($this->submit(pht('Preview Handover Change')));
        $box->appendChild($form);
      }
      $receipts=$service->readHandoverProjects($h['handover_id']);
      $box->appendChild(phutil_tag('p',array(),pht('Project decisions below are manual receipts, not executed permission changes or an exhaustive project list.')));
      foreach($receipts['rows']as$row){$box->appendChild(phutil_tag('p',array(),pht('%s: %s; %s; %s',
        $row['name'],$this->decisionLabel($row['decision']),$this->manualLabel($row['manual_status']),$row['note'])));}
      $manual=$this->form()->addHiddenInput('form_action','project.record')->addHiddenInput('handover_id',$h['handover_id'])
        ->addHiddenInput('operation_id','manual-receipt-'.bin2hex(random_bytes(16)))
        ->appendControl((new AphrontFormTokenizerControl())->setName('project')->setLabel(pht('Project'))->setLimit(1)->setDatasource(new PhabricatorProjectDatasource()))
        ->appendControl((new AphrontFormSelectControl())->setName('decision')->setLabel(pht('Manual Decision'))->setOptions(array('keep'=>pht('Keep'),'remove'=>pht('Remove'),'review'=>pht('Review'))))
        ->appendControl((new AphrontFormSelectControl())->setName('manual_status')->setLabel(pht('Manual Follow-up'))->setOptions(array('pending'=>pht('Pending'),'acknowledged'=>pht('Acknowledged'))))
        ->appendControl($this->control('note',pht('Note')))->appendControl($this->submit(pht('Record Manual Decision')));
      $box->appendChild($manual);$page->appendChild($box);
    }
    $due_cursor=$request->getStr('due_after','');if(strlen($due_cursor)>512){throw new InvalidArgumentException('organization_handover_cursor_refused');}
    $due=$service->readDueHandovers(25,$due_cursor===''?null:phutil_json_decode($due_cursor));$due_box=(new PHUIObjectBoxView())->setHeaderText(pht('Due Handover Work'));
    foreach($due['rows']as$row){$form=$this->form()->addHiddenInput('form_action','due.record')
        ->addHiddenInput('handover_id',$row['handover_id'])->addHiddenInput('expected_version',$row['version'])
        ->addHiddenInput('operation_id','due-record-'.bin2hex(random_bytes(16)))
        ->appendControl((new AphrontFormMarkupControl())->setLabel(pht('Handover'))->setValue($row['handover_id']))
        ->appendControl($this->submit(pht('Record Undispatched Due Event')));$due_box->appendChild($form);}
    $due_box->appendChild(phutil_tag('p',array(),pht('Recording a due event sends no notification and changes no access. Automatic dispatch remains disabled.')));
    if($due['next_cursor']!==null){$due_box->appendChild(phutil_tag('a',array('href'=>(string)(new PhutilURI($this->home()))->setQueryParam('due_after',phutil_json_encode($due['next_cursor']))),pht('Next Due Work Page')));}
    $page->appendChild($due_box);
    if($list['next_cursor']!==null){$page->appendChild(phutil_tag('a',array('href'=>(string)(new PhutilURI($this->home()))->setQueryParam('after',$list['next_cursor'])),pht('Next Handover Page')));}
    return$page;
  }
  private function decisionLabel($value){switch($value){case 'keep':return pht('Keep');case 'remove':return pht('Remove');case 'review':return pht('Review');default:throw new RuntimeException('organization_transfer_decision_refused');}}
  private function manualLabel($value){switch($value){case 'pending':return pht('Pending');case 'acknowledged':return pht('Acknowledged');default:throw new RuntimeException('organization_transfer_decision_refused');}}
  private function stateLabel($state){switch($state){case 'active':return pht('Active');case 'terminated':return pht('Ended');case 'submitted':return pht('Awaiting Independent Approval');case 'approved':return pht('Approved');case 'executed':return pht('Executed');default:throw new RuntimeException('organization_transfer_state_refused');}}
  private function fieldLabel($key){$labels=array('reason'=>pht('Reason'),'handover_days'=>pht('Handover Days'),'responsible_phid'=>pht('Responsible Person'),
    'subject_phid'=>pht('User'),'from_department'=>pht('Previous Department'),'to_department'=>pht('New Primary Department'),
    'exception_reason'=>pht('Above-30-Day Exception Reason'),'handover_id'=>pht('Handover'),'expected_version'=>pht('Handover Version'),
    'primary_version'=>pht('Primary Department Version'),'from_version'=>pht('Previous Department Version'),'to_version'=>pht('New Department Version'));return$labels[$key];}
}

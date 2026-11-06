<?php

/** Discovery and navigation only. Native service commands remain authoritative. */
final class PhorgeOrganizationApprovalController extends PhabricatorPeopleController {
  public function shouldRequireAdmin() { return false; }
  private function home() { return $this->getApplicationURI('organization/approvals/'); }
  private function uri($id) { return (string)(new PhutilURI($this->home()))->setQueryParam('approval',$id); }
  private function form($id,$action,$label) {
    return (new AphrontFormView())->setUser($this->getViewer())->setAction($this->home())
      ->addHiddenInput('approval',$id)->addHiddenInput('form_action',$action)
      ->appendControl((new AphrontFormSubmitControl())->setValue($label));
  }

  public function handleRequest(AphrontRequest $request) {
    if (PhabricatorEnv::getEnvConfig('governance.organization.enabled')!==true) {
      return new Aphront404Response();
    }
    try {
      $context=PhorgeGovernanceNativeActorContext::newFromWebRequest($request);
      $service=PhorgeOrganizationService::newForActor($context);
      $id=$request->getStr('approval','');
      if ($request->isHTTPPost()) {
        $request->validateCSRF();
        $detail=$service->readApprovalWorkbenchDetail($id);
        switch ($request->getStr('form_action','')) {
          case 'review':
            if (!$detail['may_approve']) { throw new RuntimeException('organization_approval_review_refused'); }
            $intent=$service->previewCompoundApproval($id);
            return $this->newDialog()->setTitle(pht('Review Compound Membership Change'))
              ->appendParagraph(pht('Team: %s; user: %s; change: %s',$intent['team'],$intent['target_user'],$this->actionLabel($intent['action'])))
              ->appendParagraph(pht('Membership: %s to %s; appointment: %s to %s',
                $this->dateLabel($intent['member_from']),$this->dateLabel($intent['member_until']),
                $this->dateLabel($intent['appointment_from']),$this->dateLabel($intent['appointment_until'])))
              ->appendParagraph(pht('This explicitly changes organizational membership and administrator appointment. It does not change native project permissions. The service rechecks the original request and all current sources before approval.'))
              ->addHiddenInput('approval',$id)->addHiddenInput('form_action','approve')
              ->addSubmitButton(pht('Approve Original Request'))
              ->addCancelButton($this->uri($id));
          case 'approve':
            if (!$detail['may_approve']) { throw new RuntimeException('organization_approval_review_refused'); }
            $service->approveApproval($id);break;
          case 'execute':
            if (!$detail['may_execute']) { throw new RuntimeException('organization_approval_execution_refused'); }
            // This method reacquires all canonical identities and rechecks the
            // immutable binding, source versions, expiry and consumed ledger.
            $service->executeApprovedCompound($id);break;
          default:throw new InvalidArgumentException('organization_approval_action_refused');
        }
        return (new AphrontRedirectResponse())->setURI($this->uri($id));
      }
      if ($id!=='') { return $this->detail($service->readApprovalWorkbenchDetail($id)); }
      $cursor=$request->getStr('after','');
      $page=$service->readApprovalWorkbenchPage(20,$cursor===''?null:$cursor);
      $labels=$this->peopleLabels($page['rows']);$rows=array();
      foreach ($page['rows'] as $item) {
        $rows[]=array(phutil_tag('a',array('href'=>$this->uri($item['approval_id'])),$item['approval_id']),
          $this->actionLabel($item['action']),$item['team'],$this->personLabel($item['target_user'],$labels),
          $this->stateLabel($item['state']),$this->dateLabel($item['expires_at']));
      }
      $box=(new PHUIObjectBoxView())->setHeaderText(pht('Compound Organization Approvals'))
        ->appendChild((new AphrontTableView($rows))->setHeaders(array(pht('Request'),pht('Change'),pht('Team'),pht('User'),pht('Recorded State'),pht('Expires')))
          ->setNoDataString(pht('No compound requests on this page.')));
      if ($page['next_cursor']!==null) {
        $box->appendChild(phutil_tag('a',array('href'=>(string)(new PhutilURI($this->home()))->setQueryParam('after',$page['next_cursor'])),pht('Next Request Page')));
      }
      return $this->newPage()->setTitle(pht('Organization Approvals'))->appendChild(array(
        phutil_tag('p',array(),pht('System administrators only. Recorded requests are discovery metadata, not authorization. Open a request for current review. Expired or changed sources are refused; consumed requests have no new action.')),
        phutil_tag('a',array('href'=>$this->getApplicationURI('organization/')),pht('Create an Explicit Membership and Appointment Request')),$box));
    } catch (Throwable $error) {
      return $this->newDialog()->setTitle(pht('Approval Request Unavailable'))
        ->appendParagraph(pht('The request could not be read or changed. It may be unavailable, expired, already consumed, or no longer match current authority. No approval is inferred from a displayed link.'))
        ->addCancelButton($this->home(),pht('Return to Approvals'));
    }
  }

  private function detail(array $item) {
    $labels=$this->peopleLabels(array($item));$rows=array();
    foreach (array('approval_id'=>pht('Request'),'team'=>pht('Team'),'target_user'=>pht('User'),
      'requester'=>pht('Requester'),'executor'=>pht('Executor'),'approver'=>pht('Approver'),
      'expected_team_version'=>pht('Requested Team Version'),
      'expected_member_version'=>pht('Requested Membership Version'),
      'expected_appointment_version'=>pht('Requested Appointment Version')) as $key=>$label) {
      $value=$item[$key]===null?pht('Not Yet Approved'):$item[$key];
      if(in_array($key,array('target_user','requester','executor','approver'),true)&&$item[$key]!==null){$value=$this->personLabel($item[$key],$labels);}
      $rows[]=array($label,$value);
    }
    $rows[]=array(pht('Change'),$this->actionLabel($item['action']));
    $rows[]=array(pht('Recorded State'),$this->stateLabel($item['state']));
    foreach (array('issued_at'=>pht('Requested'),'expires_at'=>pht('Expires'),
      'member_from'=>pht('Membership Starts'),'member_until'=>pht('Membership Ends'),
      'appointment_from'=>pht('Appointment Starts'),'appointment_until'=>pht('Appointment Ends')) as $key=>$label) {
      $rows[]=array($label,$this->dateLabel($item[$key]));
    }
    $box=(new PHUIObjectBoxView())->setHeaderText(pht('Original Compound Request'))
      ->appendChild((new AphrontTableView($rows))->setHeaders(array(pht('Field'),pht('Value'))));
    if ($item['may_approve']) { $box->appendChild($this->form($item['approval_id'],'review',pht('Review Original Request'))); }
    if ($item['may_execute']) { $box->appendChild($this->form($item['approval_id'],'execute',pht('Execute Approved Request'))); }
    $box->appendChild(phutil_tag('p',array(),pht('Current eligibility is rechecked on every POST. Neither a displayed action nor this page is an approval or an execution permit.')));
    return $this->newPage()->setTitle(pht('Organization Approval'))->appendChild($box);
  }
  /** Native People VIEW filtering only; never handles/images or authority. */
  private function peopleLabels(array $items) {
    $phids=array();foreach($items as$item){foreach(array('target_user','requester','executor','approver')as$key){
      if($item[$key]!==null){$phids[$item[$key]]=true;}}}
    if(count($phids)>100){throw new RuntimeException('organization_approval_label_budget');}
    if(!$phids){return array();}
    $users=(new PhabricatorPeopleQuery())->setViewer($this->getViewer())->withPHIDs(array_keys($phids))
      ->needProfileImage(false)->setLimit(count($phids))->execute();
    $labels=array();foreach($users as$user){if(!isset($phids[$user->getPHID()])){throw new RuntimeException('organization_approval_label_changed');}
      $labels[$user->getPHID()]=$this->nativePersonLabel($user);}
    return $labels;
  }
  /** Same profile URI rule as native People handle loading, without images. */
  private function nativePersonLabel(PhabricatorUser $user) {
    return phutil_tag('a',array('href'=>'/p/'.$user->getUsername().'/'),$user->getFullName());
  }
  private function personLabel($phid,array $labels) { return idx($labels,$phid,pht('Unavailable Person')); }
  private function dateLabel($value) { return $value===null?pht('Unchanged / No End Date'):phabricator_datetime($value,$this->getViewer()); }
  private function stateLabel($state) {
    switch ($state) { case 'submitted':return pht('Awaiting Independent Approval');case 'approved':return pht('Approved, Not Executed');case 'consumed':return pht('Consumed'); }
    throw new RuntimeException('organization_approval_state_refused');
  }
  private function actionLabel($action) {
    switch ($action) {case 'member.add-and-appoint':return pht('Add Membership and Administrator Appointment');case 'appointment.revoke-and-end':return pht('Revoke Appointment and End Membership');}
    throw new RuntimeException('organization_compound_action_refused');
  }
}

<?php

/** Small native People surface; all authority remains in the sealed service. */
final class PhorgeOrganizationController extends PhabricatorPeopleController {
  public function shouldRequireAdmin() { return false; }

  public function handleRequest(AphrontRequest $request) {
    if (PhabricatorEnv::getEnvConfig('governance.organization.enabled') !== true) {
      return new Aphront404Response();
    }
    $json = $request->getBool('json');
    try {
      $context = PhorgeGovernanceNativeActorContext::newFromWebRequest($request);
      if ($request->isHTTPPost()) {
        $request->validateCSRF();
        if ($request->getStr('form_action', '') !== '') {
          list($action, $payload) = $this->formPayload($request,$context);
        } else {
          $raw = $request->getStr('payload', '');
          if (strlen($raw) > 65536) { throw new InvalidArgumentException('organization_payload_refused'); }
          $payload = phutil_json_decode($raw);
          if (!is_array($payload)) { throw new InvalidArgumentException('organization_payload_refused'); }
          $action = $request->getStr('action');
        }
        if(!$json && $action==='unit.create' && !$request->getBool('confirm_depth')) {
          $preview=PhorgeOrganizationService::newForActor($context)->previewUnitChange(array(
            'unit_id'=>$payload['unit_id'],'kind'=>$payload['kind'],'parent_id'=>$payload['parent_id'],'expected_version'=>0));
          if($preview['warnings']){
            $form=id(new AphrontFormView())->setUser($this->getViewer())
              ->addHiddenInput('action',$action)->addHiddenInput('payload',phutil_json_encode($payload))
              ->addHiddenInput('confirm_depth',1);
            return $this->newDialog()->setTitle(pht('Organization Depth Warning'))
              ->appendParagraph(pht('This directory exceeds the recommended 8 levels. You may continue; native project depth is unchanged.'))
              ->appendForm($form)->addSubmitButton(pht('Continue'))->addCancelButton($this->getApplicationURI('organization/'));
          }
        }
        if(!$json && $action==='unit.update' && $payload['state']==='active' && !$request->getBool('confirm_depth')){
          $current=(new PhorgeOrganizationQuery($context))->getUnitForManagement($payload['unit_id']);
          if(!$current){throw new RuntimeException('organization_not_visible');}
          $unchanged_parent=isset($current['management_references']) && $current['management_references']['parent_id']===$payload['parent_id'];
          $preview=$unchanged_parent?array('warnings'=>array()):PhorgeOrganizationService::newForActor($context)->previewUnitChange(array('unit_id'=>$payload['unit_id'],
            'kind'=>$current['kind'],'parent_id'=>$payload['parent_id'],'expected_version'=>$payload['expected_version']));
          if($preview['warnings']){
            $form=id(new AphrontFormView())->setUser($this->getViewer())->addHiddenInput('action',$action)
              ->addHiddenInput('payload',phutil_json_encode($payload))->addHiddenInput('confirm_depth',1);
            return $this->newDialog()->setTitle(pht('Organization Depth Warning'))
              ->appendParagraph(pht('This directory exceeds the recommended 8 levels. You may continue; native project depth is unchanged.'))
              ->appendForm($form)->addSubmitButton(pht('Continue'))->addCancelButton($this->getApplicationURI('organization/'));
          }
        }
        if(!$json && $action==='unit.update' && $payload['state']==='archived' && !$request->getBool('confirm_archive')){
          $preview=PhorgeOrganizationService::newForActor($context)->previewUnitArchive($payload['unit_id']);
          if($preview['version']!==$payload['expected_version']){throw new RuntimeException('organization_stale_version');}
          $form=id(new AphrontFormView())->setUser($this->getViewer())->addHiddenInput('action',$action)
            ->addHiddenInput('payload',phutil_json_encode($payload))->addHiddenInput('confirm_archive',1);
          return $this->newDialog()->setTitle(pht('Archive Organization Unit'))
            ->appendParagraph(pht('Relationships and audit history are retained. Native project permissions are unchanged. Restore requires a separate review.'))
            ->appendParagraph(pht('Current and scheduled membership records: %s', $preview['effects']['member']))
            ->appendForm($form)->addSubmitButton(pht('Archive'))->addCancelButton($this->getApplicationURI('organization/'));
        }
        $result = PhorgeOrganizationEndpoint::write($context, $action, $payload);
        if ($json) { return id(new AphrontJSONResponse())->setContent(array('result' => $result)); }
        return id(new AphrontRedirectResponse())->setURI($this->getApplicationURI('organization/'));
      }
      if ($json) {
        $result = PhorgeOrganizationEndpoint::read($context, $request->getStr('mode', 'list'),
          $request->getStr('unit_id'), $request->getStr('user_phid'),
          $request->getInt('limit', 100), $request->getStr('after') ?: null);
        return id(new AphrontJSONResponse())->setContent(array('result' => $result));
      }
      return $this->buildDirectory($request, $context);
    } catch (Throwable $error) {
      if ($json) {
        return id(new AphrontJSONResponse())->setHTTPResponseCode(403)
          ->setContent(array('error' => pht('The organization request could not be completed.')));
      }
      return $this->newDialog()->setTitle(pht('Organization Request Unavailable'))
        ->appendParagraph(pht('The organization request could not be completed.'))
        ->addCancelButton($this->getApplicationURI(), pht('Return to People'));
    }
  }

  private function formPayload(AphrontRequest $request, PhorgeGovernanceNativeActorContext $context) {
    $operation = $request->getStr('operation_id');
    if ($request->getStr('form_action') === 'unit.create') {
      if(count($request->getArr('parent'))>1){throw new InvalidArgumentException('organization_parent_refused');}
      return array('unit.create', array('operation_id' => $operation,
        'unit_id' => $request->getStr('unit_id'), 'kind' => $request->getStr('kind'),
        'name' => $request->getStr('name'), 'parent_id' => head($request->getArr('parent')) ?: null));
    }
    if ($request->getStr('form_action') === 'unit.metadata') {
      $departments=$request->getArr('department');$contacts=$request->getArr('contact');
      if(count($departments)>1||count($contacts)>1){throw new InvalidArgumentException('organization_metadata_refused');}
      $references=$this->managementReferences($request,$context);
      return array('unit.metadata',array('operation_id'=>$operation,'unit_id'=>$request->getStr('unit_id'),
        'expected_version'=>$request->getInt('unit_version'),
        'associated_department_id'=>$this->referenceAction($request,'department_action')==='keep'?$references['department_id']:(head($departments)?:null),
        'contact_user_phid'=>$this->referenceAction($request,'contact_action')==='keep'?$references['contact_user_phid']:(head($contacts)?:null)));

    }
    if ($request->getStr('form_action') === 'member.update') {
      $date=(new AphrontFormDateControl())->setViewer($this->getViewer())->setName('until')->setAllowNull(true);
      $date->readValueFromRequest($request);if($date->getError()){throw new InvalidArgumentException('organization_period_refused');}
      return array('members.edit',array('operation_id'=>$operation,'mutations'=>array(array(
        'action'=>'member.update','approval_id'=>null,'expected_appointment_version'=>$request->getInt('appointment_version'),
        'expected_member_version'=>$request->getInt('member_version'),'expected_team_version'=>$request->getInt('unit_version'),
        'target_user'=>$request->getStr('user_phid'),'team'=>$request->getStr('unit_id'),
        'valid_from'=>$request->getInt('valid_from'),'valid_until'=>$date->getValue()===null?null:(int)$date->getValue()))));
    }
    if ($request->getStr('form_action') === 'department.assign') {
      $departments=$request->getArr('department');if(count($departments)!==1){throw new InvalidArgumentException('organization_department_required');}
      return array('department.assign',array('operation_id'=>$operation,'user_phid'=>$request->getStr('user_phid'),
        'expected_version'=>$request->getInt('primary_version'),'unit_id'=>head($departments)));
    }
    if ($request->getStr('form_action') === 'department.clear') {
      return array('department.clear',array('operation_id'=>$operation,'user_phid'=>$request->getStr('user_phid'),
        'expected_version'=>$request->getInt('primary_version')));
    }
    if ($request->getStr('form_action') === 'unit.update') {
      $parents=$request->getArr('parent');if(count($parents)>1){throw new InvalidArgumentException('organization_parent_refused');}
      $references=$this->managementReferences($request,$context);
      return array('unit.update',array('operation_id'=>$operation,'unit_id'=>$request->getStr('unit_id'),
        'expected_version'=>$request->getInt('unit_version'),'name'=>$request->getStr('name'),
        'state'=>$request->getStr('state'),
        'parent_id'=>$this->referenceAction($request,'parent_action')==='keep'?$references['parent_id']:(head($parents)?:null)));

    }
    if ($request->getStr('form_action') === 'member.end' || $request->getStr('form_action') === 'member.suspend') {
      return array('members.edit',array('operation_id'=>$operation,'mutations'=>array(array(
        'action'=>$request->getStr('form_action'),'approval_id'=>null,
        'expected_appointment_version'=>$request->getInt('appointment_version'),
        'expected_member_version'=>$request->getInt('member_version'),'expected_team_version'=>$request->getInt('unit_version'),
        'target_user'=>$request->getStr('user_phid'),'team'=>$request->getStr('unit_id'),
        'valid_from'=>null,'valid_until'=>null))));
    }
    if ($request->getStr('form_action') === 'member.add') {
      $users = $request->getArr('member');
      if (count($users) !== 1) { throw new InvalidArgumentException('organization_subject_refused'); }
      return array('members.edit', array('operation_id' => $operation,
        'mutations' => array(array('action' => 'member.add', 'approval_id' => null,
          'expected_appointment_version' => 0, 'expected_member_version' => 0,
          'expected_team_version' => $request->getInt('unit_version'),
          'target_user' => head($users), 'team' => $request->getStr('unit_id'),
          'valid_from' => 0, 'valid_until' => null))));
    }
    throw new InvalidArgumentException('organization_form_refused');
  }

  private function referenceAction(AphrontRequest $request,$name) {
    $action=$request->getStr($name,'keep');
    if(!in_array($action,array('keep','replace'),true)){throw new InvalidArgumentException('organization_reference_action_refused');}
    return $action;
  }

  private function managementReferences(AphrontRequest $request, PhorgeGovernanceNativeActorContext $context) {
    $unit=(new PhorgeOrganizationQuery($context))->getUnitForManagement($request->getStr('unit_id'));
    if(!$unit||!isset($unit['management_references'])){throw new RuntimeException('organization_system_admin_required');}
    if($unit['version']!==$request->getInt('unit_version')){throw new RuntimeException('organization_stale_version');}
    return $unit['management_references'];
  }

  private function buildDirectory(AphrontRequest $request,
    PhorgeGovernanceNativeActorContext $context) {
    $query = new PhorgeOrganizationQuery($context);
    $units = $query->searchUnits($request->getStr('query',''),100,$request->getStr('after') ?: null);
    $search=id(new AphrontFormView())->setUser($this->getViewer())->setMethod('GET')
      ->appendControl(id(new AphrontFormTextControl())->setLabel(pht('Search'))->setName('query')->setValue($request->getStr('query','')))
      ->appendControl(id(new AphrontFormSubmitControl())->setValue(pht('Search')));
    $rows = array();
    foreach ($units as $unit) {
      $uri = id(new PhutilURI($this->getApplicationURI('organization/')))
        ->setQueryParam('unit_id', $unit['unit_id']);
      $rows[] = array(phutil_tag('a', array('href' => (string)$uri), $unit['name']),
        $unit['kind'] === 'team' ? pht('Team') : pht('Department'),
        $unit['parent_id'] === null ? '' : $unit['parent_id'], $unit['version']);
    }
    $table = id(new AphrontTableView($rows))
      ->setHeaders(array(pht('Name'), pht('Type'), pht('Parent'), pht('Version')))
      ->setNoDataString(pht('No organization units are visible to you.'));
    $boxes = array(id(new PHUIObjectBoxView())->setHeaderText(pht('Organization Directory'))
      ->appendChild($search)->appendChild($table));
    $capabilities=$query->getActorCapabilities();
    $selected = $request->getStr('unit_id','');
    $selected_units=$units;
    if($selected!=='' && !in_array($selected,array_column($units,'unit_id'),true)){
      $selected_unit=$query->getUnit($selected);if($selected_unit){$selected_units[]=$selected_unit;}
    }
    foreach ($selected_units as $unit) {
      if ($unit['unit_id'] !== $selected) { continue; }
      $members = $query->listMembers($selected,100,$request->getStr('member_after')?:null);
      $member_rows = array();
      foreach ($members as $member) {
        $controls=array();
        if($unit['kind']==='team'){
          $controls[]=id(new AphrontFormView())->setUser($this->getViewer())
            ->addHiddenInput('form_action','member.update')->addHiddenInput('operation_id','web:'.bin2hex(random_bytes(16)))
            ->addHiddenInput('unit_id',$selected)->addHiddenInput('unit_version',$unit['version'])
            ->addHiddenInput('user_phid',$member['user_phid'])->addHiddenInput('member_version',$member['member_version'])
            ->addHiddenInput('appointment_version',$member['appointment_version'])->addHiddenInput('valid_from',$member['valid_from'])
            ->appendControl(id(new AphrontFormDateControl())->setViewer($this->getViewer())->setLabel(pht('Membership Ends'))
              ->setName('until')->setAllowNull(true)->setValue($member['valid_until']))
            ->appendControl(id(new AphrontFormSubmitControl())->setValue(pht('Update Membership Period')));
          foreach(array('member.end'=>pht('End Membership'),'member.suspend'=>pht('Suspend Membership')) as $action=>$label){
            $controls[]=id(new AphrontFormView())->setUser($this->getViewer())
              ->addHiddenInput('form_action',$action)->addHiddenInput('operation_id','web:'.bin2hex(random_bytes(16)))
              ->addHiddenInput('unit_id',$selected)->addHiddenInput('unit_version',$unit['version'])
              ->addHiddenInput('user_phid',$member['user_phid'])->addHiddenInput('member_version',$member['member_version'])
              ->addHiddenInput('appointment_version',$member['appointment_version'])
              ->appendControl(id(new AphrontFormSubmitControl())->setValue($label));
          }
        }elseif($capabilities['manage_units']){
          $controls[]=id(new AphrontFormView())->setUser($this->getViewer())
            ->addHiddenInput('form_action','department.clear')->addHiddenInput('operation_id','web:'.bin2hex(random_bytes(16)))
            ->addHiddenInput('user_phid',$member['user_phid'])->addHiddenInput('primary_version',$member['primary_department_version'])
            ->appendControl(id(new AphrontFormSubmitControl())->setValue(pht('Clear Main Department')));
        }
        $member_rows[] = array($member['user_phid'], $member['account_eligible']
          ? pht('Active Account') : pht('Inactive Account'),$controls);
      }
      $boxes[] = id(new PHUIObjectBoxView())->setHeaderText(pht('Members'))
        ->appendChild(id(new AphrontTableView($member_rows))
          ->setHeaders(array(pht('User'), pht('Account Status'),pht('Actions')))
          ->setNoDataString(pht('No current members.')));
      if($capabilities['manage_units']){
        $edit=id(new AphrontFormView())->setUser($this->getViewer())
          ->addHiddenInput('form_action','unit.update')->addHiddenInput('operation_id','web:'.bin2hex(random_bytes(16)))
          ->addHiddenInput('unit_id',$selected)->addHiddenInput('unit_version',$unit['version'])
          ->appendControl(id(new AphrontFormTextControl())->setName('name')->setLabel(pht('Name'))->setValue($unit['name']))
          ->appendControl(id(new AphrontFormSelectControl())->setName('parent_action')->setLabel(pht('Parent Action'))
            ->setOptions(array('keep'=>pht('Keep Existing Reference'),'replace'=>pht('Replace or Clear Reference')))->setValue('keep'))
          ->appendControl(id(new AphrontFormTokenizerControl())->setName('parent')->setLabel(pht('Parent'))
            ->setLimit(1)->setDatasource((new PhorgeOrganizationDatasource())->setActorContext($context))->setValue($unit['parent_id']===null?array():array($unit['parent_id'])))
          ->appendControl(id(new AphrontFormSelectControl())->setName('state')->setLabel(pht('State'))
            ->setOptions(array('active'=>pht('Active'),'archived'=>pht('Archive'))))
          ->appendControl(id(new AphrontFormSubmitControl())->setValue(pht('Save Organization Unit')));
        $boxes[]=id(new PHUIObjectBoxView())->setHeaderText(pht('Manage Organization Unit'))->appendChild($edit);
        if($unit['kind']==='team'){
          $meta=id(new AphrontFormView())->setUser($this->getViewer())
            ->addHiddenInput('form_action','unit.metadata')->addHiddenInput('operation_id','web:'.bin2hex(random_bytes(16)))
            ->addHiddenInput('unit_id',$selected)->addHiddenInput('unit_version',$unit['version'])
            ->appendControl(id(new AphrontFormStaticControl())->setValue(pht('Descriptive links do not grant directory or project permissions.')))
            ->appendControl(id(new AphrontFormSelectControl())->setName('department_action')->setLabel(pht('Department Action'))
              ->setOptions(array('keep'=>pht('Keep Existing Reference'),'replace'=>pht('Replace or Clear Reference')))->setValue('keep'))
            ->appendControl(id(new AphrontFormTokenizerControl())->setLabel(pht('Associated Department'))->setName('department')->setLimit(1)
              ->setDatasource((new PhorgeOrganizationDatasource())->setActorContext($context))
              ->setValue($unit['department_id']===null?array():array($unit['department_id'])))
            ->appendControl(id(new AphrontFormSelectControl())->setName('contact_action')->setLabel(pht('Contact Action'))
              ->setOptions(array('keep'=>pht('Keep Existing Reference'),'replace'=>pht('Replace or Clear Reference')))->setValue('keep'))
            ->appendControl(id(new AphrontFormTokenizerControl())->setLabel(pht('Contact'))->setName('contact')->setLimit(1)
              ->setDatasource(new PhabricatorPeopleDatasource())->setValue($unit['contact_user_phid']===null?array():array($unit['contact_user_phid'])))
            ->appendControl(id(new AphrontFormSubmitControl())->setValue(pht('Save Descriptive Metadata')));
          $boxes[]=id(new PHUIObjectBoxView())->setHeaderText(pht('Descriptive Organization Metadata'))->appendChild($meta);
        }

      }
      if ($unit['kind'] === 'team') {
        $form = id(new AphrontFormView())->setUser($this->getViewer())
          ->addHiddenInput('form_action', 'member.add')
          ->addHiddenInput('operation_id', 'web:'.bin2hex(random_bytes(16)))
          ->addHiddenInput('unit_id', $selected)->addHiddenInput('unit_version', $unit['version'])
          ->appendControl(id(new AphrontFormTokenizerControl())->setLabel(pht('User'))
            ->setName('member')->setLimit(1)->setDatasource(new PhabricatorPeopleDatasource()))
          ->appendControl(id(new AphrontFormSubmitControl())->setValue(pht('Add Ordinary Member')));
        $boxes[] = id(new PHUIObjectBoxView())->setHeaderText(pht('Add Ordinary Member'))
          ->appendChild($form);
      }
    }
    $capabilities = $query->getActorCapabilities();
    if ($capabilities['manage_units']) {
      $form = id(new AphrontFormView())->setUser($this->getViewer())
        ->addHiddenInput('form_action', 'unit.create')
        ->addHiddenInput('operation_id', 'web:'.bin2hex(random_bytes(16)))
        ->addHiddenInput('unit_id', 'unit-'.bin2hex(random_bytes(12)))
        ->appendControl(id(new AphrontFormTextControl())->setLabel(pht('Name'))->setName('name'))
        ->appendControl(id(new AphrontFormSelectControl())->setLabel(pht('Type'))->setName('kind')
          ->setOptions(array('team' => pht('Team'), 'department' => pht('Department'))))
        ->appendControl(id(new AphrontFormTokenizerControl())->setLabel(pht('Parent'))->setName('parent')
          ->setLimit(1)->setDatasource((new PhorgeOrganizationDatasource())->setActorContext($context)))
        ->appendControl(id(new AphrontFormSubmitControl())->setValue(pht('Create Organization Unit')));
      $boxes[] = id(new PHUIObjectBoxView())->setHeaderText(pht('Create Organization Unit'))
        ->appendChild($form);
    }
    if(count($units)===100){$uri=(new PhutilURI($this->getApplicationURI('organization/')))
      ->setQueryParam('after',last($units)['unit_id'])->setQueryParam('query',$request->getStr('query',''));
      $boxes[]=phutil_tag('a',array('href'=>(string)$uri),pht('Next Organization Page'));}
    if($selected!=='' && isset($members) && count($members)===100){$uri=(new PhutilURI($this->getApplicationURI('organization/')))
      ->setQueryParam('unit_id',$selected)->setQueryParam('member_after',last($members)['user_phid']);
      $boxes[]=phutil_tag('a',array('href'=>(string)$uri),pht('Next Member Page'));}
    if($capabilities['manage_units']){
      $unassigned=$query->listUnassignedUsers(20,$request->getStr('unassigned_after')?:null);$unassigned_rows=array();
      foreach($unassigned as $person){$form=id(new AphrontFormView())->setUser($this->getViewer())
        ->addHiddenInput('form_action','department.assign')->addHiddenInput('operation_id','web:'.bin2hex(random_bytes(16)))
        ->addHiddenInput('user_phid',$person['user_phid'])->addHiddenInput('primary_version',$person['primary_department_version'])
        ->appendControl(id(new AphrontFormTokenizerControl())->setLabel(pht('Department'))->setName('department')->setLimit(1)
          ->setDatasource((new PhorgeOrganizationDatasource())->setActorContext($context)))
        ->appendControl(id(new AphrontFormSubmitControl())->setValue(pht('Assign Main Department')));
        if($person['reason']==='inactive_department'){
          // Clearing the inactive current reference is explicit and audited.
          // Reassignment is a separate request; do not bypass transfer fencing.
          $form=id(new AphrontFormView())->setUser($this->getViewer())
            ->addHiddenInput('form_action','department.clear')->addHiddenInput('operation_id','web:'.bin2hex(random_bytes(16)))
            ->addHiddenInput('user_phid',$person['user_phid'])->addHiddenInput('primary_version',$person['primary_department_version'])
            ->appendControl(id(new AphrontFormSubmitControl())->setValue(pht('Clear Main Department')));
        }
        $reasons=array('unassigned'=>pht('Unassigned'),'inactive_department'=>pht('Inactive Department'));
        $unassigned_rows[]=array($person['name'],$person['username'],idx($reasons,$person['reason'],pht('Unavailable')),$form);}
      if(count($unassigned)===20){$uri=(new PhutilURI($this->getApplicationURI('organization/')))->setQueryParam('unassigned_after',last($unassigned)['user_phid']);
        $boxes[]=phutil_tag('a',array('href'=>(string)$uri),pht('Next Unassigned Page'));}
      $boxes[]=id(new PHUIObjectBoxView())->setHeaderText(pht('Main Department Attention Required'))
        ->appendChild(id(new AphrontTableView($unassigned_rows))->setHeaders(array(pht('Name'),pht('User'),pht('Reason'),pht('Actions')))
          ->setNoDataString(pht('No unassigned users on this page.')));
    }
    $crumbs = $this->buildApplicationCrumbs()->addTextCrumb(pht('Organization Directory'));
    return $this->newPage()->setCrumbs($crumbs)->setTitle(pht('Organization Directory'))
      ->appendChild($boxes);
  }
}

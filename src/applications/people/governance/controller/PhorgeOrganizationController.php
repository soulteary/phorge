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
      if ($request->getStr('bulk','')!=='' || strncmp($request->getStr('form_action',''),'bulk.',5)===0) {
        return $this->buildBulk($request,$context);
      }
      if (!$request->isHTTPPost() && $request->getStr('view')==='tree') {
        return $this->buildTree($request,$context);
      }
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
        if(!$json && $action==='approval.approve' && !$request->getBool('confirm_compound_approval')){
          $preview=PhorgeOrganizationService::newForActor($context)->previewCompoundApproval($payload['approval_id']);
          $form=id(new AphrontFormView())->setUser($this->getViewer())->addHiddenInput('action',$action)
            ->addHiddenInput('payload',phutil_json_encode($payload))->addHiddenInput('confirm_compound_approval',1);
          return $this->newDialog()->setTitle(pht('Review Compound Membership Impact'))
            ->appendParagraph(pht('Team: %s; User: %s; Change: %s',$preview['team'],$preview['target_user'],
              $preview['action']==='member.add-and-appoint'?pht('Add Membership and Appointment'):pht('Revoke Appointment and End Membership')))
            ->appendParagraph(pht('Membership period: %s to %s; Appointment period: %s to %s',
              $preview['member_from']===null?pht('Unchanged'):$preview['member_from'],
              $preview['member_until']===null?pht('No End Date'):$preview['member_until'],
              $preview['appointment_from']===null?pht('Unchanged'):$preview['appointment_from'],
              $preview['appointment_until']===null?pht('No End Date'):$preview['appointment_until']))
            ->appendParagraph(pht('Adding membership may grant business access through this team. Approval never changes native project permissions.'))
            ->appendForm($form)->addSubmitButton(pht('Approve'))->addCancelButton($this->getApplicationURI('organization/'));
        }
        $result = PhorgeOrganizationEndpoint::write($context, $action, $payload);
        if ($json) { return id(new AphrontJSONResponse())->setContent(array('result' => $result)); }
        if($action==='compound.propose'){
          return $this->newDialog()->setTitle(pht('Compound Approval Requested'))
            ->appendParagraph(pht('Approval ID: %s', $result['approval_id']))
            ->appendParagraph(pht('A different current system administrator must approve this request. The requester then executes it.'))
            ->addCancelButton((string)(new PhutilURI($this->getApplicationURI('organization/approvals/')))->setQueryParam('approval',$result['approval_id']),pht('Open Approval Request'));
        }

        return id(new AphrontRedirectResponse())->setURI($this->getApplicationURI('organization/'));
      }
      if ($json) {
        $result = PhorgeOrganizationEndpoint::read($context, $request->getStr('mode', 'list'),
          $request->getStr('unit_id'), $request->getStr('user_phid'),
          $request->getInt('limit', 100), ($request->getStr('after')===''?null:$request->getStr('after')));
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

  private function buildBulk(AphrontRequest $request,PhorgeGovernanceNativeActorContext $context) {
    $query=new PhorgeOrganizationQuery($context);$mode=$request->getStr('bulk');
    if(!in_array($mode,array('import','replace'),true)){throw new InvalidArgumentException('organization_bulk_request_refused');}
    $unit=$mode==='import'?$query->getUnit($request->getStr('unit_id')):null;
    if(($mode==='replace'&&!$query->getActorCapabilities()['manage_units']) ||
        ($mode==='import'&&(!$unit||$unit['kind']!=='team'))){throw new RuntimeException('organization_actor_forbidden');}
    $uri=$this->getApplicationURI('organization/');
    if($request->isHTTPPost()){
      $request->validateCSRF();
      if($request->getStr('form_action')==='bulk.commit'){
        $raw=$request->getStr('confirmed_intent','');if(strlen($raw)>16384){throw new InvalidArgumentException('organization_bulk_request_refused');}
        $intent=phutil_json_decode($raw);
        if(!is_array($intent)||$intent['mode']!==$mode||($mode==='import'&&$intent['unit_id']!==$unit['unit_id'])){
          throw new InvalidArgumentException('organization_bulk_request_refused');}
        PhorgeOrganizationEndpoint::write($context,'members.commit',array('intent'=>$intent,
          'expected_digest'=>$request->getStr('expected_digest'),'acknowledge_impact'=>$request->getBool('acknowledge_bulk')));
        return id(new AphrontRedirectResponse())->setURI($uri);
      }
      if($request->getStr('form_action')!=='bulk.preview'){throw new InvalidArgumentException('organization_bulk_request_refused');}
      $users=$request->getArr('subject');
      if($mode==='replace'&&count($users)!==1){throw new InvalidArgumentException('organization_subject_refused');}
      $selection=$request->getArr('selection');
      if($mode==='import'){
        $raw=$request->getStr('usernames','');if(strlen($raw)>8192){throw new InvalidArgumentException('organization_bulk_limit');}
        $names=preg_split('/[\s,;]+/u',trim($raw),-1,PREG_SPLIT_NO_EMPTY);
        if(count($names)>200){throw new InvalidArgumentException('organization_bulk_limit');}
        if($names){$people=id(new PhabricatorPeopleQuery())->setViewer($this->getViewer())->withUsernames(array_values(array_unique($names)))->execute();
          $resolved=array();foreach($people as$person){$resolved[$person->getUsername()]=$person->getPHID();}
          foreach($names as$name){if(!isset($resolved[$name])){throw new InvalidArgumentException('organization_bulk_user_unavailable');}$selection[]=$resolved[$name];}}
      }
      $intent=PhorgeOrganizationRequest::bulk(array('mode'=>$mode,'operation_id'=>'web:'.bin2hex(random_bytes(16)),
        'unit_id'=>$unit?$unit['unit_id']:null,'user_phid'=>$mode==='replace'?head($users):null,
        'selection'=>$selection,'valid_from'=>0,'valid_until'=>null));
      $plan=PhorgeOrganizationEndpoint::write($context,'members.preview',$intent);
      $rows=array();$actions=array('keep'=>pht('Keep Existing Period'),'member.add'=>pht('Add Member'),
        'member.update'=>pht('Reactivate Member'),'member.end'=>pht('End Membership'));
      $handles=$this->getViewer()->loadHandles(array_unique(ipull($plan['changes'],'user_phid')));
      foreach($plan['changes']as$change){$team=$query->getUnit($change['team']);if(!$team){throw new RuntimeException('organization_not_visible');}
        $rows[]=array($team['name'],$handles[$change['user_phid']]->renderLink(),$actions[$change['action']]);}
      $form=id(new AphrontFormView())->setUser($this->getViewer())->addHiddenInput('bulk',$mode)
        ->addHiddenInput('unit_id',$unit?$unit['unit_id']:'')->addHiddenInput('form_action','bulk.commit')
        ->addHiddenInput('confirmed_intent',phutil_json_encode($intent))->addHiddenInput('expected_digest',$plan['digest'])
        ->appendControl(id(new AphrontFormCheckboxControl())->addCheckbox('acknowledge_bulk',1,
          pht('I reviewed every change. Team membership may affect explicit content grants. Native project permissions and administrator appointments are unchanged.'),false))
        ->appendControl(id(new AphrontFormSubmitControl())->setValue(pht('Apply Reviewed Changes')));
      $box=id(new PHUIObjectBoxView())->setHeaderText(pht('Review Membership Changes'))
        ->appendChild(id(new AphrontTableView($rows))->setHeaders(array(pht('Team'),pht('User'),pht('Change'))))
        ->appendChild($form);
    }else{
      $form=id(new AphrontFormView())->setUser($this->getViewer())->addHiddenInput('bulk',$mode)
        ->addHiddenInput('unit_id',$unit?$unit['unit_id']:'')->addHiddenInput('form_action','bulk.preview');
      if($mode==='import'){
        $form->appendControl(id(new AphrontFormTokenizerControl())->setLabel(pht('Existing Users'))->setName('selection')
          ->setDatasource(new PhabricatorPeopleDatasource()))
          ->appendControl(id(new AphrontFormTextAreaControl())->setLabel(pht('Or Paste Usernames'))->setName('usernames')
            ->setCaption(pht('Up to 200 existing usernames, separated by spaces, commas or newlines. Duplicate selections are merged. No accounts are created.')));
      }else{
        $form->appendControl(id(new AphrontFormTokenizerControl())->setLabel(pht('User'))->setName('subject')->setLimit(1)
          ->setDatasource(new PhabricatorPeopleDatasource()))
          ->appendControl(id(new AphrontFormTokenizerControl())->setLabel(pht('Complete Desired Team List'))->setName('selection')
            ->setDatasource((new PhorgeOrganizationDatasource())->setActorContext($context)))
          ->appendRemarkupInstructions(pht('An empty list explicitly ends all ordinary team memberships. Administrator prerequisites cannot be removed here. Departments, appointments and project memberships are preserved. At most 200 teams may be affected.'));
      }
      $form->appendControl(id(new AphrontFormSubmitControl())->setValue(pht('Preview Changes')));
      $box=id(new PHUIObjectBoxView())->setHeaderText($mode==='import'?pht('Import Ordinary Members'):pht('Replace User Team Memberships'))->appendChild($form);
    }
    return $this->newPage()->setTitle(pht('Organization Memberships'))->appendChild($box);
  }

  /** Server-rendered lazy tree: each branch has its own visible-only cursor. */
  private function buildTree(AphrontRequest $request,PhorgeGovernanceNativeActorContext $context) {
    $query=new PhorgeOrganizationQuery($context);$parent=$request->getStr('tree_parent');$parent=$parent===''?null:$parent;
    $search=$request->getStr('query','');$after=($request->getStr('after')===''?null:$request->getStr('after'));
    $units=$query->treePage($parent,100,$after,$search);
    $uri=function($parent=null,$after=null)use($search){return (string)(new PhutilURI($this->getApplicationURI('organization/')))
      ->setQueryParam('view','tree')->setQueryParam('tree_parent',$parent)->setQueryParam('after',$after)->setQueryParam('query',$search);};
    $crumbs=$this->buildApplicationCrumbs()->addTextCrumb(pht('Organization Directory'),$uri());
    $ancestors=array();$seen=array();$cursor=$parent;
    while($cursor!==null){
      if(isset($seen[$cursor])||count($seen)>=64){throw new RuntimeException('organization_tree_cycle');}
      $seen[$cursor]=true;$row=$query->getUnit($cursor);if(!$row){throw new RuntimeException('organization_not_visible');}
      $ancestors[]=$row;$cursor=$row['parent_id'];
    }
    foreach(array_reverse($ancestors)as$row){$crumbs->addTextCrumb($row['name'],$uri($row['unit_id']));}
    $items=array();foreach($units as$unit){$select=(string)(new PhutilURI($this->getApplicationURI('organization/')))->setQueryParam('unit_id',$unit['unit_id']);
      $items[]=phutil_tag('li',array(),array(phutil_tag('a',array('href'=>$select),$unit['name']),' — ',
        $unit['kind']==='team'?pht('Team'):pht('Department'),' · ',
        phutil_tag('a',array('href'=>$uri($unit['unit_id'])),pht('Open Children'))));}
    $form=id(new AphrontFormView())->setUser($this->getViewer())->setMethod('GET')->addHiddenInput('view','tree')
      ->addHiddenInput('tree_parent',$parent===null?'':$parent)->appendControl(id(new AphrontFormTextControl())->setName('query')->setLabel(pht('Filter This Branch'))->setValue($search))
      ->appendControl(id(new AphrontFormSubmitControl())->setValue(pht('Search')));
    $children=array($form,phutil_tag('ul',array(),$items));
    if(!$units){$children[]=phutil_tag('p',array(),pht('No visible units in this branch.'));}
    if(count($units)===100){$children[]=phutil_tag('a',array('href'=>$uri($parent,last($units)['unit_id'])),pht('Next Organization Page'));}
    if($parent!==null){$children[]=phutil_tag('a',array('href'=>$uri($ancestors[0]['parent_id'])),pht('Up One Level'));}
    $children[]=phutil_tag('a',array('href'=>$this->getApplicationURI('organization/')),pht('Flat Directory'));
    return $this->newPage()->setCrumbs($crumbs)->setTitle(pht('Organization Tree'))
      ->appendChild(id(new PHUIObjectBoxView())->setHeaderText(pht('Organization Tree'))->appendChild($children));
  }

  private function formPayload(AphrontRequest $request, PhorgeGovernanceNativeActorContext $context) {
    $operation = $request->getStr('operation_id');
    if(in_array($request->getStr('form_action'),array('compound.approve','compound.execute'),true)){
      return array($request->getStr('form_action')==='compound.approve'?'approval.approve':'compound.execute-approved',
        array('approval_id'=>$request->getStr('approval_id')));
    }
    if($request->getStr('form_action')==='compound.propose'){
      $grant=$request->getStr('compound_action')==='member.add-and-appoint';
      if(!$grant && $request->getStr('compound_action')!=='appointment.revoke-and-end'){
        throw new InvalidArgumentException('organization_compound_request_refused');}
      $members=$request->getArr('compound_subject');if(count($members)!==1){throw new InvalidArgumentException('organization_compound_request_refused');}
      $input=array('action'=>$request->getStr('compound_action'),'approval_id'=>$request->getStr('approval_id'),
        'operation_id'=>$operation,'team'=>$request->getStr('unit_id'),'target_user'=>head($members),
        'expected_team_version'=>$request->getInt('unit_version'),'expected_member_version'=>$request->getInt('member_version'),
        'expected_appointment_version'=>$request->getInt('appointment_version'),'impact_acknowledged'=>$request->getBool('impact_acknowledged'),
        'member_from'=>$grant?0:null,'member_until'=>null,'appointment_from'=>$grant?0:null,'appointment_until'=>null,'ttl_seconds'=>3600);
      if($grant){foreach(array('member','appointment')as$kind){
        $date=(new AphrontFormDateControl())->setViewer($this->getViewer())->setName('compound_'.$kind.'_until')->setAllowNull(true);
        $date->readValueFromRequest($request);if($date->getError()){throw new InvalidArgumentException('organization_period_refused');}
        $input[$kind.'_until']=$date->getValue()===null?null:(int)$date->getValue();}}
      return array('compound.propose',$input);
    }
    if ($request->getStr('form_action') === 'unit.create') {
      if(count($request->getArr('parent'))>1){throw new InvalidArgumentException('organization_parent_refused');}
      return array('unit.create', array('operation_id' => $operation,
        'unit_id' => $request->getStr('unit_id'), 'kind' => $request->getStr('kind'),
        'name' => $request->getStr('name'), 'parent_id' => $this->firstReference($request->getArr('parent'))));
    }
    if ($request->getStr('form_action') === 'unit.metadata') {
      $departments=$request->getArr('department');$contacts=$request->getArr('contact');
      if(count($departments)>1||count($contacts)>1){throw new InvalidArgumentException('organization_metadata_refused');}
      $references=$this->managementReferences($request,$context);
      return array('unit.metadata',array('operation_id'=>$operation,'unit_id'=>$request->getStr('unit_id'),
        'expected_version'=>$request->getInt('unit_version'),
        'associated_department_id'=>$this->referenceAction($request,'department_action')==='keep'?$references['department_id']:$this->firstReference($departments),
        'contact_user_phid'=>$this->referenceAction($request,'contact_action')==='keep'?$references['contact_user_phid']:$this->firstReference($contacts)));

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
        'parent_id'=>$this->referenceAction($request,'parent_action')==='keep'?$references['parent_id']:$this->firstReference($parents)));

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

  private function firstReference(array $values) {
    if(count($values)>1){throw new InvalidArgumentException('organization_reference_action_refused');}
    return $values?head($values):null;
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
    $units = $query->searchUnits($request->getStr('query',''),100,($request->getStr('after')===''?null:$request->getStr('after')));
    $tree_link=phutil_tag('a',array('href'=>(string)(new PhutilURI($this->getApplicationURI('organization/')))->setQueryParam('view','tree')),pht('Organization Tree'));
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
        if($capabilities['manage_units'] && $unit['kind']==='team' && $member['appointment_version']>0){
          $controls[]=id(new AphrontFormView())->setUser($this->getViewer())
            ->addHiddenInput('form_action','compound.propose')->addHiddenInput('compound_action','appointment.revoke-and-end')
            ->addHiddenInput('operation_id','web:'.bin2hex(random_bytes(16)))->addHiddenInput('approval_id','approval:'.bin2hex(random_bytes(16)))
            ->addHiddenInput('unit_id',$selected)->addHiddenInput('unit_version',$unit['version'])
            ->addHiddenInput('compound_subject[]',$member['user_phid'])->addHiddenInput('member_version',$member['member_version'])
            ->addHiddenInput('appointment_version',$member['appointment_version'])
            ->appendControl(id(new AphrontFormCheckboxControl())->addCheckbox('impact_acknowledged',1,
              pht('I acknowledge that this explicitly ends membership and administrator appointment.'),false))
            ->appendControl(id(new AphrontFormSubmitControl())->setValue(pht('Request Revocation and Membership End')));
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
        $boxes[]=phutil_tag('a',array('href'=>(string)(new PhutilURI($this->getApplicationURI('organization/')))
          ->setQueryParam('bulk','import')->setQueryParam('unit_id',$selected)),pht('Import Ordinary Members'));

        $form = id(new AphrontFormView())->setUser($this->getViewer())
          ->addHiddenInput('form_action', 'member.add')
          ->addHiddenInput('operation_id', 'web:'.bin2hex(random_bytes(16)))
          ->addHiddenInput('unit_id', $selected)->addHiddenInput('unit_version', $unit['version'])
          ->appendControl(id(new AphrontFormTokenizerControl())->setLabel(pht('User'))
            ->setName('member')->setLimit(1)->setDatasource(new PhabricatorPeopleDatasource()))
          ->appendControl(id(new AphrontFormSubmitControl())->setValue(pht('Add Ordinary Member')));
        $boxes[] = id(new PHUIObjectBoxView())->setHeaderText(pht('Add Ordinary Member'))
          ->appendChild($form);
        if($capabilities['manage_units']){
          $compound=id(new AphrontFormView())->setUser($this->getViewer())
            ->addHiddenInput('form_action','compound.propose')->addHiddenInput('compound_action','member.add-and-appoint')
            ->addHiddenInput('operation_id','web:'.bin2hex(random_bytes(16)))->addHiddenInput('approval_id','approval:'.bin2hex(random_bytes(16)))
            ->addHiddenInput('unit_id',$selected)->addHiddenInput('unit_version',$unit['version'])
            ->addHiddenInput('member_version',0)->addHiddenInput('appointment_version',0)
            ->appendControl(id(new AphrontFormTokenizerControl())->setLabel(pht('User'))->setName('compound_subject')->setLimit(1)->setDatasource(new PhabricatorPeopleDatasource()));
          foreach(array('member'=>pht('Membership Ends'),'appointment'=>pht('Appointment Ends'))as$kind=>$label){
            $compound->appendControl(id(new AphrontFormDateControl())->setViewer($this->getViewer())->setName('compound_'.$kind.'_until')
              ->setLabel($label)->setAllowNull(true)->setValue(null));}
          $compound->appendControl(id(new AphrontFormCheckboxControl())->addCheckbox('impact_acknowledged',1,
            pht('Adding membership may grant business access through this team. I request explicit membership and administrator approval.'),false))
            ->appendControl(id(new AphrontFormSubmitControl())->setValue(pht('Request Membership and Appointment')));
          $boxes[]=id(new PHUIObjectBoxView())->setHeaderText(pht('Explicit Membership and Appointment'))->appendChild($compound);
        }

      }
    }
    $capabilities = $query->getActorCapabilities();
    $boxes[]=$tree_link;
    if ($capabilities['manage_units']) {
      $boxes[]=phutil_tag('a',array('href'=>(string)(new PhutilURI($this->getApplicationURI('organization/')))->setQueryParam('bulk','replace')),pht('Replace User Team Memberships'));
      $boxes[]=phutil_tag('a',array('href'=>$this->getApplicationURI('organization/transfer/')),pht('Organization Transfers'));
      $boxes[]=phutil_tag('a',array('href'=>$this->getApplicationURI('organization/history/')),pht('Restricted Organization History'));
      $boxes[]=phutil_tag('a',array('href'=>$this->getApplicationURI('organization/approvals/')),pht('Compound Approval Workbench'));
      foreach(array('compound.approve'=>pht('Approve Compound Request'),'compound.execute'=>pht('Execute Approved Compound'))as$action=>$label){
        $approval_form=id(new AphrontFormView())->setUser($this->getViewer())->addHiddenInput('form_action',$action)
          ->appendControl(id(new AphrontFormTextControl())->setLabel(pht('Approval ID'))->setName('approval_id'))
          ->appendControl(id(new AphrontFormSubmitControl())->setValue($label));
        $boxes[]=id(new PHUIObjectBoxView())->setHeaderText($label)->appendChild($approval_form);
      }
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

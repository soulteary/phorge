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
          list($action, $payload) = $this->formPayload($request);
        } else {
          $raw = $request->getStr('payload', '');
          if (strlen($raw) > 65536) { throw new InvalidArgumentException('organization_payload_refused'); }
          $payload = phutil_json_decode($raw);
          if (!is_array($payload)) { throw new InvalidArgumentException('organization_payload_refused'); }
          $action = $request->getStr('action');
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

  private function formPayload(AphrontRequest $request) {
    $operation = $request->getStr('operation_id');
    if ($request->getStr('form_action') === 'unit.create') {
      return array('unit.create', array('operation_id' => $operation,
        'unit_id' => $request->getStr('unit_id'), 'kind' => $request->getStr('kind'),
        'name' => $request->getStr('name'), 'parent_id' => null));
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

  private function buildDirectory(AphrontRequest $request,
    PhorgeGovernanceNativeActorContext $context) {
    $query = new PhorgeOrganizationQuery($context);
    $units = $query->listUnits(100, $request->getStr('after') ?: null);
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
      ->appendChild($table));
    $selected = $request->getStr('unit_id');
    foreach ($units as $unit) {
      if ($unit['unit_id'] !== $selected) { continue; }
      $members = $query->listMembers($selected);
      $member_rows = array();
      foreach ($members as $member) {
        $member_rows[] = array($member['user_phid'], $member['account_eligible']
          ? pht('Active Account') : pht('Inactive Account'));
      }
      $boxes[] = id(new PHUIObjectBoxView())->setHeaderText(pht('Members'))
        ->appendChild(id(new AphrontTableView($member_rows))
          ->setHeaders(array(pht('User'), pht('Account Status')))
          ->setNoDataString(pht('No current members.')));
      if ($unit['kind'] === 'team') {
        $form = id(new AphrontFormView())->setUser($this->getViewer())
          ->addHiddenInput('form_action', 'member.add')
          ->addHiddenInput('operation_id', 'web:'.bin2hex(random_bytes(16)))
          ->addHiddenInput('unit_id', $selected)->addHiddenInput('unit_version', $unit['version'])
          ->appendChild(id(new AphrontFormTokenizerControl())->setLabel(pht('User'))
            ->setName('member')->setLimit(1)->setDatasource(new PhabricatorPeopleDatasource()))
          ->appendChild(id(new AphrontFormSubmitControl())->setValue(pht('Add Ordinary Member')));
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
        ->appendChild(id(new AphrontFormTextControl())->setLabel(pht('Name'))->setName('name'))
        ->appendChild(id(new AphrontFormSelectControl())->setLabel(pht('Type'))->setName('kind')
          ->setOptions(array('team' => pht('Team'), 'department' => pht('Department'))))
        ->appendChild(id(new AphrontFormSubmitControl())->setValue(pht('Create Organization Unit')));
      $boxes[] = id(new PHUIObjectBoxView())->setHeaderText(pht('Create Organization Unit'))
        ->appendChild($form);
    }
    $crumbs = $this->buildApplicationCrumbs()->addTextCrumb(pht('Organization Directory'));
    return $this->newPage()->setCrumbs($crumbs)->setTitle(pht('Organization Directory'))
      ->appendChild($boxes);
  }
}

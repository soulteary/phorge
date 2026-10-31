<?php

final class PhabricatorPolicyEditController
  extends PhabricatorPolicyController {

  public function handleRequest(AphrontRequest $request) {
    $viewer = $this->getViewer();
    $request_data = $request->getRequestData();
    $capability = idx($request_data, 'capability');
    if ($capability !== null && $capability !== '' &&
        (!is_string($capability) ||
         !PhabricatorPolicyCapability::getCapabilityByKey($capability))) {
      return $this->newUnavailableRuleResponse();
    }

    $object_phid = $request->getURIData('objectPHID');
    if ($object_phid) {
      $object_type = phid_get_type($object_phid);
      $object = id(new PhabricatorObjectQuery())
        ->setViewer($viewer)
        ->withPHIDs(array($object_phid))
        ->executeOne();
    } else {
      $object_type = $request->getURIData('objectType');
      if (!$object_type) {
        $object_type = $request->getURIData('templateType');
      }

      $phid_types = PhabricatorPHIDType::getAllInstalledTypes($viewer);
      if (empty($phid_types[$object_type])) {
        return new Aphront404Response();
      }
      $object = $phid_types[$object_type]->newObject();
    }
    if (!$object) {
      return new Aphront404Response();
    }

    $phid = $request->getURIData('phid');

    switch ($phid) {
      case AphrontFormPolicyControl::getSelectProjectKey():
        return $this->handleProjectRequest($request);
      case AphrontFormPolicyControl::getSelectNamedKey():
        return $this->handleNamedPolicyRequest($request, $object_type);
      case AphrontFormPolicyControl::getSelectCustomKey():
        $phid = null;
        break;
      default:
        break;
    }

    $action_options = array(
      PhabricatorPolicy::ACTION_ALLOW => pht('Allow'),
      PhabricatorPolicy::ACTION_DENY => pht('Deny'),
    );

    $rules = id(new PhutilClassMapQuery())
      ->setAncestorClass(PhabricatorPolicyRule::class)
      ->execute();

    $rules = self::selectBusinessRules($rules, $object);
    $default_rule = array(
      'action' => head_key($action_options),
      'rule' => head_key($rules),
      'value' => null,
    );

    if ($phid) {
      $policies = id(new PhabricatorPolicyQuery())
        ->setViewer($viewer)
        ->withPHIDs(array($phid))
        ->execute();
      if (!$policies) {
        return new Aphront404Response();
      }
      $policy = head($policies);
    } else {
      if (!$rules) {
        return $this->newUnavailableRuleResponse();
      }
      $policy = id(new PhabricatorPolicy())
        ->setRules(array($default_rule))
        ->setDefaultAction(PhabricatorPolicy::ACTION_DENY);
    }

    // Never pass an unavailable stored rule to the native JavaScript editor:
    // its missing option/template could change what the person sees or saves.
    // Policies are immutable and their original evaluation remains intact.
    if ($phid && self::hasUnavailableRules($policy, $rules)) {
      return $this->newHistoricalPolicyResponse($request, $policy);
    }
    if (!$rules) {
      return $this->newUnavailableRuleResponse();
    }

    // Continue the same policy-control Workflow, preserving its result handler.
    // This first POST only opens a fresh editor; the next explicit Save Policy
    // creates a policy and the outer object form still has to apply it.
    $starting_replacement = $request->getExists('__start_policy_replacement__');
    if ($starting_replacement &&
        (!$request->isFormPost() ||
         idx($request_data, '__start_policy_replacement__') !== '1' ||
         $request->getExists('rules') || $phid)) {
      return $this->newUnavailableRuleResponse();
    }

    $root_id = celerity_generate_unique_node_id();

    $default_action = $policy->getDefaultAction();
    $rule_data = $policy->getRules();

    $errors = array();
    if ($request->isFormPost() && !$starting_replacement) {
      $data = idx($request_data, 'rules');
      $default_action = idx($request_data, 'default');
      if (!is_string($data) || !is_string($default_action) ||
          !isset($action_options[$default_action])) {
        return $this->newUnavailableRuleResponse();
      }
      try {
        $data = phutil_json_decode($data);
      } catch (PhutilJSONParserException $ex) {
        return $this->newUnavailableRuleResponse();
      }
      if (!is_array($data)) {
        return $this->newUnavailableRuleResponse();
      }

      $rule_data = array();
      foreach ($data as $rule) {
        if (!is_array($rule)) {
          return $this->newUnavailableRuleResponse();
        }
        $action = idx($rule, 'action');
        if (!is_string($action)) {
          return $this->newUnavailableRuleResponse();
        }
        switch ($action) {
          case 'allow':
          case 'deny':
            break;
          default:
            return $this->newUnavailableRuleResponse();
        }

        $rule_class = idx($rule, 'rule');
        if (!is_string($rule_class) || empty($rules[$rule_class])) {
          return $this->newUnavailableRuleResponse();
        }

        $rule_obj = $rules[$rule_class];
        if (!self::isRuleValueAvailable($rule_obj, idx($rule, 'value'))) {
          return $this->newUnavailableRuleResponse();
        }

        $value = $rule_obj->getValueForStorage(idx($rule, 'value'));

        $rule_data[] = array(
          'action' => $action,
          'rule' => $rule_class,
          'value' => $value,
        );
      }

      // Filter out nonsense rules, like a "users" rule without any users
      // actually specified.
      $valid_rules = array();
      foreach ($rule_data as $rule) {
        $rule_class = $rule['rule'];
        if ($rules[$rule_class]->ruleHasEffect($rule['value'])) {
          $valid_rules[] = $rule;
        }
      }

      if (!$valid_rules) {
        $errors[] = pht('None of these policy rules have any effect.');
      }

      // NOTE: Policies are immutable once created, and we always create a new
      // policy here. If we didn't, we would need to lock this endpoint down,
      // as users could otherwise just go edit the policies of objects with
      // custom policies.

      if (!$errors) {
        $new_policy = new PhabricatorPolicy();
        $new_policy->setRules($valid_rules);
        $new_policy->setDefaultAction($default_action);
        $new_policy->save();

        $data = array(
          'phid' => $new_policy->getPHID(),
          'info' => array(
            'name' => $new_policy->getName(),
            'full' => $new_policy->getName(),
            'icon' => $new_policy->getIcon(),
          ),
        );

        return id(new AphrontAjaxResponse())->setContent($data);
      }
    }

    // Convert rule values to display format (for example, expanding PHIDs
    // into tokens).
    foreach ($rule_data as $key => $rule) {
      $rule_data[$key]['value'] = $rules[$rule['rule']]->getValueForDisplay(
        $viewer,
        $rule['value']);
    }

    $default_select = AphrontFormSelectControl::renderSelectTag(
      $default_action,
      $action_options,
      array(
        'name' => 'default',
      ));

    if ($errors) {
      $errors = id(new PHUIInfoView())
        ->setErrors($errors);
    }

    $form = id(new PHUIFormLayoutView())
      ->appendChild($errors)
      ->appendChild(
        javelin_tag(
          'input',
          array(
            'type' => 'hidden',
            'name' => 'rules',
            'sigil' => 'rules',
          )))
      ->appendChild(
        id(new PHUIFormInsetView())
          ->setTitle(pht('Rules'))
          ->setRightButton(
            javelin_tag(
              'a',
              array(
                'href' => '#',
                'class' => 'button button-green',
                'sigil' => 'create-rule',
                'mustcapture' => true,
              ),
              pht('New Rule')))
          ->setDescription(pht('These rules are processed in order.'))
          ->setContent(javelin_tag(
            'table',
            array(
              'sigil' => 'rules',
              'class' => 'policy-rules-table',
            ),
            '')))
      ->appendChild(
        id(new AphrontFormMarkupControl())
          ->setLabel(pht('If No Rules Match'))
          ->setValue(pht(
            '%s all other users.',
            $default_select)));

    $form = phutil_tag(
      'div',
      array(
        'id' => $root_id,
      ),
      $form);

    $rule_options = mpull($rules, 'getRuleDescription');
    $type_map = mpull($rules, 'getValueControlType');
    $templates = mpull($rules, 'getValueControlTemplate');

    require_celerity_resource('policy-edit-css');
    Javelin::initBehavior(
      'policy-rule-editor',
      array(
        'rootID' => $root_id,
        'actions' => $action_options,
        'rules' => $rule_options,
        'types' => $type_map,
        'templates' => $templates,
        'data' => $rule_data,
        'defaultRule' => $default_rule,
      ));

    $title = pht('Custom Policy');

    $key = $request->getStr('capability');
    if ($key) {
      $capability = PhabricatorPolicyCapability::getCapabilityByKey($key);
      $title = pht('Custom "%s" Policy', $capability->getCapabilityName());
    }

    $dialog = id(new AphrontDialogView())
      ->setWidth(AphrontDialogView::WIDTH_FULL)
      ->setViewer($viewer)
      ->setTitle($title)
      ->appendChild($form)
      ->addSubmitButton(pht('Save Policy'))
      ->addCancelButton('#');

    if ($key) {
      $dialog->addHiddenInput('capability', $key);
    }

    return id(new AphrontDialogResponse())->setDialog($dialog);
  }

  private static function selectBusinessRules(
    array $rules,
    PhabricatorPolicyInterface $object) {

    $hidden = array_fuse(PhabricatorPolicyEditorRuleSet::getHiddenRuleClasses());
    foreach ($rules as $key => $rule) {
      if (!$rule->canApplyToObject($object) || $rule->shouldHideFromUI() ||
          isset($hidden[get_class($rule)])) {
        unset($rules[$key]);
      }
    }
    return msort($rules, 'getRuleOrder');
  }

  private static function hasUnavailableRules(
    PhabricatorPolicy $policy,
    array $rules) {

    foreach ($policy->getRules() as $rule) {
      if (!is_array($rule) || !is_string(idx($rule, 'rule')) ||
          !isset($rules[$rule['rule']]) ||
          !in_array(idx($rule, 'action'), array('allow', 'deny'), true) ||
          !self::isRuleValueAvailable(
            $rules[$rule['rule']], idx($rule, 'value'))) {
        return true;
      }
    }
    return false;
  }

  private static function isRuleValueAvailable(
    PhabricatorPolicyRule $rule,
    $value) {

    if ($rule->getValueControlType() !==
        PhabricatorPolicyRule::CONTROL_TYPE_SELECT) {
      return true;
    }
    $options = idx($rule->getValueControlTemplate(), 'options', array());
    return (is_string($value) || is_int($value)) &&
      array_key_exists($value, $options);
  }

  private static function newReplacementURI(AphrontRequest $request) {
    $base = '/policy/edit/';
    if ($request->getURIData('objectPHID')) {
      $context = 'object/'.rawurlencode($request->getURIData('objectPHID'));
    } else if ($request->getURIData('objectType')) {
      $context = 'type/'.rawurlencode($request->getURIData('objectType'));
    } else {
      $context = 'template/'.rawurlencode($request->getURIData('templateType'));
    }
    $uri = new PhutilURI($base.$context.'/'.
      AphrontFormPolicyControl::getSelectCustomKey().'/');
    $capability = $request->getStr('capability', '');
    if ($capability !== '') {
      $uri->replaceQueryParam('capability', $capability);
    }
    return $uri;
  }

  private function newHistoricalPolicyResponse(
    AphrontRequest $request,
    PhabricatorPolicy $policy) {

    $dialog = id(new AphrontDialogView())
      ->setViewer($this->getViewer())
      ->setWidth(AphrontDialogView::WIDTH_FULL)
      ->setTitle(pht('Historical Policy (Read Only)'))
      ->appendParagraph(pht(
        'This policy contains rules which are unavailable in this editor. '.
        'The existing rules continue to apply and will not be removed or '.
        'rewritten automatically.'))
      ->appendChild(id(new PhabricatorPolicyRulesView())
        ->setViewer($this->getViewer())->setPolicy($policy))
      ->appendParagraph(pht(
        'To make changes, create a replacement policy and configure all of '.
        'its rules explicitly. After saving the new policy, save the original '.
        'object to apply its new permission settings.'))
      ->setSubmitURI(self::newReplacementURI($request))
      ->addHiddenInput('__start_policy_replacement__', '1')
      ->addHiddenInput('capability', $request->getStr('capability', ''))
      ->addSubmitButton(pht('Create Replacement Policy'))
      ->addCancelButton('#');
    if ($request->isFormPost()) {
      $dialog->setErrors(array(pht(
        'Historical policies can not be edited directly. '.
        'This submission was not saved.')));
    }
    return id(new AphrontDialogResponse())->setDialog($dialog);
  }

  private function newUnavailableRuleResponse() {
    return id(new AphrontDialogResponse())->setDialog(
      id(new AphrontDialogView())
        ->setViewer($this->getViewer())
        ->setTitle(pht('Unavailable Policy Rule'))
        ->appendParagraph(pht(
          'This rule is not available for the current object. This submission '.
          'was not saved. Reopen the custom policy and choose supported rules.'))
        ->addCancelButton('#', pht('Close')));
  }

  private function handleProjectRequest(AphrontRequest $request) {
    $viewer = $this->getViewer();

    $errors = array();
    $e_project = true;

    if ($request->isFormPost()) {
      $project_phids = $request->getArr('projectPHIDs');
      $project_phid = head($project_phids);

      $project = id(new PhabricatorObjectQuery())
        ->setViewer($viewer)
        ->withPHIDs(array($project_phid))
        ->executeOne();

      if ($project) {
        // Save this project as one of the user's most recently used projects,
        // so we'll show it by default in future menus.

        $favorites_key = PhabricatorPolicyFavoritesSetting::SETTINGKEY;
        $favorites = $viewer->getUserSetting($favorites_key);
        if (!is_array($favorites)) {
          $favorites = array();
        }

        // Add this, or move it to the end of the list.
        unset($favorites[$project_phid]);
        $favorites[$project_phid] = true;

        $preferences = PhabricatorUserPreferences::loadUserPreferences($viewer);

        $editor = id(new PhabricatorUserPreferencesEditor())
          ->setActor($viewer)
          ->setContentSourceFromRequest($request)
          ->setContinueOnNoEffect(true)
          ->setContinueOnMissingFields(true);

        $xactions = array();
        $xactions[] = $preferences->newTransaction($favorites_key, $favorites);
        $editor->applyTransactions($preferences, $xactions);

        $data = array(
          'phid' => $project->getPHID(),
          'info' => array(
            'name' => $project->getName(),
            'full' => $project->getName(),
            'icon' => $project->getDisplayIconIcon(),
          ),
        );

        return id(new AphrontAjaxResponse())->setContent($data);
      } else {
        $errors[] = pht('You must choose a project.');
        $e_project = pht('Required');
      }
    }

    $project_datasource = id(new PhabricatorProjectDatasource())
      ->setParameters(
        array(
          'policy' => 1,
        ));

    $form = id(new AphrontFormView())
      ->setViewer($viewer)
      ->appendControl(
        id(new AphrontFormTokenizerControl())
          ->setLabel(pht('Members Of'))
          ->setName('projectPHIDs')
          ->setLimit(1)
          ->setError($e_project)
          ->setDatasource($project_datasource));

    return $this->newDialog()
      ->setWidth(AphrontDialogView::WIDTH_FORM)
      ->setErrors($errors)
      ->setTitle(pht('Select Project'))
      ->appendForm($form)
      ->addSubmitButton(pht('Done'))
      ->addCancelButton('#');
  }

  private function handleNamedPolicyRequest(
    AphrontRequest $request,
    $object_type) {

    $viewer = $this->getViewer();

    $errors = array();
    $e_policy = true;

    if ($request->isFormPost()) {
      $policy_phids = $request->getArr('namedPHIDs');
      $policy_phid = head($policy_phids);

      /** @var PhorgeNamedPolicy */
      $policy = id(new PhabricatorObjectQuery())
        ->setViewer($viewer)
        ->withPHIDs(array($policy_phid))
        ->executeOne();

      if ($policy) {
        // TODO add to favorites

        $data = array(
          'phid' => $policy->getPHID(),
          'info' => array(
            'name' => $policy->getName(),
            'full' => $policy->getName(),
            'icon' => $policy->getIcon(),
          ),
        );

        return id(new AphrontAjaxResponse())->setContent($data);
      } else {
        $errors[] = pht('You must choose a policy.');
        $e_policy = pht('Required');
      }
    }

    $policy_datasource = id(new PhorgeNamedPolicyDatasource())
      ->setParameters(
        array(
          'targetObjectType' => $object_type,
        ));

    $form = id(new AphrontFormView())
      ->setViewer($viewer)
      ->appendControl(
        id(new AphrontFormTokenizerControl())
          ->setName('namedPHIDs')
          ->setLimit(1)
          ->setError($e_policy)
          ->setDatasource($policy_datasource));

    return $this->newDialog()
      ->setWidth(AphrontDialogView::WIDTH_FORM)
      ->setErrors($errors)
      ->setTitle(pht('Select Named Policy'))
      ->appendForm($form)
      ->addSubmitButton(pht('Done'))
      ->addCancelButton('#');
  }

}

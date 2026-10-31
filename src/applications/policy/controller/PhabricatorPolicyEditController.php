<?php

final class PhabricatorPolicyEditController
  extends PhabricatorPolicyController {

  public function handleRequest(AphrontRequest $request) {
    $viewer = $this->getViewer();

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
      $policy = id(new PhabricatorPolicy())
        ->setRules(array($default_rule))
        ->setDefaultAction(PhabricatorPolicy::ACTION_DENY);
    }

    // Never pass an unavailable stored rule to the native JavaScript editor:
    // its missing option/template could change what the person sees or saves.
    // Policies are immutable and their original evaluation remains intact.
    if (self::hasUnavailableRules($policy, $rules)) {
      return $this->newHistoricalPolicyResponse($request, $policy);
    }

    // Continue the same policy-control Workflow, preserving its result handler.
    // This first POST only opens a fresh editor; the next explicit Save Policy
    // creates a policy and the outer object form still has to apply it.
    $starting_replacement = $request->getExists('__start_policy_replacement__');
    if ($starting_replacement &&
        (!$request->isFormPost() ||
         $request->getStr('__start_policy_replacement__') !== '1' ||
         $request->getExists('rules') || $phid)) {
      return $this->newUnavailableRuleResponse();
    }

    $root_id = celerity_generate_unique_node_id();

    $default_action = $policy->getDefaultAction();
    $rule_data = $policy->getRules();

    $errors = array();
    if ($request->isFormPost() && !$starting_replacement) {
      $data = $request->getStr('rules');
      try {
        $data = phutil_json_decode($data);
      } catch (PhutilJSONParserException $ex) {
        throw new Exception(
          pht('Failed to JSON decode rule data!'),
          0,
          $ex);
      }

      $rule_data = array();
      foreach ($data as $rule) {
        $action = idx($rule, 'action');
        switch ($action) {
          case 'allow':
          case 'deny':
            break;
          default:
            throw new Exception(pht("Invalid action '%s'!", $action));
        }

        $rule_class = idx($rule, 'rule');
        if (empty($rules[$rule_class])) {
          return $this->newUnavailableRuleResponse();
        }

        $rule_obj = $rules[$rule_class];

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
        $new_policy->setDefaultAction($request->getStr('default'));
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

  private static function hasRetiredRules(PhabricatorPolicy $policy) {
    foreach ($policy->getRules() as $rule) {
      if (in_array(idx($rule, 'rule'),
          PhabricatorPolicyEditorRuleSet::getHiddenRuleClasses(), true)) {
        return true;
      }
    }
    return false;
  }

  private static function hasUnavailableRules(
    PhabricatorPolicy $policy,
    array $rules) {

    if (self::hasRetiredRules($policy)) {
      return true;
    }
    foreach ($policy->getRules() as $rule) {
      if (!isset($rules[idx($rule, 'rule')])) {
        return true;
      }
    }
    return false;
  }

  private static function newReplacementURI(AphrontRequest $request) {
    $base = '/policy/edit/';
    $project_base = '/organization/project-policy/';
    if (strncmp($request->getPath(), $project_base, strlen($project_base)) === 0) {
      $base = $project_base;
    }
    if ($request->getURIData('objectPHID')) {
      $context = 'object/'.rawurlencode($request->getURIData('objectPHID'));
    } else if ($request->getURIData('objectType')) {
      $context = 'type/'.rawurlencode($request->getURIData('objectType'));
    } else {
      $context = 'template/'.rawurlencode($request->getURIData('templateType'));
    }
    $uri = new PhutilURI($base.$context.'/'.
      AphrontFormPolicyControl::getSelectCustomKey().'/');
    $capability = $request->getStr('capability');
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
      ->setTitle('历史策略（只读）')
      ->appendParagraph('此策略包含已停用或系统内部规则，不能在此直接编辑。'.
        '原有规则及权限继续生效，不会自动删除或改写。')
      ->appendChild(id(new PhabricatorPolicyRulesView())
        ->setViewer($this->getViewer())->setPolicy($policy))
      ->appendParagraph('需要调整时，请新建替代策略并重新配置完整规则；'.
        '保存新策略后，还需确认保存原对象的权限设置才会应用。')
      ->setSubmitURI(self::newReplacementURI($request))
      ->addHiddenInput('__start_policy_replacement__', '1')
      ->addHiddenInput('capability', $request->getStr('capability'))
      ->addSubmitButton('新建替代策略')
      ->addCancelButton('#');
    if ($request->isFormPost()) {
      $dialog->setErrors(array('历史策略不可直接修改；本次提交未保存。'));
    }
    return id(new AphrontDialogResponse())->setDialog($dialog);
  }

  private function newUnavailableRuleResponse() {
    return id(new AphrontDialogResponse())->setDialog(
      id(new AphrontDialogView())
        ->setViewer($this->getViewer())
        ->setTitle('不支持的策略规则')
        ->appendParagraph('该规则当前不可选或不适用于当前对象。'.
          '本次提交未保存，请重新打开自定义策略并选择人员、项目或团队等业务规则。')
        ->addCancelButton('#', '关闭'));
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

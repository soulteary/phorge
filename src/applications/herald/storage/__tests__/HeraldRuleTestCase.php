<?php

final class HeraldRuleTestCase extends PhabricatorTestCase {

  public function testHeraldRuleExecutionOrder() {
    $rules = array(
      1 => HeraldRuleTypeConfig::RULE_TYPE_GLOBAL,
      2 => HeraldRuleTypeConfig::RULE_TYPE_GLOBAL,
      3 => HeraldRuleTypeConfig::RULE_TYPE_OBJECT,
      4 => HeraldRuleTypeConfig::RULE_TYPE_PERSONAL,
      5 => HeraldRuleTypeConfig::RULE_TYPE_GLOBAL,
      6 => HeraldRuleTypeConfig::RULE_TYPE_PERSONAL,
    );

    foreach ($rules as $id => $type) {
      $rules[$id] = id(new HeraldRule())
        ->setID($id)
        ->setRuleType($type);
    }

    shuffle($rules);
    $rules = msort($rules, 'getRuleExecutionOrderSortKey');
    $this->assertEqual(
      array(
        // Personal
        4,
        6,

        // Object
        3,

        // Global
        1,
        2,
        5,
      ),
      array_values(mpull($rules, 'getID')));
  }

  public function testHeraldRuleAttachmentsAcceptValidData() {
    $rule = new HeraldRule();
    $conditions = array(17 => new HeraldCondition());
    $actions = array(29 => new HeraldActionRecord());

    $this->assertEqual($rule, $rule->attachConditions($conditions));
    $this->assertEqual($conditions, $rule->getConditions());
    $this->assertEqual($rule, $rule->attachActions($actions));
    $this->assertEqual($actions, $rule->getActions());

    $rule->attachConditions(array());
    $rule->attachActions(array());
    $this->assertEqual(array(), $rule->getConditions());
    $this->assertEqual(array(), $rule->getActions());
  }

  public function testHeraldRuleAttachmentsRejectInvalidElements() {
    $conditions = array(new HeraldCondition());
    $actions = array(new HeraldActionRecord());
    $rule = id(new HeraldRule())
      ->attachConditions($conditions)
      ->attachActions($actions);

    $this->assertException(
      InvalidArgumentException::class,
      function() use ($rule) {
        $rule->attachConditions(
          array(new HeraldCondition(), new stdClass()));
      });
    $this->assertEqual($conditions, $rule->getConditions());

    $this->assertException(
      InvalidArgumentException::class,
      function() use ($rule) {
        $rule->attachActions(
          array(new HeraldActionRecord(), new stdClass()));
      });
    $this->assertEqual($actions, $rule->getActions());
  }

  public function testHeraldRuleGettersRequireAttachedData() {
    $rule = new HeraldRule();

    $this->assertException(
      PhabricatorDataNotAttachedException::class,
      array($rule, 'getConditions'));
    $this->assertException(
      PhabricatorDataNotAttachedException::class,
      array($rule, 'getActions'));
  }

}

<?php

final class TeamProjectHistoryStateTestCase extends PhabricatorTestCase {

  public function testHistoryIsDefaultOffAndInvalidStatesFailClosed() {
    $this->assertEqual('off', TeamProjectProgress::getHistoryState());
    $this->assertFalse(TeamProjectProgress::isHistoryVisible());
    $env = PhabricatorEnv::beginScopedEnv();
    foreach (array('off', 'active', 'read-only', 'unknown', null) as $state) {
      $env->overrideEnvConfig('project.history-state', $state);
      $this->assertEqual(in_array($state, array('active', 'read-only'), true),
        TeamProjectProgress::isHistoryVisible());
      $this->assertFalse(TeamProjectProgress::canPublish(
        new PhabricatorUser(), new PhabricatorProject()));
    }
    unset($env);
  }

  public function testEnglishAndChineseLabelsKeepStoredValues() {
    $english = PhabricatorEnv::beginScopedLocale('en_US');
    $this->assertEqual('Node', TeamProjectProgress::getKindNames()['node']);
    $this->assertEqual('Work Comment', TeamProjectProgress::getKindNames()['comment']);
    $this->assertEqual('Only project editors can publish records.',
      pht('Only project editors can publish records.'));
    $keys = array_keys(TeamProjectProgress::getStatusNames());
    unset($english);
    $chinese = PhabricatorEnv::beginScopedLocale('zh_CN');
    $this->assertEqual('节点', TeamProjectProgress::getKindNames()['node']);
    $this->assertEqual('工作评论', TeamProjectProgress::getKindNames()['comment']);
    $this->assertEqual('只有具备项目编辑权限的用户可以发布记录。',
      pht('Only project editors can publish records.'));
    $this->assertEqual($keys, array_keys(TeamProjectProgress::getStatusNames()));
    $this->assertEqual('node', TeamProjectProgress::normalizeKind('milestone'));
    unset($chinese);
  }
}

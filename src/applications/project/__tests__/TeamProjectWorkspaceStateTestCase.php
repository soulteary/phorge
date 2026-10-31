<?php

final class TeamProjectWorkspaceStateTestCase extends PhabricatorTestCase {

  public function testWorkspaceAndDraftDefaultsPreserveNativeBehavior() {
    $this->assertFalse(TeamProjectProgress::isWorkspaceEnabled());
    $this->assertFalse(TeamProjectProgress::areTaskDraftsEnabled());
    $configured = PhabricatorEnv::getEnvConfig('maniphest.statuses');
    $env = PhabricatorEnv::beginScopedEnv();
    foreach (array('native', 'unified', 'unknown', false) as $mode) {
      $env->overrideEnvConfig('project.workspace-mode', $mode);
      $this->assertEqual($mode === 'unified', TeamProjectProgress::isWorkspaceEnabled());
      $this->assertEqual($configured, PhabricatorEnv::getEnvConfig('maniphest.statuses'));
    }
    $env->overrideEnvConfig('project.history-task-drafts', true);
    foreach (array('off', 'active', 'read-only', 'unknown') as $state) {
      $env->overrideEnvConfig('project.history-state', $state);
      $this->assertEqual($state === 'active', TeamProjectProgress::areTaskDraftsEnabled());
    }
    unset($env);
  }

  public function testDraftMessagesRenderInEnglishAndChinese() {
    $english = PhabricatorEnv::beginScopedLocale('en_US');
    $this->assertEqual('Unable to Create Task from Project Record',
      pht('Unable to Create Task from Project Record'));
    $this->assertEqual('Sort: Status', pht('Sort: %s', 'Status'));
    unset($english);
    $chinese = PhabricatorEnv::beginScopedLocale('zh_CN');
    $this->assertEqual('无法从项目记录创建卡片',
      pht('Unable to Create Task from Project Record'));
    $this->assertEqual('排序：状态', pht('Sort: %s', '状态'));
    unset($chinese);
  }
}

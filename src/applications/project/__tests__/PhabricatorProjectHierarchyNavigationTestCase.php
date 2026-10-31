<?php

final class PhabricatorProjectHierarchyNavigationTestCase
  extends PhabricatorTestCase {

  public function testFlatDefaultPreservesNativeQuerySelection() {
    $this->assertEqual('flat',
      PhabricatorEnv::getEnvConfig('projects.default-navigation'));
    $this->assertEqual(null, $this->selectQuery('flat', null));
    $this->assertEqual('joined', $this->selectQuery('flat', 'joined'));
    $this->assertEqual('hierarchy', $this->selectQuery('flat', 'hierarchy'));
  }

  public function testHierarchyDefaultOnlySelectsUnfilteredGet() {
    $this->assertEqual('hierarchy', $this->selectQuery('hierarchy', null));
    foreach (array(array('after' => 'cursor'), array('before' => 'cursor'),
      array('nux' => '1', 'overheated' => '1'), array('__ajax__' => '1'))
      as $data) {
      $this->assertEqual('hierarchy',
        $this->selectQuery('hierarchy', null, $data));
    }
    $this->assertEqual(null,
      $this->selectQuery('hierarchy', null, array(), 'POST'));
  }

  public function testExplicitSearchesAreNeverReplaced() {
    foreach (array('all', 'active', 'joined', 'watching', 'custom-query') as $key) {
      $this->assertEqual($key, $this->selectQuery('hierarchy', $key));
    }
    foreach (array(array('query' => 'secret'), array('status' => 'archived'),
      array('name' => 'example'), array('order' => 'name')) as $data) {
      $this->assertEqual(null,
        $this->selectQuery('hierarchy', null, $data));
    }
  }

  public function testHierarchyQueryUsesRootsAndRetainsArchivedParents() {
    $engine = id(new PhabricatorProjectSearchEngine())
      ->setViewer(PhabricatorUser::getOmnipotentUser());
    $query = $engine->buildSavedQueryFromBuiltin('hierarchy');
    $this->assertTrue($query->getParameter('isRoot'));
    $this->assertEqual('all', $query->getParameter('status'));
    $this->assertEqual('name', $query->getParameter('order'));
    $this->assertFalse($query->getParameter('isMilestone'));
    $all = $engine->buildSavedQueryFromBuiltin('all');
    $this->assertEqual(null, $all->getParameter('isRoot'));
  }

  public function testNavigationMessagesHaveEnglishAndChineseForms() {
    foreach (array('en_US', 'zh_CN') as $code) {
      $locale = PhabricatorEnv::beginScopedLocale($code);
      try {
        foreach (array(
          'Project Hierarchy' => '项目层级',
          'Back to Parent Project' => '返回上一级项目',
          'Back to Project Directory' => '返回项目目录',
          'Restricted Parent Project' => '受限父项目',
          'No visible subprojects.' => '没有可查看的子项目。',
        ) as $english => $chinese) {
          $this->assertEqual($code === 'zh_CN' ? $chinese : $english,
            pht($english));
        }
      } finally {
        unset($locale);
      }
    }
  }

  private function selectQuery(
    $mode,
    $key,
    array $data = array(),
    $method = 'GET') {

    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('projects.default-navigation', $mode);
    $old = idx($_SERVER, 'REQUEST_METHOD');
    $_SERVER['REQUEST_METHOD'] = $method;
    try {
      $request = id(new AphrontRequest('phabricator.example.com', '/project/'))
        ->setURIMap(array('queryKey' => $key))
        ->setRequestData($data);
      return PhabricatorProjectNavigation::getQueryKey($request);
    } finally {
      if ($old === null) {
        unset($_SERVER['REQUEST_METHOD']);
      } else {
        $_SERVER['REQUEST_METHOD'] = $old;
      }
      unset($env);
    }
  }

}

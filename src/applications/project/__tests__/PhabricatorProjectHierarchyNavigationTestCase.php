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

  public function testFlatAnonymousListFallsBackToNativeActiveQuery() {
    list($engine, $selected) = $this->newListQueryContext('flat', new PhabricatorUser());
    $this->assertEqual('active', $engine->getDefaultQueryKey());
    $this->assertEqual('active', $selected->getQueryKey());
    $this->assertEqual(PhabricatorProjectStatus::STATUS_ACTIVE_KEY,
      $selected->getParameter('status'));
    $this->assertEqual(null, $selected->getParameter('isRoot'));
    $this->assertEqual(array('active', 'all', 'hierarchy'),
      array_keys($engine->loadEnabledNamedQueries()));
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

  private function newListQueryContext($mode, PhabricatorUser $viewer) {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('projects.default-navigation', $mode);
    $old_method = idx($_SERVER, 'REQUEST_METHOD');
    $_SERVER['REQUEST_METHOD'] = 'GET';
    try {
      $request = id(new AphrontRequest('phabricator.example.com', '/project/'))
        ->setUser($viewer)->setURIMap(array('queryKey' => null))
        ->setRequestData(array());
      $controller = id(new PhabricatorProjectListController())->setRequest($request);
      $engine = id(new PhabricatorProjectSearchEngine())
        ->setController($controller)->setViewer($controller->getViewer());
      // Follow both stages used by the project list and delegated search:
      // navigation may explicitly select hierarchy, while a null key falls
      // through to the engine's real saved/default-query resolution.
      $key = PhabricatorProjectNavigation::getQueryKey($controller->getRequest());
      if ($key === null) {
        $key = $engine->getDefaultQueryKey();
      }
      return array($engine, $engine->buildSavedQueryFromBuiltin($key));
    } finally {
      if ($old_method === null) {
        unset($_SERVER['REQUEST_METHOD']);
      } else {
        $_SERVER['REQUEST_METHOD'] = $old_method;
      }
      unset($env);
    }
  }

}

<?php

final class PhabricatorLocaleScopeGuardTestCase
  extends PhabricatorTestCase {

  public function testLocaleScopeGuard() {
    $original = PhabricatorEnv::getLocaleCode();

    // Set a guard; it should change the locale, then revert it when destroyed.
    $guard = PhabricatorEnv::beginScopedLocale('en_GB');
    $this->assertEqual('en_GB', PhabricatorEnv::getLocaleCode());
    unset($guard);
    $this->assertEqual($original, PhabricatorEnv::getLocaleCode());

    // Nest guards, then destroy them out of order.
    $guard1 = PhabricatorEnv::beginScopedLocale('en_GB');
    $this->assertEqual('en_GB', PhabricatorEnv::getLocaleCode());
    $guard2 = PhabricatorEnv::beginScopedLocale('en_A*');
    $this->assertEqual('en_A*', PhabricatorEnv::getLocaleCode());
    unset($guard1);
    $this->assertEqual('en_A*', PhabricatorEnv::getLocaleCode());
    unset($guard2);
    $this->assertEqual($original, PhabricatorEnv::getLocaleCode());

    // If you push `null`, that should mean "the default locale", not
    // "the current locale".
    $guard3 = PhabricatorEnv::beginScopedLocale('en_GB');
    $this->assertEqual('en_GB', PhabricatorEnv::getLocaleCode());
    $guard4 = PhabricatorEnv::beginScopedLocale(null);
    $this->assertEqual($original, PhabricatorEnv::getLocaleCode());
    unset($guard4);
    $this->assertEqual('en_GB', PhabricatorEnv::getLocaleCode());
    unset($guard3);
    $this->assertEqual($original, PhabricatorEnv::getLocaleCode());

  }

  public function testSelectiveUpstreamChineseMessages() {
    $guard = PhabricatorEnv::beginScopedLocale('zh_CN');
    try {
      $this->assertEqual('每页结果数量已限制', pht('Page Size Limited'));
      $this->assertEqual('找不到某些设置？', pht('Missing Some Settings?'));
      $this->assertEqual(
        '你请求每页显示 5,000 条结果，但每页最多只能显示 1,000 条。',
        pht(
          'You requested %s results per page, but the maximum page size is %s.',
          new PhutilNumber(5000),
          new PhutilNumber(1000)));
      $this->assertEqual(
        "迭代器 'unknown' 不存在。可用的迭代器：ManiphestTask。",
        pht(
          "Iterator '%s' does not exist. Valid iterators are: %s.",
          'unknown',
          'ManiphestTask'));
    } finally {
      unset($guard);
    }
  }

  public function testChineseModuleTranslationsAndOverridesFollowLocaleScope() {
    $original = PhabricatorEnv::getLocaleCode();
    $english = PhabricatorEnv::beginScopedLocale('en_US');
    $env = PhabricatorEnv::beginScopedEnv();
    PhorgeAtomicChineseFixtureTranslation::setEnabled(true);
    try {
      $chinese = PhabricatorEnv::beginScopedLocale('zh-CN');
      try {
        $this->assertEqual('zh_CN', PhabricatorEnv::getLocaleCode());
        $this->assertEqual('模块消息 example。', pht('Atomic module message %s.', 'example'));
        $this->assertEqual('模块有 2 项。',
          pht('Atomic module has %s item(s).', new PhutilNumber(2)));
        // The package fixture deliberately collides with an existing message.
        $this->assertEqual('每页结果数量已限制', pht('Page Size Limited'));
      } finally {
        unset($chinese);
      }
      $this->assertEqual('en_US', PhabricatorEnv::getLocaleCode());
      $this->assertEqual('Atomic module message example.',
        pht('Atomic module message %s.', 'example'));
      $env->overrideEnvConfig('translation.override', array(
        'Atomic module message %s.' => '站点覆盖 %s。',
      ));
      $chinese = PhabricatorEnv::beginScopedLocale('zh_CN');
      try {
        $this->assertEqual('站点覆盖 example。',
          pht('Atomic module message %s.', 'example'));
      } finally {
        unset($chinese);
      }
    } finally {
      PhorgeAtomicChineseFixtureTranslation::setEnabled(false);
      unset($env);
      unset($english);
    }
    $this->assertEqual($original, PhabricatorEnv::getLocaleCode());
  }

}

/** A dormant module source: it contributes no messages outside this test. */
final class PhorgeAtomicChineseFixtureTranslation extends PhutilTranslation {
  private static $enabled = false;

  public static function setEnabled($enabled) {
    self::$enabled = $enabled;
  }

  public function getLocaleCode() {
    return 'zh_CN';
  }

  protected function getTranslations() {
    if (!self::$enabled) { return array(); }
    return array(
      'Atomic module message %s.' => '模块消息 %s。',
      'Atomic module has %s item(s).' => array('模块有 %s 项。', '模块有 %s 项。'),
      'Page Size Limited' => 'This fixture must not replace the maintained dictionary.',
    );
  }
}

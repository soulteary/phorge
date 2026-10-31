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

}

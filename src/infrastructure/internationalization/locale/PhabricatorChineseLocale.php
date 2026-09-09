<?php

final class PhabricatorChineseLocale
  extends PhutilLocale {

  public function getLocaleCode() {
    return 'zh_CN';
  }

  public function getLocaleName() {
    return '中文（简体）';
  }

  public function getFallbackLocaleCode() {
    return 'en_US';
  }

  public function isSillyLocale() {
    return false;
  }

  public function isTestLocale() {
    return false;
  }

}

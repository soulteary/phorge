<?php

/**
 * Runtime behavior owned by a Phorge product profile.
 */
final class PhorgeProductProfile extends Phobject {

  const PROFILE_FULL = 'full';
  const PROFILE_COLLABORATION = 'collaboration';

  public static function getActiveProfile() {
    return PhabricatorEnv::getEnvConfig('phorge.product-profile');
  }

  public static function isCollaboration() {
    return (self::getActiveProfile() === self::PROFILE_COLLABORATION);
  }

  public static function getManagedApplicationClasses() {
    return array(
      'PhabricatorDiffusionApplication',
    );
  }

  public static function disablesApplication($class) {
    if (!self::isCollaboration()) {
      return false;
    }

    return in_array($class, self::getManagedApplicationClasses(), true);
  }

}

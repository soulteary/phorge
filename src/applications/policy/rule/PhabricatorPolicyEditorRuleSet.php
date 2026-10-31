<?php

/** Product-level editor choices never change evaluation of existing policies. */
final class PhabricatorPolicyEditorRuleSet extends Phobject {

  public static function getHiddenRuleClasses() {
    $classes = PhabricatorEnv::getEnvConfig('policy.editor.hidden-rules');
    if ($classes === null) {
      return PhorgeProductProfile::isCollaboration()
        ? array('PhabricatorLunarPhasePolicyRule',
                'PhabricatorLegalpadSignaturePolicyRule')
        : array();
    }
    PhutilTypeSpec::newFromString('list<string>')->check($classes);
    foreach ($classes as $class) {
      if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $class)) {
        throw new PhutilArgumentUsageException(
          pht('Invalid policy rule class name.'));
      }
    }
    return array_values(array_unique($classes));
  }
}

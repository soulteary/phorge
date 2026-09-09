<?php

final class ManiphestConfiguredCustomField
  extends ManiphestCustomField
  implements PhabricatorStandardCustomFieldInterface {

  public function getStandardCustomFieldNamespace() {
    return 'maniphest';
  }

  public function createFields($object) {
    $config = PhabricatorEnv::getEnvConfig(
      'maniphest.custom-field-definitions');

    // The collaboration profile provides these as built-in fields. Ignore
    // legacy generated definitions with the same keys to avoid a duplicate
    // field collision while preserving every unrelated administrator field.
    if (PhorgeProductProfile::isCollaboration()) {
      $definitions = ManiphestGiteaCustomField::getFieldDefinitions();
      foreach ($definitions as $key => $spec) {
        unset($config[$key]);
      }
    }

    $fields = PhabricatorStandardCustomField::buildStandardFields(
      $this,
      $config);

    return $fields;
  }

}

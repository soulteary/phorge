<?php

/**
 * Stable Gitea link fields supplied by the collaboration product profile.
 *
 * These use the same standard field keys as the former generated
 * maniphest.custom-field-definitions entries, so existing values remain
 * readable without a data migration.
 */
final class ManiphestGiteaCustomField
  extends ManiphestCustomField
  implements PhabricatorStandardCustomFieldInterface {

  public static function getFieldDefinitions() {
    return array(
      'gitea.repository' => array(
        'name' => pht('Gitea Repository'),
        'type' => 'link',
        'caption' => pht('Canonical repository in Gitea.'),
      ),
      'gitea.issue' => array(
        'name' => pht('Gitea Issue'),
        'type' => 'link',
        'caption' => pht('Related issue in Gitea.'),
      ),
      'gitea.pull-request' => array(
        'name' => pht('Gitea Pull Request'),
        'type' => 'link',
        'caption' => pht('Related pull request in Gitea.'),
      ),
      'gitea.commit' => array(
        'name' => pht('Gitea Commit'),
        'type' => 'link',
        'caption' => pht('Related commit in Gitea.'),
      ),
    );
  }

  public function getStandardCustomFieldNamespace() {
    return 'maniphest';
  }

  public function createFields($object) {
    if (!PhorgeProductProfile::isCollaboration()) {
      return array();
    }

    // Code supplies defaults only. Keep an administrator definition with the
    // same raw key authoritative, as it may intentionally use another field
    // type, caption, or validation contract for existing stored values.
    $definitions = self::getFieldDefinitions();
    $configured = PhabricatorEnv::getEnvConfig(
      'maniphest.custom-field-definitions');
    foreach ($configured as $key => $spec) {
      unset($definitions[$key]);
    }

    return PhabricatorStandardCustomField::buildStandardFields(
      $this,
      $definitions,
      // These fields are supplied by code, but keep the non-builtin standard
      // field API form used by the former configured definitions. In
      // particular, Conduit, search and export must continue exposing
      // "custom.gitea.*" keys to existing clients and saved queries.
      $builtin = false);
  }

}

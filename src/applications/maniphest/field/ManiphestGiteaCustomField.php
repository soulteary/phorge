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

    return PhabricatorStandardCustomField::buildStandardFields(
      $this,
      self::getFieldDefinitions(),
      $builtin = true);
  }

}

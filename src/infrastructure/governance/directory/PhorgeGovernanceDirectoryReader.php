<?php

/**
 * N02.DIRECTORY-VIEW.v1 isolated reader. Not a production policy/provider.
 *
 * All authorization is evaluated against current fixture rows in SQL. No
 * caller-supplied "is administrator" flag or self-membership exception exists.
 */
final class PhorgeGovernanceDirectoryReader {

  private $store;

  public function __construct(PhorgeGovernanceIsolatedDirectoryStore $store) {
    $this->store = $store;
  }

  public static function validateOptions($viewer, $after_id, $limit, $query) {
    if (!is_string($viewer) ||
        !preg_match('/\APHID-USER-[a-zA-Z0-9]{20}\z/', $viewer)) {
      throw new InvalidArgumentException('Expected a synthetic user PHID.');
    }
    if (!is_int($after_id) || $after_id < 0 ||
        !is_int($limit) || $limit < 1 || $limit > 100) {
      throw new InvalidArgumentException('Invalid bounded directory page.');
    }
    if (!is_string($query) || strlen($query) > 200 ||
        preg_match('//u', $query) !== 1) {
      throw new InvalidArgumentException('Invalid directory name query.');
    }
  }

  public function listTeams($viewer, $after_id = 0, $limit = 50, $query = '') {
    self::validateOptions($viewer, $after_id, $limit, $query);
    return $this->store->readTeams($viewer, $after_id, $limit, $query);
  }

  public function listDepartments($viewer, $after_id = 0, $limit = 50) {
    self::validateOptions($viewer, $after_id, $limit, '');
    return $this->store->readDepartments($viewer, $after_id, $limit);
  }

  public function listMembers($viewer, $team_phid, $after_id = 0, $limit = 50) {
    self::validateOptions($viewer, $after_id, $limit, '');
    self::validateTeamPHID($team_phid);
    return $this->store->readMembers($viewer, $team_phid, $after_id, $limit);
  }

  public function listUserTeams($viewer, $subject, $after_id = 0, $limit = 50) {
    self::validateOptions($viewer, $after_id, $limit, '');
    self::validateOptions($subject, 0, 1, '');
    return $this->store->readUserTeams($viewer, $subject, $after_id, $limit);
  }

  private static function validateTeamPHID($phid) {
    if (!is_string($phid) ||
        !preg_match('/\APHID-GOVT-[a-zA-Z0-9]{20}\z/', $phid)) {
      throw new InvalidArgumentException('Expected a fixture team PHID.');
    }
  }

}

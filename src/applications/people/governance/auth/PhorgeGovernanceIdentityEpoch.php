<?php

/** Native legacy-role generation; independent of optional governance activation. */
final class PhorgeGovernanceIdentityEpoch extends PhabricatorUserDAO {
  protected $userPHID;
  protected $epoch;
  public function getIDKey() { return 'userPHID'; }
  public function getTableName() { return 'organization_identityepoch'; }
  protected function getConfiguration() {
    return array(self::CONFIG_TIMESTAMPS=>false,
      self::CONFIG_IDS=>self::IDS_MANUAL,
      self::CONFIG_COLUMN_SCHEMA=>array('userPHID'=>'varbytes64','epoch'=>'uint64'),
      self::CONFIG_KEY_SCHEMA=>array('PRIMARY'=>array('columns'=>array('userPHID'),'unique'=>true))) + parent::getConfiguration();
  }
  public static function assertLegacyRoleTransaction(PhabricatorUser $user) {
    $conn = $user->establishConnection('w');
    if (get_class($conn) !== AphrontMySQLiDatabaseConnection::class ||
        !$conn->isInsideTransaction() || $conn !== (new self())->establishConnection('w')) {
      throw new RuntimeException('governance_role_generation_transaction_required');
    }
    foreach (array($user, new self()) as $table) {
      $column = $table instanceof self ? 'userPHID' : 'id';
      queryfx_one($conn, 'SELECT %C FROM %R WHERE 1=0 LOCK IN SHARE MODE', $column, $table);
      $engine = queryfx_one($conn,
        'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
        $table->getTableName());
      if (!$engine || $engine['ENGINE'] !== 'InnoDB') {
        throw new RuntimeException('governance_role_generation_transactional_source_required');
      }
    }
  }
  public static function advanceLegacyRole(PhabricatorUser $user) {
    self::assertLegacyRoleTransaction($user);
    $conn = $user->establishConnection('w');
    queryfx($conn, 'INSERT INTO %R (userPHID,epoch) VALUES (%s,2) ON DUPLICATE KEY UPDATE epoch=epoch+1',
      new self(), $user->getPHID());
  }
}

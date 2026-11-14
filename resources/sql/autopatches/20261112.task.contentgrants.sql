-- Independent Task grant metadata only, no Task field or role updates, flags remain OFF.
INSERT INTO {$NAMESPACE}_config.governance_configepoch (namespace,configKey,epoch)
VALUES ('default','governance.task-content-grants.enabled',1);
CREATE TABLE {$NAMESPACE}_maniphest.governance_taskcontentgrant (
  grantID VARBINARY(64) NOT NULL PRIMARY KEY,
  targetPHID VARBINARY(64) NOT NULL,
  operation VARBINARY(32) NOT NULL,
  subjectKind VARBINARY(16) NOT NULL,
  subjectID VARBINARY(64) NOT NULL,
  validFrom BIGINT UNSIGNED NOT NULL,
  validUntil BIGINT UNSIGNED NOT NULL,
  revokedAt BIGINT UNSIGNED NULL,
  version BIGINT UNSIGNED NOT NULL,
  KEY key_scope (targetPHID,operation,subjectKind,subjectID)
) ENGINE=InnoDB;
CREATE TABLE {$NAMESPACE}_maniphest.governance_taskcontentapproval (
  approvalID VARBINARY(64) NOT NULL PRIMARY KEY,
  targetPHID VARBINARY(64) NOT NULL,
  bindingJSON MEDIUMTEXT NOT NULL,
  bindingDigest VARBINARY(64) NOT NULL,
  state VARBINARY(16) NOT NULL,
  approverBindingJSON MEDIUMTEXT NULL,
  appliedAt BIGINT UNSIGNED NULL,
  version BIGINT UNSIGNED NOT NULL,
  KEY key_target (targetPHID)
) ENGINE=InnoDB;
CREATE TABLE {$NAMESPACE}_maniphest.governance_taskcontentaudit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  approvalID VARBINARY(64) NOT NULL,
  grantID VARBINARY(64) NOT NULL,
  targetPHID VARBINARY(64) NOT NULL,
  actorPHID VARBINARY(64) NOT NULL,
  action VARBINARY(32) NOT NULL,
  createdAt BIGINT UNSIGNED NOT NULL,
  KEY key_approval (approvalID),
  KEY key_grant (grantID)
) ENGINE=InnoDB;

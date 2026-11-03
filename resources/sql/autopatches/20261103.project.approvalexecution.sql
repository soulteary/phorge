-- Restricted first-family execution storage. Schema does not enable the feature.
CREATE TABLE {$NAMESPACE}_project.governance_executionguard (
  id INT UNSIGNED NOT NULL PRIMARY KEY,
  version BIGINT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB;
INSERT INTO {$NAMESPACE}_project.governance_executionguard (id,version) VALUES (1,1);
CREATE TABLE {$NAMESPACE}_project.governance_projectapproval (
  approvalID VARBINARY(64) NOT NULL PRIMARY KEY,
  operationID VARBINARY(64) NOT NULL,
  targetPHID VARBINARY(64) NOT NULL,
  bindingJSON MEDIUMTEXT NOT NULL,
  bindingDigest VARBINARY(64) NOT NULL,
  reason VARBINARY(2048) NOT NULL,
  state VARBINARY(16) NOT NULL,
  approverBindingJSON MEDIUMTEXT NULL,
  approvedAt BIGINT UNSIGNED NULL,
  consumedAt BIGINT UNSIGNED NULL,
  nativeGroupID VARBINARY(64) NULL,
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  KEY key_target (targetPHID),
  UNIQUE KEY key_operation (operationID)
) ENGINE=InnoDB;
CREATE TABLE {$NAMESPACE}_project.governance_executionaudit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  approvalID VARBINARY(64) NOT NULL,
  operationID VARBINARY(64) NOT NULL,
  targetPHID VARBINARY(64) NOT NULL,
  actorPHID VARBINARY(64) NOT NULL,
  action VARBINARY(32) NOT NULL,
  dataJSON MEDIUMTEXT NOT NULL,
  createdAt BIGINT UNSIGNED NOT NULL,
  KEY key_approval (approvalID),
  KEY key_operation (operationID)
) ENGINE=InnoDB;

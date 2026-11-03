-- Isolated recovery review storage. A reviewed request is not an executable permit.
CREATE TABLE {$NAMESPACE}_user.recovery_restore_request (
  requestID VARBINARY(64) NOT NULL PRIMARY KEY,
  operationID VARBINARY(128) NOT NULL,
  subjectPHID VARBINARY(64) NOT NULL,
  nativeTransactionID BIGINT UNSIGNED NOT NULL,
  requesterPHID VARBINARY(64) NOT NULL,
  requesterBindingJSON LONGTEXT NOT NULL,
  approverPHID VARBINARY(64) NULL,
  approverBindingJSON LONGTEXT NULL,
  reviewState VARBINARY(32) NOT NULL,
  permitState VARBINARY(32) NOT NULL,
  requestDigest VARBINARY(64) NOT NULL,
  bindingDigest VARBINARY(64) NOT NULL,
  bindingJSON LONGTEXT NOT NULL,
  createdAt BIGINT UNSIGNED NOT NULL,
  expiresAt BIGINT UNSIGNED NOT NULL,
  reviewedAt BIGINT UNSIGNED NULL,
  version BIGINT UNSIGNED NOT NULL,
  UNIQUE KEY key_operation (operationID),
  KEY key_subject (subjectPHID, nativeTransactionID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE {$NAMESPACE}_user.recovery_restore_operation (
  operationID VARBINARY(128) NOT NULL PRIMARY KEY,
  requestID VARBINARY(64) NOT NULL,
  action VARBINARY(32) NOT NULL,
  actorPHID VARBINARY(64) NOT NULL,
  requestDigest VARBINARY(64) NOT NULL,
  resultJSON LONGTEXT NOT NULL,
  createdAt BIGINT UNSIGNED NOT NULL,
  KEY key_request (requestID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE {$NAMESPACE}_user.recovery_restore_audit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  requestID VARBINARY(64) NOT NULL,
  operationID VARBINARY(128) NOT NULL,
  actorPHID VARBINARY(64) NOT NULL,
  action VARBINARY(32) NOT NULL,
  bindingDigest VARBINARY(64) NOT NULL,
  createdAt BIGINT UNSIGNED NOT NULL,
  KEY key_request (requestID, id),
  UNIQUE KEY key_operation (operationID)
) ENGINE=InnoDB;

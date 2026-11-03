-- Independent classification of an already committed native stop. Default OFF.
-- A classification is not a recovery permit; no native account row is changed.
CREATE TABLE {$NAMESPACE}_user.recovery_stop (
  nativeTransactionID BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  subjectPHID VARBINARY(64) NOT NULL,
  actorPHID VARBINARY(64) NOT NULL,
  category VARBINARY(32) NOT NULL,
  reason TEXT NOT NULL,
  operationID VARBINARY(128) NOT NULL,
  eventDigest VARBINARY(64) NOT NULL,
  requestDigest VARBINARY(64) NOT NULL,
  createdAt BIGINT UNSIGNED NOT NULL,
  UNIQUE KEY key_operation (operationID),
  KEY key_subject (subjectPHID, nativeTransactionID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE {$NAMESPACE}_user.recovery_stop_audit (
  nativeTransactionID BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  operationID VARBINARY(128) NOT NULL,
  actorPHID VARBINARY(64) NOT NULL,
  requestDigest VARBINARY(64) NOT NULL,
  eventDigest VARBINARY(64) NOT NULL,
  createdAt BIGINT UNSIGNED NOT NULL,
  UNIQUE KEY key_operation (operationID)
) ENGINE=InnoDB;

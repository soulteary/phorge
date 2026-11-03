-- Candidate versioned transfer storage. No feature activation or native permission migration.

CREATE TABLE {$NAMESPACE}_user.organization_transfer (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  transferID VARBINARY(64) NOT NULL,
  operationID VARBINARY(128) NOT NULL,
  subjectPHID VARBINARY(64) NOT NULL,
  responsiblePHID VARBINARY(64) NOT NULL,
  requesterPHID VARBINARY(64) NOT NULL,
  executorPHID VARBINARY(64) NOT NULL,
  action VARBINARY(16) NOT NULL,
  state VARBINARY(16) NOT NULL,
  requestDigest VARBINARY(64) NOT NULL,
  bindingJSON LONGTEXT NOT NULL,
  approverPHID VARBINARY(64) DEFAULT NULL,
  approverBindingJSON LONGTEXT DEFAULT NULL,
  approvedAt BIGINT UNSIGNED DEFAULT NULL,
  expiresAt BIGINT UNSIGNED NOT NULL,
  consumedAt BIGINT UNSIGNED DEFAULT NULL,
  version BIGINT UNSIGNED NOT NULL,
  createdAt BIGINT UNSIGNED NOT NULL,
  UNIQUE KEY key_identity (transferID),
  KEY key_subject (subjectPHID, state),
  KEY key_operation (operationID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE {$NAMESPACE}_user.organization_handover (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  handoverID VARBINARY(64) NOT NULL,
  transferID VARBINARY(64) NOT NULL,
  subjectPHID VARBINARY(64) NOT NULL,
  fromDepartmentID VARBINARY(64) NOT NULL,
  toDepartmentID VARBINARY(64) NOT NULL,
  responsiblePHID VARBINARY(64) NOT NULL,
  state VARBINARY(16) NOT NULL,
  startAt BIGINT UNSIGNED NOT NULL,
  deadlineAt BIGINT UNSIGNED NOT NULL,
  timezone VARBINARY(64) NOT NULL,
  startLocal VARBINARY(32) NOT NULL,
  deadlineLocal VARBINARY(32) NOT NULL,
  startOffset INT NOT NULL,
  deadlineOffset INT NOT NULL,
  cumulativeDays INT UNSIGNED NOT NULL,
  version BIGINT UNSIGNED NOT NULL,
  UNIQUE KEY key_identity (handoverID),
  UNIQUE KEY key_transfer (transferID),
  KEY key_due (state, deadlineAt, id),
  KEY key_subject (subjectPHID, state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE {$NAMESPACE}_user.organization_projectdisposition (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  handoverID VARBINARY(64) NOT NULL,
  projectPHID VARBINARY(64) NOT NULL,
  decision VARBINARY(16) NOT NULL,
  manualStatus VARBINARY(16) NOT NULL,
  manualActorPHID VARBINARY(64) DEFAULT NULL,
  manualAt BIGINT UNSIGNED DEFAULT NULL,
  observedDigest VARBINARY(64) DEFAULT NULL,
  note LONGTEXT NOT NULL,
  version BIGINT UNSIGNED NOT NULL,
  UNIQUE KEY key_identity (handoverID, projectPHID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE {$NAMESPACE}_user.organization_outbox (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  eventID VARBINARY(128) NOT NULL,
  eventType VARBINARY(32) NOT NULL,
  transferID VARBINARY(64) DEFAULT NULL,
  handoverID VARBINARY(64) DEFAULT NULL,
  sourceVersion BIGINT UNSIGNED NOT NULL,
  state VARBINARY(16) NOT NULL,
  attempts INT UNSIGNED NOT NULL,
  createdAt BIGINT UNSIGNED NOT NULL,
  dispatchedAt BIGINT UNSIGNED DEFAULT NULL,
  UNIQUE KEY key_identity (eventID),
  KEY key_pending (state, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

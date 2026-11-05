-- Metadata-only governance notice outbox. No feature is enabled by storage.
CREATE TABLE {$NAMESPACE}_metamta.governance_notice_event (
  eventID VARBINARY(64) NOT NULL PRIMARY KEY,
  schemaVersion INT UNSIGNED NOT NULL,
  sourceKind VARBINARY(32) NOT NULL,
  sourceID BIGINT UNSIGNED NOT NULL,
  operationDigest VARBINARY(64) NOT NULL,
  actorDigest VARBINARY(64) NOT NULL,
  action VARBINARY(32) NOT NULL,
  metadataJSON LONGTEXT NOT NULL,
  metadataDigest VARBINARY(64) NOT NULL,
  createdAt BIGINT UNSIGNED NOT NULL,
  state VARBINARY(16) NOT NULL,
  UNIQUE KEY source (sourceKind,sourceID,action)
) ENGINE=InnoDB;
CREATE TABLE {$NAMESPACE}_metamta.governance_notice_delivery (
  eventID VARBINARY(64) NOT NULL,
  recipientPHID VARBINARY(64) NOT NULL,
  mailPHID VARBINARY(64) DEFAULT NULL,
  state VARBINARY(16) NOT NULL,
  createdAt BIGINT UNSIGNED NOT NULL,
  updatedAt BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY(eventID,recipientPHID),
  UNIQUE KEY mail (mailPHID)
) ENGINE=InnoDB;

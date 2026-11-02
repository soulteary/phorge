CREATE TABLE {$NAMESPACE}_user.governance_mediabrowser (
  browserDigest BINARY(64) NOT NULL PRIMARY KEY,
  generation BIGINT UNSIGNED NOT NULL,
  expiresAt BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB;

CREATE TABLE {$NAMESPACE}_user.governance_mediaexchange (
  transactionDigest BINARY(64) NOT NULL PRIMARY KEY,
  browserDigest BINARY(64) NOT NULL,
  generation BIGINT UNSIGNED NOT NULL,
  expiresAt BIGINT UNSIGNED NOT NULL,
  codeDigest BINARY(64) NULL,
  userPHID VARBINARY(64) NULL,
  sessionPHID VARBINARY(64) NULL,
  credentialDigest BINARY(64) NULL,
  accountEpoch BINARY(64) NULL,
  sessionExpiresAt BIGINT UNSIGNED NULL,
  audience VARBINARY(32) NULL,
  purpose VARBINARY(32) NULL,
  returnPath LONGBLOB NULL,
  usedAt BIGINT UNSIGNED NULL,
  UNIQUE KEY codeDigest (codeDigest),
  KEY browserDigest (browserDigest)
) ENGINE=InnoDB;

CREATE TABLE {$NAMESPACE}_user.governance_mediacredential (
  secretDigest BINARY(64) NOT NULL PRIMARY KEY,
  browserDigest BINARY(64) NOT NULL,
  generation BIGINT UNSIGNED NOT NULL,
  userPHID VARBINARY(64) NOT NULL,
  sessionPHID VARBINARY(64) NOT NULL,
  credentialDigest BINARY(64) NOT NULL,
  accountEpoch BINARY(64) NOT NULL,
  expiresAt BIGINT UNSIGNED NOT NULL,
  audience VARBINARY(32) NOT NULL,
  purpose VARBINARY(32) NOT NULL,
  revokedAt BIGINT UNSIGNED NULL,
  KEY browserDigest (browserDigest)
) ENGINE=InnoDB;

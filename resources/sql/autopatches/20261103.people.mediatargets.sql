ALTER TABLE {$NAMESPACE}_user.governance_mediaexchange
  ADD targetJSON LONGBLOB NULL,
  ADD targetDigest BINARY(64) NULL,
  ADD clientNonceDigest BINARY(64) NULL,
  ADD KEY expiresAt (expiresAt);
ALTER TABLE {$NAMESPACE}_user.governance_mediabrowser ADD KEY expiresAt (expiresAt);
ALTER TABLE {$NAMESPACE}_user.governance_mediacredential ADD KEY expiresAt (expiresAt);
CREATE TABLE {$NAMESPACE}_user.governance_mediaquota (
  bucketDigest BINARY(64) NOT NULL PRIMARY KEY,
  windowStart BIGINT UNSIGNED NOT NULL,
  attempts BIGINT UNSIGNED NOT NULL,
  expiresAt BIGINT UNSIGNED NOT NULL,
  KEY expiresAt (expiresAt)
) ENGINE=InnoDB;

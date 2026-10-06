CREATE TABLE {$NAMESPACE}_metamta.gorge_mail_delivery (
 deliveryID VARBINARY(128) NOT NULL PRIMARY KEY,
 payloadHash VARCHAR(64) NOT NULL,
 payload LONGTEXT NOT NULL,
 state VARCHAR(32) NOT NULL,
 revision INT UNSIGNED NOT NULL,
 attempt INT UNSIGNED NOT NULL,
 nextAttempt BIGINT UNSIGNED NOT NULL,
 startedEpoch BIGINT UNSIGNED NOT NULL,
 result LONGTEXT NOT NULL,
 projectionPending TINYINT(1) NOT NULL DEFAULT 0,
 KEY projectionPending (projectionPending,deliveryID)
) ENGINE=InnoDB;
CREATE TABLE {$NAMESPACE}_metamta.gorge_mail_attempt (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 deliveryID VARBINARY(128) NOT NULL,
 attempt INT UNSIGNED NOT NULL,
 startedEpoch BIGINT UNSIGNED NOT NULL,
 finishedEpoch BIGINT UNSIGNED NULL,
 outcome VARCHAR(32) NOT NULL,
 UNIQUE KEY deliveryAttempt(deliveryID,attempt)
) ENGINE=InnoDB;

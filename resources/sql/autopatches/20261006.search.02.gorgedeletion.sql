CREATE TABLE {$NAMESPACE}_search.search_gorgedeletion (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  namespace VARBINARY(64) NOT NULL,
  objectPHID VARBINARY(64) NOT NULL,
  objectClass VARBINARY(128) NOT NULL,
  sourceVersion VARBINARY(512) NOT NULL,
  completedEpoch BIGINT UNSIGNED NULL,
  nextAttempt BIGINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY object (namespace, objectPHID),
  KEY pending (completedEpoch, nextAttempt, id)
) ENGINE=InnoDB;

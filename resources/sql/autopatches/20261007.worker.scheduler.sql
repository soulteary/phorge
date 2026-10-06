CREATE TABLE {$NAMESPACE}_worker.worker_gorgeschedulercontrol (
  id INT UNSIGNED NOT NULL PRIMARY KEY,
  owner VARBINARY(16) NOT NULL,
  epoch BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB;
INSERT INTO {$NAMESPACE}_worker.worker_gorgeschedulercontrol (id, owner, epoch)
  VALUES (1, 'php', 1);

CREATE TABLE {$NAMESPACE}_worker.worker_gorgeschedule (
  triggerID INT UNSIGNED NOT NULL PRIMARY KEY,
  scheduledVersion INT UNSIGNED NOT NULL,
  retryAfter BIGINT UNSIGNED NOT NULL DEFAULT 0,
  lastEvaluatedEpoch BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

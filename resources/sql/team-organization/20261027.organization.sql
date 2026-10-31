CREATE TABLE IF NOT EXISTS {$NAMESPACE}_user.user_organizationteam (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  phid VARBINARY(64) NOT NULL,
  name VARCHAR(255) NOT NULL COLLATE utf8mb4_unicode_ci,
  description LONGTEXT NOT NULL COLLATE utf8mb4_bin,
  parentPHID VARBINARY(64) DEFAULT NULL,
  viewPolicy VARBINARY(64) NOT NULL,
  membersPolicy VARBINARY(64) NOT NULL,
  isArchived TINYINT(1) NOT NULL,
  version INT UNSIGNED NOT NULL,
  creatorPHID VARBINARY(64) NOT NULL,
  dateCreated INT UNSIGNED NOT NULL,
  dateModified INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY key_phid (phid),
  KEY key_parent (parentPHID, id),
  KEY key_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS {$NAMESPACE}_user.user_organizationmember (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  teamPHID VARBINARY(64) NOT NULL,
  userPHID VARBINARY(64) NOT NULL,
  dateCreated INT UNSIGNED NOT NULL,
  dateModified INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY key_team_user (teamPHID, userPHID),
  KEY key_user (userPHID, teamPHID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS {$NAMESPACE}_user.user_organizationaudit (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  teamPHID VARBINARY(64) NOT NULL,
  actorPHID VARBINARY(64) NOT NULL,
  action VARCHAR(32) NOT NULL COLLATE utf8mb4_bin,
  oldValue LONGTEXT NOT NULL COLLATE utf8mb4_bin,
  newValue LONGTEXT NOT NULL COLLATE utf8mb4_bin,
  dateCreated INT UNSIGNED NOT NULL,
  dateModified INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  KEY key_team (teamPHID, id),
  KEY key_actor (actorPHID, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS {$NAMESPACE}_user.user_organizationlock (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  lockName VARCHAR(32) NOT NULL COLLATE utf8mb4_bin,
  dateCreated INT UNSIGNED NOT NULL,
  dateModified INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY key_name (lockName)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

INSERT IGNORE INTO {$NAMESPACE}_user.user_organizationlock
  (id, lockName, dateCreated, dateModified) VALUES (1, 'organization', 0, 0);

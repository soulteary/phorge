USE `{$NAMESPACE}_user`;

CREATE TABLE IF NOT EXISTS user_entrysessionbinding (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sessionKey VARBINARY(64) NOT NULL,
  userPHID VARBINARY(64) NOT NULL,
  principalHash VARBINARY(64) NOT NULL,
  entryHash VARBINARY(64) NOT NULL,
  sessionExpires INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY key_session (sessionKey),
  KEY key_expires (sessionExpires)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

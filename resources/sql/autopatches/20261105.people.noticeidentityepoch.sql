-- Notice-only monotonic account binding. Never a native credential epoch.
CREATE TABLE {$NAMESPACE}_user.governance_notice_identityepoch (
  userPHID VARBINARY(64) NOT NULL,
  generation BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (userPHID)
) ENGINE=InnoDB;

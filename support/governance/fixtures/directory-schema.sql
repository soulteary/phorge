-- N01.ORG-SCHEMA.v1: synthetic-only. NOT a registered production patch.
CREATE TABLE IF NOT EXISTS fixture_guard (
  id INT UNSIGNED PRIMARY KEY,
  marker VARCHAR(80) NOT NULL
) ENGINE=InnoDB;
INSERT IGNORE INTO fixture_guard (id, marker)
VALUES (1, 'N01.ORG-SCHEMA.v1:synthetic-only');
CREATE TABLE IF NOT EXISTS fixture_user (
  phid VARCHAR(64) CHARACTER SET ascii PRIMARY KEY,
  is_admin TINYINT UNSIGNED NOT NULL DEFAULT 0,
  is_approved TINYINT UNSIGNED NOT NULL DEFAULT 1,
  is_disabled TINYINT UNSIGNED NOT NULL DEFAULT 0,
  is_system_agent TINYINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS directory_department (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  phid VARCHAR(64) CHARACTER SET ascii NOT NULL UNIQUE,
  name VARCHAR(200) NOT NULL,
  parent_id BIGINT UNSIGNED NULL,
  state ENUM('active','archived') NOT NULL DEFAULT 'active',
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (parent_id) REFERENCES directory_department (id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS directory_team (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  phid VARCHAR(64) CHARACTER SET ascii NOT NULL UNIQUE,
  name VARCHAR(200) NOT NULL,
  parent_id BIGINT UNSIGNED NULL,
  home_department_id BIGINT UNSIGNED NULL,
  state ENUM('active','archived') NOT NULL DEFAULT 'active',
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (parent_id) REFERENCES directory_team (id),
  FOREIGN KEY (home_department_id) REFERENCES directory_department (id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS current_primary_department (
  user_phid VARCHAR(64) CHARACTER SET ascii PRIMARY KEY,
  department_id BIGINT UNSIGNED NOT NULL,
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (user_phid) REFERENCES fixture_user (phid),
  FOREIGN KEY (department_id) REFERENCES directory_department (id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS team_membership (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  team_id BIGINT UNSIGNED NOT NULL,
  user_phid VARCHAR(64) CHARACTER SET ascii NOT NULL,
  state ENUM('active','suspended','ended') NOT NULL DEFAULT 'active',
  valid_from BIGINT UNSIGNED NOT NULL,
  valid_until BIGINT UNSIGNED NULL,
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY membership_pair (team_id, user_phid),
  FOREIGN KEY (team_id) REFERENCES directory_team (id),
  FOREIGN KEY (user_phid) REFERENCES fixture_user (phid)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS admin_appointment (
  team_id BIGINT UNSIGNED NOT NULL,
  user_phid VARCHAR(64) CHARACTER SET ascii NOT NULL,
  state ENUM('active','ended') NOT NULL DEFAULT 'active',
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (team_id, user_phid),
  FOREIGN KEY (team_id, user_phid)
    REFERENCES team_membership (team_id, user_phid)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS directory_assignment_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_phid VARCHAR(64) CHARACTER SET ascii NOT NULL,
  previous_department_id BIGINT UNSIGNED NULL,
  next_department_id BIGINT UNSIGNED NULL,
  actor_phid VARCHAR(64) CHARACTER SET ascii NOT NULL,
  occurred_at BIGINT UNSIGNED NOT NULL,
  operation_id VARCHAR(80) CHARACTER SET ascii NOT NULL UNIQUE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS directory_audit (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_phid VARCHAR(64) CHARACTER SET ascii NOT NULL,
  target_phid VARCHAR(64) CHARACTER SET ascii NOT NULL,
  event_type VARCHAR(80) CHARACTER SET ascii NOT NULL,
  event_data LONGTEXT NOT NULL,
  occurred_at BIGINT UNSIGNED NOT NULL,
  operation_id VARCHAR(80) CHARACTER SET ascii NOT NULL UNIQUE
) ENGINE=InnoDB;

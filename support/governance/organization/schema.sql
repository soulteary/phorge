-- N03.ORG-WRITE.v1 isolated schema. No production patch registration.
CREATE TABLE fixture_guard (
 id INT UNSIGNED PRIMARY KEY,
 marker VARCHAR(96) NOT NULL,
 schema_version INT UNSIGNED NOT NULL,
 schema_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 owner_nonce CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 epoch_version BIGINT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB;
CREATE TABLE org_user (
 user_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 is_admin TINYINT UNSIGNED NOT NULL DEFAULT 0,
 is_approved TINYINT UNSIGNED NOT NULL DEFAULT 0,
 is_disabled TINYINT UNSIGNED NOT NULL DEFAULT 0,
 is_system_agent TINYINT UNSIGNED NOT NULL DEFAULT 0,
 is_mailing_list TINYINT UNSIGNED NOT NULL DEFAULT 0,
 version BIGINT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB;
CREATE TABLE org_unit (
 unit_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 name VARCHAR(255) NOT NULL,
 parent_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 department_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 version BIGINT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB;
CREATE TABLE org_member (
 team_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 user_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 valid_from BIGINT UNSIGNED NOT NULL,
 valid_until BIGINT UNSIGNED NULL,
 version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 PRIMARY KEY(team_id,user_id)
) ENGINE=InnoDB;
CREATE TABLE org_appointment (
 team_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 user_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 role VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 valid_from BIGINT UNSIGNED NOT NULL,
 valid_until BIGINT UNSIGNED NULL,
 version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 PRIMARY KEY(team_id,user_id)
) ENGINE=InnoDB;
CREATE TABLE org_operation (
 operation_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 request_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 actor_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 result_json MEDIUMTEXT NOT NULL,
 created_at BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB;
CREATE TABLE org_audit (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 operation_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 actor_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 action VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 target_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 before_json MEDIUMTEXT NOT NULL,
 after_json MEDIUMTEXT NOT NULL,
 created_at BIGINT UNSIGNED NOT NULL,
 KEY(operation_id)
) ENGINE=InnoDB;
CREATE TABLE org_approval (
 approval_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 operation_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 binding_json MEDIUMTEXT NOT NULL,
 state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 approver_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 approver_version BIGINT UNSIGNED NULL,
 approver_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 approved_at BIGINT UNSIGNED NULL,
 consumed_at BIGINT UNSIGNED NULL,
 consumed_by_operation VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL,
 version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 KEY(operation_id),
 KEY(consumed_by_operation)
) ENGINE=InnoDB;

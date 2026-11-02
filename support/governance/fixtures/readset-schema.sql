-- N07.READSET.v1 synthetic-only, never a registered production patch.
CREATE TABLE fixture_guard (
  id INT UNSIGNED PRIMARY KEY,
  marker VARCHAR(80) NOT NULL
) ENGINE=InnoDB;
INSERT INTO fixture_guard (id, marker)
VALUES (1, 'N07.READSET.v1:synthetic-only');
CREATE TABLE readset_binding (
  binding_id VARCHAR(80) CHARACTER SET ascii PRIMARY KEY,
  operation_id VARCHAR(80) CHARACTER SET ascii NOT NULL,
  actor VARCHAR(100) CHARACTER SET ascii NOT NULL,
  executor VARCHAR(100) CHARACTER SET ascii NOT NULL,
  approver VARCHAR(100) CHARACTER SET ascii NOT NULL,
  target VARCHAR(100) CHARACTER SET ascii NOT NULL,
  operation VARCHAR(80) CHARACTER SET ascii NOT NULL,
  object_class VARCHAR(30) CHARACTER SET ascii NOT NULL,
  parameters_digest CHAR(64) CHARACTER SET ascii NOT NULL,
  actor_session VARCHAR(100) CHARACTER SET ascii NOT NULL,
  executor_session VARCHAR(100) CHARACTER SET ascii NOT NULL,
  approver_session VARCHAR(100) CHARACTER SET ascii NOT NULL
) ENGINE=InnoDB;
CREATE TABLE readset_source (
  row_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_role VARCHAR(100) CHARACTER SET ascii NOT NULL,
  source_kind VARCHAR(80) CHARACTER SET ascii NOT NULL,
  source_id VARCHAR(200) CHARACTER SET ascii NOT NULL,
  store_name VARCHAR(80) CHARACTER SET ascii NOT NULL,
  revision VARCHAR(100) CHARACTER SET ascii NOT NULL,
  eligibility ENUM('eligible','ineligible','unknown') NOT NULL
) ENGINE=InnoDB;
CREATE TABLE readset_manifest (
  binding_id VARCHAR(80) CHARACTER SET ascii NOT NULL,
  source_row_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (binding_id, source_row_id),
  FOREIGN KEY (binding_id) REFERENCES readset_binding (binding_id)
) ENGINE=InnoDB;
-- A source FK is deliberately absent: a dangling manifest models source loss
-- and must be detected as incomplete, not treated as an empty/no-op grant.

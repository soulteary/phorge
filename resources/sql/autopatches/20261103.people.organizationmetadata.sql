ALTER TABLE {$NAMESPACE}_user.organization_unit
  ADD visibilityModel VARBINARY(32) NOT NULL DEFAULT 'restricted-admin-v1',
  ADD visibilityVersion BIGINT UNSIGNED NOT NULL DEFAULT 1,
  ADD associatedDepartmentID VARBINARY(64) DEFAULT NULL,
  ADD contactUserPHID VARBINARY(64) DEFAULT NULL;
ALTER TABLE {$NAMESPACE}_user.organization_primarydepartment
  MODIFY unitID VARBINARY(64) DEFAULT NULL;

-- Mandatory native Config metadata before running this release. No feature activation.
CREATE TABLE {$NAMESPACE}_config.governance_configepoch (
  namespace VARBINARY(64) NOT NULL,
  configKey VARBINARY(64) NOT NULL,
  epoch BIGINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (namespace,configKey)
) ENGINE=InnoDB;
INSERT INTO {$NAMESPACE}_config.governance_configepoch (namespace,configKey,epoch) VALUES
('default','governance.project-approval.enabled',1),
('default','policy.allow-public',1),
('default','policy.locked',1),
('default','phabricator.application-settings',1),
('default','phabricator.uninstalled-applications',1),
('default','phabricator.show-prototypes',1),
('default','phorge.product-profile',1),
('default','load-libraries',1),
('default','cluster.databases',1),
('default','gorge.db.uri',1),
('default','mysql.host',1),
('default','mysql.port',1),
('default','mysql.user',1),
('default','mysql.pass',1),
('default','storage.default-namespace',1),
('default','security.require-multi-factor-auth',1);

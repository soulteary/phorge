# Focused legacy-role database compatibility check

The new always-on legacy-role hook must preserve the documented MariaDB path.
Its shared metadata locks use LOCK IN SHARE MODE, not MySQL-only FOR SHARE.

Run `php support/governance/native-organization/test-native-organization-mysql.php
--legacy-sql-compatibility` with the same explicit isolated fixture environment
as the normal native runner. Unknown or extra arguments remain rejected. This
mode calls `governance_native_legacy_sql_compatibility_cases($fixture, $check)`
from the owned full-native fixture after restoring the actual native schema and current
patches. The fixture must use a disposable MariaDB instance and its restricted
writer principal, no published port, no deployment configuration and no daemon
or outbound delivery. The parent fixture owns cleanup. The case creates actual
PhabricatorUser objects and calls both actual PhabricatorUserEditor legacy role
methods, with both optional governance features disabled. It checks the actual
role rows, independent epoch increments and absence of transaction/locking leaks.
The server's VERSION() identifies the runtime tested. The same case can run on
MySQL for regression comparison; that is not MariaDB evidence.

Do not call the optional NativeIdentitySource/API-token adapter during this
focused profile. Its same-primary proof currently requires MySQL server_uuid;
organization service isolation-variable discovery is likewise a separate
optional-profile gate. A successful legacy check does not certify the full
optional backend on MariaDB, all versions at or above the documented minimum,
or arbitrary distributed database placement.

The existing strict legacy-role fault/outer-lock cases remain complementary
coverage on the deployed MySQL profile. Matching supported-PHP runtime receipts
and exact executed-source hashes are required; source grep or PHP lint is not
SQL dialect runtime proof. No script should substitute a fake SQL parser or
mocked native editor result for the database run.

# Isolated directory storage fixture

This is the N01.ORG-SCHEMA.v1 / N02.DIRECTORY-VIEW.v1 foundation only.
Nothing registers an application, route, policy, production schema patch or
member-management operation. The independent `fixture_user` table contains only
synthetic eligibility inputs; it is not a native-user replication mechanism.
No production configuration or application bootstrap is loaded.

Run `test-directory-mysql.php` inside a disposable PHP 8.5.12 test container
which already has the project's existing mysqli extension and a private MySQL
server reachable on loopback. There must be no public ports. Supply these test
variables through the restricted test environment:

- `PHORGE_GOVERNANCE_ISOLATED_TEST=1`
- `PHORGE_GOVERNANCE_FIXTURE_DSN`: explicit `mysql:` DSN, host `127.0.0.1` or
  `::1`, port, a `governance_fixture_*` database name, charset `utf8mb4`
- `PHORGE_GOVERNANCE_FIXTURE_USER`
- `PHORGE_GOVERNANCE_FIXTURE_PASSWORD`

The DSN's database is only a validated fixture identifier. The runner creates
its own random `governance_fixture_*` database and removes it in `finally`.
The test account must be restricted to the disposable server and allowed to
create/drop its synthetic fixture database. Credentials and connection
parameters are not emitted. A failed run emits the stage and exception class,
not the raw database exception. Failure to remove the owned schema fails the
run. If the process itself is killed, dispose of the entire owned test server;
there is no general cleanup command which scans arbitrary databases.

```sh
php support/governance/fixtures/test-directory-mysql.php
```

Both the fixture runner and connection factory reuse the supported-runtime
helper before establishing any connection. Unsupported versions fail explicitly
at `unsupported-version`, without SQL.

The actual MySQL assertions include one-current-primary-department uniqueness,
a real membership foreign-key prerequisite for appointment, post-revocation
reads using a second independent connection, effective membership expiry,
restricted keyset pages, literal search, per-team relation privacy, and no
ordinary-member/self exception. Array mocks are not reported as database
passes. This is not a capacity benchmark or a claim of true concurrent
transaction interleaving: the second-connection test commits a revocation
before the next read.

The read adapter accepts only an explicit CLI test opt-in, loopback test DSN,
fixture schema prefix and matching synthetic schema marker. These are accident
prevention guards, not protection from a malicious local database administrator.
It has no writer API; the SQL implementation only issues SELECT statements. The
fixture initialization account is not claimed to be database-enforced SELECT-only. Fixtures deliberately create synthetic memberships and
appointments directly; that must never become a production bootstrap, migration
seed or an approval bypass.

Directory readers return only managed team relations. Current roster and user-team
reads exclude ended, suspended, future and expired relationship rows. A managed
roster may include a stored active relationship for a disabled account, explicitly
marked `accountEligible=0`; user-team reads exclude ineligible subjects.
System administrators may inspect a current stored relation to an archived team,
whose archived state is returned explicitly; this is not an effective policy
grant or a promise that the user can access that team.
This batch has no relationship-history read interface. Ordinary members cannot
read their own directory entry. Parent/home department references are redacted
unless independently visible. Disabled, unapproved or system-agent actors are
not eligible; a team appointment also requires active, unexpired membership and
an active team. This does not create project access, implement a production
policy evaluator or claim complete recovery enforcement.

The schema uses current rows plus separate history/audit fixtures. It does not
perform audit deletion, enforce an eight-level hard limit or modify native
project hierarchy. History and audit have no reader endpoint in this batch.

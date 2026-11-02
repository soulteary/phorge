# Synthetic read-set MySQL fixture

Use only a disposable PHP 8.5.12+ 8.5 test environment with mysqli and a private
loopback MySQL server. The existing explicit test opt-in and fixture credentials
are supplied in the restricted environment, never command-line output:

- `PHORGE_GOVERNANCE_ISOLATED_TEST=1`
- `PHORGE_GOVERNANCE_FIXTURE_DSN`: strict loopback `mysql:` DSN with a
  `governance_fixture_*` database identifier and `utf8mb4`
- `PHORGE_GOVERNANCE_FIXTURE_USER`
- `PHORGE_GOVERNANCE_FIXTURE_PASSWORD`

Run `php support/governance/fixtures/test-readset-mysql.php`.

The supplied database identifier is only a guarded fixture namespace. The
runner creates its own random schema, records ownership only after successful
CREATE, and cleans up only that owned schema. Collision failure injection must
not acquire ownership or drop an existing database. If killed ungracefully,
dispose of the owned disposable server rather than sweeping arbitrary schemas.
No application/deployment configuration or production schema registry is loaded.

The fixture uses real InnoDB rows representing synthetic authorization sources,
not real application user or policy data. An independent connection changes
membership, appointment, account, named-policy, Space and parent/member epochs
while the target policy epoch stays fixed. Missing, additional or deleted source
rows, wrong role identities, ineligible/unknown sources, a changed operation and
unproven cross-store observations block.

A separate repeatable-read connection intentionally retains an old revision
after another connection commits. The store's private fresh autocommit read
observes the change and invalidates the read set; the stale caller connection is
not accepted as an observation adapter. This is a source-observation test, not
proof of locks across a real business transaction or complete application
source closure. All execution remains blocked.

The fixture account creates and drops only its disposable schema. The adapter
contains SELECT operations only, but this is not a database-enforced SELECT-only
account test. Store labels do not establish cross-database consistency. Failure
receipts emit only a safe stage and exception class, not raw SQL or credentials.

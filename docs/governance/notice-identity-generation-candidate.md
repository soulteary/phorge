# Notice-only native identity generation candidate — unverified

The isolated raw DAO profile proved that a persistent old notice binding revives
after separately committed disable and enable saves with notifications enabled
and recovery disabled. Both actual DAO states committed, but neither a USER
transaction nor legacy role epoch changed. This is a real failed safety test,
not a successful diagnostic contract or an authorization alternative.

The independent candidate adds a notice-only generation in the User database.
It deliberately does not reuse the shared legacy identity epoch: a notification
fix must not silently batch-invalidate native sessions/API/media credentials.
The existing legacy UserEditor role epoch updates remain exactly as before.
There is no static per-transaction latch or deduplication state.

With notices enabled, native User UPDATE is enclosed in an owned nested Lisk
transaction on the actual User writer. It locks the actual persisted PRIMARY
row, executes the original RecoveryGuard/native update path, rereads actual
persisted security fields, and advances a notice generation only for a real
security-state change. The generation SQL and native User SQL share the same
connection/transaction. Unrelated saves do not advance it. Original INSERT and
recovery initial-admission semantics are not enabled or widened. With notices
disabled the original update path remains. Forced, nonprimary, untracked raw
transactions and non-InnoDB sources fail closed in the candidate scope.

Notice account binding v2 includes that generation; old v1 hashes are never
upgraded in place. No immutable mail snapshot is rewritten. No code enables the
feature, changes roles, creates credentials or grants restoration.

## Emergency-disable and known-ineligible availability

Root selected the strict final-disabled original fast path, before notice config
or storage. It is extended only to strict canonical native fields that guarantee
ineligibility under current `NoticeSource::canReceive`: disabled1, approved0,
admin0, system-agent1 or mailing-list1. Raw values are checked without getter
bool coercion. Unknown aliases and email state are not inferences of this profile.
These writes retain the existing RecoveryGuard behavior and do not touch notice
storage. A restoring eligible/security update must atomically advance the notice
generation. In particular disable1 may skip the generation, but enable0 cannot
restore a deliverable old binding without advancing it. Original legacy epoch
hooks remain independent; there is no static transaction latch.

## OFF-to-ON activation is a separate blocked gate

No writer captures notice generations while the notice flag is off. The existing
v1 account hash is rejected by v2, never upgraded, but v2 alone does not certify
re-enabling a prior installation. Proposed minimal activation profile: a locked
externally managed notice incarnation, outside application SQL backup, must be
bound into every v2 account hash; every OFF-to-ON activation rotates it and all
workers reload, so a prior activation's notice binding never revives. Do not
rotate native API/session/media credentials. Default remains off. This profile
and its real OFF-to-ON SQL/physical tests are not implemented yet, so activation
stays blocked and no source completeness is claimed during the off interval.

## Mandatory next evidence

Keep the actual two-commit raw DAO test and fresh policy denial, then prove a
new real notice remains deliverable. Add real unchanged/username save controls,
Editor disable-enable and legacy role paths without unsafe dedup expectations,
outer transaction rollback, nested savepoint rollback, exact epoch-write SQL
fault rollback, exhaustion, unavailable-table/engine profile and emergency
disable behavior. Require notices-on/recovery-off explicitly. None of these may
be replaced with direct calls to an epoch increment function or synthetic mail.

The raw driver also needs explicit initialization of the real zero-worker
registry; null cleanup is not zero. That fixture correction is in this draft.
New DDL/SchemaSpec and an explicit reviewed migration entry are present; supported native classmap,
source catalog and full exact-HEAD pure/native/physical proof are still pending.
No current test evidence or old933 result proves this new candidate. PR107 stays
Draft, production flags remain off and deployment is not authorized here.

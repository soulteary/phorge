# Governance notices — draft implementation and remaining gates

This draft adds a transactionally recorded governance-notice outbox for the
selected organization, approval and recovery producers. Event metadata contains
identities/digests rather than names, rosters, reasons or private source text.
The outbox insert borrows the original physical User transaction; a real worker
creates a persistent recipient binding and link-only native mail. Delivery checks
current recipient account, primary address and source eligibility at each actual
physical attempt. The currently bounded audience is current system administrators,
not every future governance recipient profile.

All production features remain off by default. This PR does not deploy, enable,
provision public services or authorize restoration. It stays **Draft** until Root
closes the remaining gates, even if an intermediate CI run is green.

The signed `dd6d217ca7fa288ab74ded2776fb7128fff6c502` isolated source passed
248 supported PHP8.5.12 pure methods and 23 actual allow/replay pipeline checks:
real producer/outbox, relay, three Go daemon stages, native HTTP handler, current
physical authorization callback and stable Owlmail0.14 SMTP capture. Raw MIME
was checked for generic link-only text and a single synthetic destination.
All owned processes, schemas, principals, reader and private fixture storage were
cleaned; 6852 runtime source files and36 archive aliases matched. Exact hashes
and boundaries are in `evidence/governance-notice-draft/acceptance.json`.

The older signed23b component passed248 pure/933 native checks with the same
production source. That is historical component evidence, not a claim that this
new draft HEAD passed all933. The raw ORM fixture is now included but UNRUN.
It uses actual PhabricatorUser DAO saves to commit disable then enable while
notifications are on and account recovery is off, with no intermediate policy
probe or fabricated USER transaction. An old delivery binding must remain denied;
if authorization revives, the test intentionally fails and retains bounded facts.
The separate UserEditor ABA profile does not prove this DAO entry.

Before ready/merge: prove raw ORM denial, physical revoke/rollback and remaining
producer/recipient coverage, run fresh exact-HEAD Native933 CI, and obtain current
independent review. The first allow profile is not the full N11 implementation or
global source closure. Browser, production gateway and restoration permissions
are not certified here. No private logs, MIME bodies, caches, host data, credentials
or public-domain configuration are part of this PR.

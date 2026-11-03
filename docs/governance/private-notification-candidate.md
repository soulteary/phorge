# Private email notification candidate

This change keeps `governance.private-mail.enabled` locked and false. The native
permission model, optional applications, production deployment and existing
Phorge/Gorge responsibilities remain unchanged.

The selected candidate sends private business notifications as generic subjects
and authenticated application links. The reviewed source catalog contains 34
explicit producer files and seven shared construction/authorization consumers.
It includes retained application editors and direct calendar/file producers;
source presence does not mean an optional application is enabled or tested.
Account verification, login/reset, invitations, contact/SSH/security producers are
classified separately to preserve their native challenge and address semantics.
Unknown producers fail closed. Classification is fixed source provenance, not a
new user role, permission grant or proof that the security matrix is complete.

Business content is cleared before native persistence and final serialization:
no private object title/action, comments/custom fields, HTML, attachment bytes,
actor display names, stamps or custom headers leave in the message. Business
reply/threading metadata is deliberately omitted. Native target splitting uses
individual-recipient messages. A direct shared business envelope remains blocked
until its own explicit split and runtime tests exist.

Each supported attempt resolves the current eligible native recipient and the
actual viewer `ObjectQuery`, including its native query-level boundaries. A fresh
owned request-cache scope prevents an earlier Space decision from surviving into
a later attempt; the exact prior cache is restored. A denied/quarantined message
cannot revive after access is regranted. An unsafe legacy prepared payload is
never rewritten under the same immutable delivery ID or resubmitted as private
bytes. Already accepted and unknown outcomes retain the existing delivery ledger
semantics; sent copies cannot be recalled.

The paired Gorge implementation calls the genuine service-authenticated native
`mail.delivery` method immediately before every physical `adapter.Send`, including
adapter retries and fallback. The callback binds the actual serialized message,
mail and immutable delivery identity, then checks current native policy. Missing,
unavailable or denied authorization sends no bytes. This is not an atomic ACL to
SMTP transaction and establishes no new seconds-level revocation promise.

The isolated combined-main evidence is 148 pure PHP methods and 302 real native
MySQL/Gorge checks, with 5,867 executed source files matching the host and clean
55-schema/two-runtime-user/reader cleanup. The paired Go test suite passed all 40
tested packages; 13 packages had no tests. Governance Python tests passed 89 cases
and permission Python tests passed 84. The strict source/receipt gates reject
missing scenarios, duplicates, unknown source drift and promotion of partial
flags. CI runs both the existing native receipt gate and the notification source
gate, with the paired queue binary supplied explicitly.

These counts prove the bounded native envelope/source-query slice. They do not
prove physical native callback/SMTP/Owlmail delivery, every producer/security
flow, shared direct-producer splitting, browser behavior or production readiness.
Those remain release gates. The new English messages/options also have Chinese
translations; a browser-level localized notification flow is not yet claimed.

Evidence and reviewed producers are stored next to this document. The older
isolated 145/261 candidate run is retained privately and is not substituted for
the combined-main proof. A future main rebase or runtime source change requires
fresh matching evidence.


## Versioned security source checkpoint

The later security-source slice passed **150 pure PHP methods and 325 actual
native SQL checks**. The isolated runtime matched 7,453 explicitly collected
source/resource files byte for byte; this broader collection count is not
interchangeable with the older PHP-only source count. All 55 owned schemas,
two runtime users and the reader were removed with no cleanup failures. The
148/302 candidate evidence remains immutable and is not substituted for these
new sources.

Class names no longer exempt all security mail. Each supported email flow binds
its actual native account, email address and source; PHID delivery must still
resolve to the exact bound, unique current primary email. Login/welcome retain
the real native challenge, email verification tracks its current code, and
username/approval messages bind the actual committed native USER transaction.
Registration queue notices are split per current administrator and projected to
a generic authenticated link. A demoted administrator cannot receive a queued
copy. The registration test invokes the real producer method; it does not claim
to exercise the complete registration HTTP workflow.

Accounts without a primary email retain their ordinary native rename,
approval and approval-withdrawal behavior. These optional owner notices are
skipped without inventing an email address; a deliverable administrator copy is
independent. SSH notices without a valid owner destination remain unbound and
fail closed at delivery, without blocking the key mutation. Full SSH mutation
and device notification runtime coverage is still outstanding.

This version supports only the deployed **single-primary database profile**.
When the email feature is on, configured `cluster.databases` routing or native
read-only state refuses authorization before and after the current-source
queries. An `r`-labelled Lisk handle alone is not classified as a replica.
Configured replica/failover topologies require a separately implemented and
validated primary-only path; a fresh request cache is not a topology proof.

Legalpad's unbound signature-verification producer is refused while this
candidate is enabled. A supported document/signatory authorization flow remains
unimplemented. Old/new primary email alerts are source-reviewed but need their
own producer runtime cases. SMS is outside this email policy and has no new
runtime certification. The producer catalog records these boundaries explicitly.

Portable CI runs the reviewed source catalog and strict native receipt gate,
with explicit paired file/taskqueue binaries. Mutation tests reject missing
no-email regression cases, promoted SMTP/security completion flags, source drift
and unsupported-signature claims. These checks must pass on a clean checkout;
unknown annotated producer files are rejected, not silently excluded. The newer
39-case physical business-mail evidence is a separate parent-owned checkpoint
on older sources and does not certify SMTP delivery of these new security flows.

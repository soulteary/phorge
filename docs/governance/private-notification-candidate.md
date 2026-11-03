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

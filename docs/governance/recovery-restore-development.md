# Isolated restoration development

This unexecuted successor starts at main f4c5702a. The completed stop-ledger
223/819 evidence applies to that historical source, not these changes.

The candidate persists review requests, independent current administrator
reviews, operation receipts and audit records in one native People transaction.
Every review remains `permitState=blocked`. A stored acknowledgement is not an
execution permit. Native enable is refused while any actual source family or
execution provider remains incomplete. No route, dispatcher or live migration
is added. The optional account-recovery switch defaults to false.

The current service locks planned native User rows in sorted order, obtains
real credentials, captures sources, and binds request/operation identifiers.
It rechecks sources, requester/reviewer credentials, current administrator role
and database time after ledger/audit writes. Current-admin history exposes only
seven metadata columns; expired review credentials do not prevent an otherwise
currently authorized administrator from reading history. Only that fixed read
entry has scoped transaction-bookkeeping write-guard relief.

Actual source adapters implemented in this draft:

- Native account and direct Web/API resources reuse the existing bounded,
  fixed-profile readers inside the caller's transaction. The new helper owns
  no transaction and cannot roll back the service. It does not initialize an
  absent actor epoch or mutate credential sources.
- Media captures fixed selected credential/exchange/browser records and the
  current external issuer binding. It does not certify every producer, quota
  source, or restored snapshot lineage.
- Directory capture locks the existing directory mutex after User/credential
  locks and hashes selected memberships, appointments, primary department and
  referenced units. The known mutex fences native directory writers, not
  arbitrary SQL writers, transfer queues or all future producers.

All thirteen server-fixed families remain incomplete. In particular, Project
edges/materializers/rules, named/default policy, Spaces, automatic object
authority, file attachments/derivations, layered configuration, extensions and
external restore lineage still require concrete adapters and absence fences.
Native Web/API credential disposition is not newly selected as global bulk
revocation. Old media credentials must not revive after restoration.

The reused ORM guard denies qualification increases without a complete grant,
preserves flag-off native behavior and emergency disable, and rejects untracked
raw transactions before issuing START TRANSACTION. Adoption remains blocked:
first approval, all producer paths and a real complete execution lease are not
proved. New registrations, unique-key collision handling and nested rollback
need actual native tests before this guard can be considered supported.

Only host syntax checks have run. New pure methods are not yet registered or
executed on supported PHP. Map, SchemaSpec comparison, candidate migration,
SQL/CSRF/concurrency/rollback, physical restoration and all-source coverage are
unrun. No production activation, permission migration or deployment occurred.

# Restricted native history candidate

This O6 slice adds current-system-administrator-only metadata history with
bounded keyset pages and an exact elapsed 365-day retention-candidate report.
It does not load or return before/after snapshot JSON, delete records, dispatch
notifications, or grant directory/project permissions. The feature remains off.

The native History controller, existing Conduit query dispatch and management
link share the sealed native actor service. Each page revalidates current native
account/credential eligibility; an old cursor is not an authorization ticket.
POST is rejected. Read-only request write-guard bookkeeping is scoped and restored.

The candidate adds two pure methods and nine actual native-fixture scenarios,
including real administrator revocation using a Request/Cancel URI and the
native no-factor normal-session MFA path. These scenarios are **not yet run**
against this source. Earlier lifecycle/media checkpoints do not certify O6.

O7 transfer/handover and O8 due/outbox remain separate unfinished packages. No
production schema migration, optional activation, destructive 365-day cleanup
or full browser acceptance is included.

# Restricted native history candidate

This O6 slice adds current-system-administrator-only metadata history with
bounded keyset pages and an exact elapsed 365-day retention-candidate report.
It does not load or return before/after snapshot JSON, delete records, dispatch
notifications, or grant directory/project permissions. The feature remains off.

The native History controller, existing Conduit query dispatch and management
link share the sealed native actor service. Each page revalidates current native
account/credential eligibility; an old cursor is not an authorization ticket.
POST is rejected. Read-only request write-guard bookkeeping is scoped and restored.

The candidate adds two pure methods and eleven actual native-fixture scenarios,
including real administrator revocation using a Request/Cancel URI and the
native no-factor normal-session MFA path. The actual PHP 8.5.12 run passed 147 native test methods and 297 native SQL
checks, including all eleven new history checks; 5866 executed PHP/SQL source
files matched the host. Cleanup removed 55 owned schemas, two runtime users
and the reader with no failures. The first run exposed a missing request
boolean default; the fixed normal GET path passed the retained regression.
Evidence is in `evidence/native-history-297-20261103`. This isolated result
does not prove hosted CI, browser acceptance or production activation.

O7 transfer/handover and O8 due/outbox remain separate unfinished packages. No
production schema migration, optional activation, destructive 365-day cleanup
or full browser acceptance is included.

Retention pagination scans at most the requested 1–100 primary-key rows before
filtering old records. An empty matching page may still return a next cursor;
clients and the native page follow that cursor to reach later candidates. The
limit bounds examined rows, not only returned matches. This prevents an all-recent
report from holding the organization mutex across the entire retained history.

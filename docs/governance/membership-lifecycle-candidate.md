# Native membership lifecycle candidate

This checkpoint preserves the feature-gated development of native O4 lifecycle
regression coverage and explicit O5 membership/appointment compounds. The
organization feature remains disabled by default. Native project permissions
and join policies are unchanged; no deployment or permission migration occurs.

The earlier supported-runtime checkpoint passed 142 pure methods and 225 native
SQL checks against an earlier revision. It does **not** validate all code in this
checkpoint. The newer candidate adds real concurrent operation replay, owned SQL
fault rollback, manager authorization and CSRF cases, plus native proposal,
independent approval review and execution forms. A fresh combined-source run after integrating main has now passed **142 pure
methods and 244 real native SQL checks**, with seven workers cleaned up, all 55
owned schemas and two runtime accounts removed, and the reader removed. This
is a candidate backend/controller check, not hosted CI, wire or browser proof.
Evidence is in `evidence/membership-lifecycle-checkpoint-20261103/`; the exact
earlier 225-check receipt is retained separately in
`evidence/membership-lifecycle-early-225-20261103/`.

Compound requests explicitly bind acknowledgement, target, operation, separate
membership/appointment periods and actual before-source versions. Approval is
consumed before either relationship changes. Both relationships, consumption,
audit and operation ledger share the native transaction; the final gate checks
the intended postcondition and current identities, credentials and time. Existing
standalone appointment approval does not implicitly create membership.

O4/O5 remain partial: the complete timing, cross-team, fault-position and browser
matrix is not certified by this checkpoint. Native history/transfer/handover/due
worklist packages O6–O8 remain separate unfinished work. No dispatcher or history
purge is enabled, and historical relationships are retained.

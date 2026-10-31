# D06: native workspace and record-to-task drafts

- Source: `soulteary/phorge#49`, immutable head
  `4051759aedb701c46b0b859185d08b6873fbd066`.
- Dependencies: D01 regression foundation and D05 project records.
- Included atoms: W01–W04, W06–W08, H10–H12.
- Controls: native workspace by default; task drafts disabled by default;
  publication/read-only state remains enforced by D05 at every write boundary.
- Adaptations: English `pht()` sources and Chinese catalog; fresh account
  validation for task drafts; direct-route checks; existing task dictionary
  retained; native UI/ordering/drag metadata preserved.
- Deferred: D13 global task statuses, P2 organization/member/role changes, and
  optional entry/maintenance services.
- Paired service ownership: file/image/render validation continues against the
  immutable Gorge revision. HTTP validation preserves the production default
  builtin-composition mode; it does not silently migrate that optional mode.

The HTTP harness exposed a pre-existing optional-mode compatibility gap:
native page favicons request 76/120/152-pixel outputs, while the paired Gorge
composition recipe accepts 16/32/64/128. This package does not widen the external
API, change the production default, or add a PHP fallback to hide that gap.
Migrating builtin composition must be validated separately across repositories.

Exact native/HTTP/browser results and the PR/commit identities are recorded by
the integration batch. The source contribution's reported browser counts are
not counted as tests reproduced by this package.

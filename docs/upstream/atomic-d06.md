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

## Current checkpoint

D06 merged as #57. The six-package inventory is now 141 native methods; the
focused D01/D05/D06 selection is 56 + 24 + 11 = 91 methods. HTTP acceptance
still has 30 assertions, separately recorded from browser checks. PHP 8.5.12
is the sole supported baseline, with explicit JIT disable and zero buffer.
Gorge's separately merged favicon fix and the formal r3 pair supersede the
historical external API gap below; Phorge adds no rendering fallback. Keep
optional controls disabled outside the disposable acceptance fixture.

The following paragraphs preserve the original D06 integration history.

The HTTP harness exposed a pre-existing optional-mode compatibility gap:
native page favicons request 76/120/152-pixel outputs, while the paired Gorge
composition recipe accepts 16/32/64/128. This package does not widen the external
API, change the production default, or add a PHP fallback to hide that gap.
Migrating builtin composition must be validated separately across repositories.

Exact native/HTTP/browser results and the PR/commit identities are recorded by
the integration batch. The source contribution's reported browser counts are
not counted as tests reproduced by this package.

The focused native package gate covers 49 D01 foundation methods, 24 D05 record
methods, and 11 D06 workspace/draft methods (84 total), with no allowed skips.
Run `python3 tests/atomic/run.py --package D01 --package D05 --package D06
--output <new-directory>` on a clean tree. The separate HTTP driver reproduces
30 assertions against disposable native sessions and storage; its report must
also confirm successful cleanup. Browser persistence and layout evidence is
recorded separately and is not added to either automated count.

After merging current main through D05 `69075fae3c`, all six manifests are
installed. The complete current-source native gate is therefore 134 methods,
run without `--package` filters. PR #57 records that complete result separately
from the focused 84-method history. The HTTP fixture uses PHP's default
non-JIT mode explicitly, with a DB-free real-server regression and effective
runtime settings in its report; production PHP 8.3 and OPcache settings are
unchanged. See `tests/project-workspace/README.md` for the reproduced JIT issue.

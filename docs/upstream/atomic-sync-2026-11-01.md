# Atomic contribution synchronization — 2026-11-01

## Reviewed inputs

- Main implementation baseline: `a6d18ba72c805bc54e703ba2c02a53d5ba3d2290`.
- PR #47: `53f319fdff64d880699e127db3c3ffa7b6ae9739`.
- PR #48: `5b08bcdfca114bec1925a6c9d9d150f0096e5dd8`.
- PR #49: `4051759aedb701c46b0b859185d08b6873fbd066`.
- PR #50: `47f9cd049c1a436e6a5ac377123627c4d2f010b0`.

The fixed inputs were refreshed before implementation. This is selective
contribution intake, not a wholesale upstream merge or a claim that every
source PR capability has been adopted. The 2026-10-31 upstream-history
checkpoint and its deferred list remain unchanged.

## Batch progress

| Package | Atoms and scope | State | Source / verification |
| --- | --- | --- | --- |
| D01 | B01/B02/B03/B05/B07: isolated native regression gates, discovery, language/map checks and mixed-instance release protocol | Implemented for review | Current main; real MySQL plus paired Gorge file/image services, result policy and configuration-isolation regressions; exact native execution recorded in PR evidence |
| D02 | Private original/derived file visibility and People denial robustness; B08 source-model decision | Separate P1 PR | Selected #50 only; bearer restriction deferred |
| D03 | Localized policy presentation and explicit replacement of unavailable rules | Separate P1 PR | Selected #50 only; private templates deferred |
| D04 | Opt-in hierarchy navigation and ancestor privacy | Separate P1 PR | Selected #50 and compatible privacy cases; no project permission migration |
| D05 | Native append-only project records, current authorization and compatibility | Separate P1 PR | Selected #49; no role/team dependency |
| D06 | Opt-in native workspace and no-write record-to-task drafts | Separate P1 PR | Selected #49; global default active/blocked changes deferred |
| D07–D13 | Membership model, teams/roles/imports/handoffs and optional lifecycle templates | Deferred | Competing #47/#48 semantics require their migration ADR; no whole-PR merge |
| D14–D19 | Bearer enforcement, entry binding, maintenance and external durability/cleanup | Deferred | Preserve independent switches and Gorge/external responsibility boundaries |

The D01 workflow uses the same immutable `GORGE_CONTRACT_REF` repository
variable as existing paired runtime CI, not a moving Gorge branch. Its matrix
checks selected native packages on PHP 8.3/8.4/8.5; production remains PHP 8.3.
The local native evidence records the exact paired commit and PHP version;
results on other matrix versions must be read from those individual CI jobs.
Fixture builds use the paired Gorge commit's release build bases and retain
its strict Go-version guard. They do not reuse or change Phorge's older
production acceptance builders. The runner requires fresh per-invocation
evidence even when an output directory contains a previous passing report.

Local D01 verification used PHP 8.5.8 and actual disposable MySQL/Gorge file,
image and render services built from
`90dc361d0888a988631edec65b44f3330c889481`: 47 native methods passed, none
skipped. This includes all 24 retained project core methods, all 3 native
library checks, cold i18n checks, a modular Chinese-translation regression,
real file/avatar/image operations and a repeated native storage upgrade.
Runner result-policy regressions (14 checks) and isolation/release-inventory
Python regressions also pass. The evidence is package-specific, not a claim
that later package code or every production workload passed.

Required skips are failures. An explicitly documented optional skip remains a
skip in the result JSON. A source author's acceptance count or a declared
test-method list is not a test pass. Missing file engines, an unavailable
service or an empty result set cannot be recorded as successful regression
coverage.

For each following batch, append its PR/merge identity, actual source subset,
test evidence and any limitations without marking unrelated deferred work
complete. No retired application, repository-hosting/notification client,
PHP file writer or Gorge-owned cleanup/consumer is restored by this series.

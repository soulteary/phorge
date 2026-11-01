# Atomic contribution synchronization — 2026-11-01

## Current checkpoint (supersedes historical runtime statements below)

D01–D06 merged through #52–#57; #58 enforces clean source provenance,
#59 establishes sole PHP 8.5.12 support, #60 covers authentication navigation,
and #61 fixes notification timing. The last runtime baseline is
`5298239e0fe364046b2a60d5f76ab733a284fcd0`.
The formal paired Gorge release is `2026.11.01-r3` at
`55f8ba8e57b179a7dee92ad43c77038ca322f61c`.
Current inventory: 141 native methods and 30 HTTP assertions. The 134 atoms
and 268 design anchors describe planned scope, not executed test counts.
D01–D06 cover 42 atoms; D07–D19 retain 92 deferred atoms. Optional controls
remain native/off/false. No feature activation or permission migration is
included in this checkpoint.

The entries below retain their historical runtime and intermediate PR state.
See `atomic-required-gates-and-evidence.md` for current merge requirements and
`b04-m07-permission-decision-and-audit.md` for the next read-only design stage.

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

Actual PR CI identified two gate integration defects: Ubuntu runners did not
provide `rg`, and native ancestor-suite discovery pulled a root-level atomic
preflight into existing cluster acceptance without its dedicated services.
The workflow now installs ripgrep explicitly. The preflight moved to a
dedicated testing subtree, retaining mandatory file/avatar/schema checks and
adding native broad-versus-explicit discovery regressions. The bundled test
engine and existing runtime acceptance selections remain unchanged.
The corrected D01 suite passed all 49 native methods locally on PHP 8.5.8
with the same disposable paired services; no required method was skipped.
Sequential feature acceptance also exposed the pure-unit four-second CPU
limit in the repeated-migration preflight. Only the dedicated atomic
environment case now receives a bounded 30-second integration budget; the
native parent teardown still restores the caller's limit. A no-service hook
regression covers restoration after normal and failed bodies. No global
runtime budget or production setting changes.

Required skips are failures. An explicitly documented optional skip remains a
skip in the result JSON. A source author's acceptance count or a declared
test-method list is not a test pass. Missing file engines, an unavailable
service or an empty result set cannot be recorded as successful regression
coverage.

Post-merge verification at Phorge `246d0bdc4307d8f4c66f4b6afbb3d848a19110d1`
found a provenance defect: the PHP 8.4 CI artifact passed 134 native methods
and 30 HTTP assertions while recording `worktreeDirty: true`. Actions had
placed its paired `.test-gorge` checkout inside the Phorge worktree, and the
runners only recorded dirtiness instead of enforcing the clean-source release
requirement. The follow-up moves that owned paired checkout outside Phorge
and requires clean Phorge HEAD/status before and after both CI gates. Dirty
source, source changes during testing and stale early-refusal evidence fail
closed; local TDD remains explicitly available without a release claim.
Real temporary-Git regressions reproduce the nested-checkout issue, tracked
and untracked source changes, clean HEAD changes, stale successful reports,
and ownership-safe handling of foreign/symlink log and output paths. The
product features, PHP baseline, paired Gorge revision and production settings
are unchanged; full native/HTTP execution remains required in the follow-up CI.

The post-merge PHP 8.3 job also stalled before product tests in the ripgrep
installation step. Its live APT log repeatedly ignored
`http://azure.archive.ubuntu.com/ubuntu` indexes for Noble, updates, backports
and security while the official HTTPS archive served the fallback InRelease
successfully; a follow-up PR PHP 8.4 job showed the same setup stall. The
disposable CI step now rewrites only an existing `/etc/apt/apt-mirrors.txt`
to `https://archive.ubuntu.com/ubuntu`, bounds APT retries to two and HTTP/HTTPS
timeouts to 30 seconds, and limits the step to five minutes. Standard signed
APT checks and other repositories remain enabled. `rg --version` confirms
the installed dependency before tests begin; fresh CI, rather than a local
mirror-string test, verifies the runner setup. Production package sources
and running workflow jobs are untouched.

For each following batch, append its PR/merge identity, actual source subset,
test evidence and any limitations without marking unrelated deferred work
complete. No retired application, repository-hosting/notification client,
PHP file writer or Gorge-owned cleanup/consumer is restored by this series.

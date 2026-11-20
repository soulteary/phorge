# CI tiers and supported evidence

CI has three different acceptance boundaries. A pass at one boundary does not
stand in for another.

## Core development

Pull requests and pushes to `main` run the core tier. The stable required context
`Native packages (PHP 8.5.13)` must reach a terminal result from real core checks;
it must not wait for adapters which are disabled in native deployments. Core
checks retain source identity, policy and security contracts, native runtime
preparation, and the applicable Phorge/Gorge contracts. The existing runtime,
operations and publication security workflows remain in place.

The twelve focused adapter workflows and the expensive optional integration
branches in `atomic-feature-regressions.yml` belong to the full release tier.
Their names do not establish that they are native core: current deployments keep
the governance adapters off. A focused manual run is diagnostic evidence for its
exact commit; it cannot authorize publication or replace the full aggregate.

## Full release qualification

`container-image.yml` accepts an existing dated immutable source tag. Dispatch
must target that very tag. The event SHA, checked-out HEAD, tag commit and
publication workflow SHA must agree; selecting a tag in an input while dispatching
from a branch is refused.

Before any publication job with package/signing permissions, `release-full.yml`
calls the atomic full workflow and all twelve focused workflows enumerated in
[support-baseline.json](support-baseline.json). It has only a required
`expected_head` input, no dispatch trigger and no skip/full-toggle input. Each
callee is bound to that source. The aggregate requires every job to succeed;
failed, cancelled and skipped jobs refuse qualification. The publication job
requires this aggregate's exact qualified head. No manual focused or atomic run
can supply that output to a different publication run.

Relative reusable-workflow references select the same commit as their caller;
the called workflow receives the caller's GitHub context. See GitHub's
[reusable workflow reference](https://docs.github.com/en/actions/how-tos/reuse-automations/reuse-workflows).

This dependency does not replace the existing immutable registry tags, source
revision checks, paired Gorge revision contracts, production PHP security checks,
AMD64/ARM64 published-image checks, digest binding, attestation or Cosign signing.
An existing image publication must still pass full qualification before it can be
accepted by this workflow. A source fixture alone cannot qualify an image pair.

## Feature enablement and native trial acceptance

A full release pass preserves the optional paths; it does not turn them on or
certify a particular deployment's credentials, policies or data. Enabling an
adapter requires explicit deployment authorization and feature-specific evidence
for that deployment. Existing migration and permission boundaries still apply.

Native trial acceptance uses the ordinary login, task, attachment, notification
and business-mail paths on the qualified native deployment. It does not wait for
N09's source-operation/process experiment or for disabled adapters. Owlmail
admission belongs to Stargate; disabling a Phorge or Gorge account does not revoke
Owlmail admission. This round does not require a rollback drill as a release gate.
Previously observed isolated rollback evidence remains scoped to its actual
snapshot, images, restored database and observed actions.

Keep the actual Linux environment, image digests, source commits and test scope
with every result. Testing both released image architectures is not evidence for
two independent native deployment environments. Historical snapshots and earlier
CI results describe those snapshots, not the current deployment.

## Supported baseline and retirement

PHP 8.5.13 is the sole current production/CI baseline, with JIT disabled and a zero
JIT buffer. Historical PHP 8.3/8.4 evidence remains archival. Production MySQL is
8.4.13. MySQL 8.0 in `gorge-runtime-contracts.yml` is a test-only carrier for
historical application-schema migration fixtures. Those tests provide no evidence
or promise of a MySQL engine upgrade, or of MySQL 8.0 production support. State a
supported release-source migration path only when its specific start, target and
migration evidence are available; this policy establishes no arbitrary starting
release support. Native fresh/repeated schema upgrades and historical migration
tests remain available; this tier change removes none of their implementation
or schema checks.

Runtime fixtures provide bounded parser, ABI, source or process evidence only for
the exact inputs and environment they exercised. Formal paired release
qualification additionally binds the Phorge/Gorge sources, released component
set, immutable registry digests, architectures and signature/provenance evidence.
Neither a fixture pass nor a native browser check substitutes for those release
proofs. N09 does not become a native-trial prerequisite by sharing a source SHA.

Retire an upgrade path, fixture or adapter only after documenting the supported
replacement or explicit end of support, preserving successful supported and
repeated-upgrade evidence, checking the supported consumer inventory, and
reviewing the source/test/documentation change. Archive historical evidence.
Age, a disabled current adapter or a slower full job alone is not a retirement
condition. Do not delete upgrade paths as part of reorganizing CI triggers.

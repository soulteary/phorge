# Upgrade and rollback

This procedure complements [production cutover](../../PRODUCTION-CUTOVER.md),
[image publication](../../deploy/release/README.md), and the
[backup tools](../../scripts/operations/README.md). It does not change their gates.

The [component catalog](2026.11.19-r3-components.json) records the published
Phorge/Gorge pair used in the [upgrade observation](2026.11.19-upgrade-acceptance.json).
It covers ten deployed components, not every Gorge candidate. Supporting-service
versions are observations, not a tested compatibility range or an instruction to
enable them. This document does not change Compose defaults.

The [read-only inventory utility](../../scripts/operations/README.md#read-only-upgrade-inventory)
can collect bounded observations and compare image/feature settings. It never
performs an upgrade or marks the external acceptance gates complete.

## Before changing services

1. Define the application and infrastructure scope, interruption window, owner,
   and rollback decision point. Record applications whose image must remain
   unchanged. Pin their current immutable image references; do not pull a mutable
   branch tag during an infrastructure restart. Preserve existing package holds
   unless their owner explicitly approves a change.
2. Keep deployment configuration, account directories, credentials, raw container
   inspection, SQL dumps and mail outside the repository. Use restricted storage
   and the deployment's encryption and retention policy. Public records contain
   software identities, check outcomes and unresolved gates only.
3. Resolve the target release through its public manifest. Check manifest hashes,
   the immutable index, target platform, actual image source/version labels,
   exact-workflow Cosign identity and GitHub provenance. A release author's
   verification claim is distinct from the operator's independent verification.
   Authentication failures, timeouts and unavailable registries remain failures;
   do not disable TLS, substitute an unverified mirror, or call an old image current.
4. Review release-specific migration notes before crossing versions. Check ports,
   probes, callback handling, proxy trust, retired settings and runtime UID/GID.
   Do not reuse demonstration passwords. Preserve credential-encryption keys and
   Redis state; an empty-store key correction is not a general rotation procedure.
5. Back up configuration and every persistent dependency, including database grants
   where required, files, Redis persistence, mail and external stores. Establish
   write quiescence and consistency boundaries for each backup. A live file copy
   is not proof of a coordinated database/file recovery point. The existing backup
   tool rejects unsupported topologies, including Redis and custom writable binds;
   use a reviewed topology-specific procedure rather than weakening that check.
6. Restore copies with the same database image into disposable environments with
   no public ports. Restrict or disable outbound delivery. Preserve file ownership
   and mode as well as content. Exercise the target Phorge/Gorge schema path on
   that copy. A successful SQL import does not establish business or file integrity.

Record whether each gate passed, failed, was blocked, or was not executed. Missing
evidence is not a pass. Stop if the backup scope, source identity or required
rehearsal is incomplete.

## Upgrade sequence

1. Preserve old images, manifests, configuration and backup checksums. Prepare the
   complete target configuration before stopping traffic. Keep the four native
   permission axes unchanged and optional governance features disabled.
2. For each authentication or mail service, stop ingress and the old writer before
   the final data snapshot. Test the new version on a copy first. Never run two
   writers against one mail/control directory. Update probes with the service;
   a deprecated probe is not a substitute for its documented readiness contract.
3. Drain application traffic and stop every relevant writer before the final
   application backup. Include workers, schedulers, CLI jobs and external writers.
   Keep database access available for the reviewed schema step. Do not migrate
   permission semantics or activate optional features as part of that step.
4. Apply only the schema/configuration steps rehearsed on the copy. Start matching
   Gorge dependencies, Phorge web and daemon services in their declared dependency
   order, using immutable image references and no implicit rebuild or pull.
5. Review the host package transaction before applying it. Retain a bootable old
   kernel. If a restart is required, capture the expected running-service set first
   and check it again after reboot. Do not upgrade a database major version merely
   because the host package transaction succeeded.
6. Check actual running images and source revisions, readiness, preserved
   application images and runtime feature settings. Verify HTTPS with normal
   certificate validation. Treat redirects as entry checks, not authenticated
   application acceptance.
7. Repeat real-provider OTP login, account switching, disablement and logout with
   approved test identities. Check private attachments, notifications and the mail
   chain separately. Keep candidate media-identity acceptance distinct from native
   attachment behavior while its switches are off. A synthetic SMTP capture does
   not prove real-provider delivery.

Keep a failed or unexecuted gate open even when services are healthy. The dated
record retains database/Redis image verification and fresh real OTP login as open
gates. It does not qualify a sensitive-data rollout or production load.

## Rollback

1. Stop traffic and writers. Preserve new logs, captures and pending outbox/queue
   state before reverting anything. Do not replay unknown delivery outcomes or
   combine database, file and queue state from unrelated recovery points.
2. Check the release's reverse-compatibility rules. If the old binary cannot read
   the new state, restore the complete pre-upgrade recovery point before starting
   it. Reverting an image alone does not undo schema or data changes. Do not claim
   a failed upgrade was rolled back merely because old containers start.
3. Restore matching configuration, service images and proxy/probe contracts.
   Keep authentication replicas on a compatible session-exchange protocol. When
   sessions or tickets are incompatible, require a fresh login rather than mixing
   old and new replicas or sharing broader cookies.
4. Verify the restored database, files, queue ownership, mail ledger and required
   external state. Repeat readiness and business checks, then resume traffic under
   the deployment's approval process. Stop if state cannot be reconciled.
5. Remove only resources created for the rehearsal, after checking ownership and
   consumers. Keep rollback images, private evidence and backups for their agreed
   retention period. Revert temporary client configuration separately.

No RPO or RTO is established by this document. Measure both in a representative
restore rehearsal; retain any business-integrity or external-store gaps explicitly.

# Early Calendar fixture identity

`governance_calendar_wire_publish_cleanup_identity(array $identity)` publishes the
fixed private `calendar-cleanup-identity.json` role immediately after successful
owned fixture creation, before probes or service startup. The caller supplies the
credential-free identity projected from the owned fixture after `assertOwned()`:

- `namespace`, `owner_nonce`, `quickstart_sha256`;
- `writer_user`, `setup_user`, `reader_user` (names only);
- `schemas`: the sorted, unique 55 full schema names for this source fixture.

The helper rejects unknown fields, noncanonical identities, foreign schemas,
wrong parent ownership or mode, replaced/symlinked/hardlinked owner files, and
noncanonical parent paths. Existing isolated-test, parent owner and source revision
environment variables bind the publication. An exclusive 0600 temporary regular
file is flushed, fsynced and read back; a hard-link publication refuses overwrite,
then the temporary link is removed and the final bytes and inode are rechecked.
A failed publication retains evidence rather than deleting an unknown entry.

The payload scope is `owned-calendar-pre-ready-cleanup-identity-v1`, its stage is
`created-before-services`, and `cleanup_authorized` is always `false`. It contains
no DSN, password or business rows. The owner nonce remains private lineage data;
reading a matching nonce never authorizes cleanup. Parent capture is required even
when the keeper exits before `ready.json`. Independent exact-writer SQL0,
process/service terminal evidence and separately reviewed exact-owned cleanup are
still required. This role cannot recover a partial failure inside fixture creation
before publication.

Run the actual filesystem controls with:

```sh
php support/governance/notification/tests/calendar-wire-cleanup-identity-host.php
```

The host test exercises real filesystem publication, no overwrite, restrictive
permissions, environment/identity refusals and owner-file alias attacks. It does
not start SQL, SMTP, workers or a browser, and does not prove runtime cleanup.

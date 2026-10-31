# PHP 8.5.12 production baseline

PHP 8.5 is the only supported minor branch. The minimum security release and
the Docker/CI baseline are 8.5.12. Both native Web startup and CLI runtime
bootstrap reject other branches and earlier 8.5 patches. Historical release
reports mentioning 8.3/8.4 remain evidence for those releases, not a support
promise for this one.

## Runtime and packaging

- Use the locked PHP 8.5.12 Apache image and its matching Debian snapshot.
- OPcache is built into PHP 8.5; do not build it as a shared extension.
- Keep `opcache.jit=disable` and `opcache.jit_buffer_size=0` for Web, CLI,
  migrations and operations. Function JIT has reproduced SQL-formatter errors.
- Retain the existing session hardening and minimum patched-version check.
- Use the paired Gorge source and its Go 1.27.3 build lock. PHP's older Go
  acceptance builder must not silently download another toolchain.

The separate Gorge favicon fix extends its bounded compose geometry to native
76, 120 and 152 pixel touch icons. Do not silently disable an already enabled
Gorge builtin-composition mode as a substitute for that fix. PHP file writing
and retired background consumers remain disabled.

## Promotion evidence

Run the production security gate against the actual image, native packages,
workspace HTTP contracts, and the isolated paired acceptance on Linux AMD64
and ARM64. Preserve source commits, dirty state, runtime manifest, image IDs
and registry digests. No skip or absent test-result file is a pass.

Before switching an existing deployment:

1. Record its actual Compose files, source/image identities and enabled
   provider/feature configuration without exposing secrets.
2. Capture a database, configuration and file-volume backup and verify its
   restoration. Validate external search backups separately when configured.
3. Restore a representative backup in isolation. Verify authentication,
   existing sessions, private-object permissions, files and enabled project
   workflows under 8.5.12. Native fixtures are not production-data evidence.
4. Keep the previous immutable application image and matching configuration
   solely as a rollback artifact. New source must never run under PHP 8.3/8.4.
5. Switch all Web, migration and PHP CLI nodes together. Keep the database
   namespace, Gorge responsibilities and service configuration stable.
6. Check the effective Web and CLI version/INI, health, permission boundaries,
   application errors and representative traffic before recording completion.

Rolling back means restoring the previous **code/image/configuration pair**;
overriding only the PHP base image for the new source is unsupported. A code
rollback is not permission to restore an older database over new writes.

Repository defaults describe the release configuration. They do not prove that
a particular live deployment has been upgraded; record that operation's own
backup, restore, health and runtime evidence.

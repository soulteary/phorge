# PHP 8.5.13 security baseline

PHP 8.5.13 replaces 8.5.12 as the minimum supported security release and the
exact CI and container acceptance version. Earlier PHP releases and prerelease
builds must fail admission. The supported minor branch remains 8.5.

The official Apache image index is pinned to
`sha256:0a785eab8b7eb4836f9c6093771c8ea15ff8ba7269444f3da70a1d33d15fe664`.
Package installation uses the `20261120T000000Z` Debian snapshot. JIT remains
disabled. Web, CLI, test images, receipt validators and optional XLSX test
platform metadata use the same current version.

Old runtime configuration and successful 8.5.12 receipts are superseded. They
must not be used to qualify this release or selected as a rollback runtime.
Historical audit records retain their actual version and result; they are not
rewritten to claim an 8.5.13 test run.

Before deployment, build the pinned image, verify the actual PHP version and
JIT settings, complete the required CI and application regressions, and test
backup restoration. Recovery must use an image meeting the current security
floor. This change does not deploy services or migrate application permissions.

Local validation: the locked image builds; its CLI reports 8.5.13 with JIT
disabled, rejects 8.5.12, and passes all 276 native governance unit methods.
Full exact-head CI remains required before merge.

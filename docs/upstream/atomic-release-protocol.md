# Atomic package release and rollback protocol

The P0 gate fixes how selected functionality is verified; it does not change
project membership, ancestor visibility, role interpretation, file ownership,
or production configuration. Ordinary projects keep the current main
semantics until their separately reviewed migration is implemented. Milestone
inheritance remains native. Gorge continues to own file writes, image
computation and the previously transferred consumers/cleanup responsibilities.

## Reader, schema, then writer

1. Record the actual main commit, selected source PR heads, package atoms,
   paired Gorge commit and test evidence. Build native maps from this tree,
   never from an upstream generated map.
2. Inventory every web instance and worker, including stopped instances which
   may otherwise restart from an old image. Record immutable image/source
   revisions and the data/strategy reader contract each supports. Establish a
   complete `expectedNodeIds` list from the deployment's authoritative inventory.
3. Deploy schema registration and readers/authorization interpreters before
   enabling a new writer. Run fresh and repeated native upgrades in the
   disposable environment. Expected surplus quickstart tables from retired
   applications do not justify ignoring missing columns, bad types or failed
   migrations.
4. Drain and stop incompatible instances/workers. Verify their in-flight work
   has finished and the scheduler cannot restart their old images. A stale
   serving reader or worker blocks writer activation even if every web page
   looks correct.
5. Validate the observed inventory with
   `python3 tests/atomic/check_release.py /path/to/release-inventory.json`.
   Enable a package's explicitly implemented writer control only after both
   this preflight and that package's actual API/transaction guards pass.
   UI exposure alone is never a writer guard.
6. Record the activation and monitor failures. Degrade to the package's
   supported read-only state to stop new writes, preserving existing object
   policies, transaction types, stable references and current authorization.

The inventory checker validates supplied facts; it does not discover a fleet,
authenticate an operator, mutate configuration or prove that a stopped process
is really stopped. Deployment automation must obtain the complete current
inventory and observe quiescence. Reader/schema contract integers belong to
the reviewed package deployment contract, not an invented global Phorge
runtime flag. This P0 PR adds no global feature enablement switch.

Example inventory for a package introducing reader/schema contract 2:

```json
{
  "schemaVersion": 1,
  "targetReaderVersion": 2,
  "targetSchemaVersion": 2,
  "appliedSchemaVersion": 2,
  "enableWrites": true,
  "expectedNodeIds": ["web-a", "worker-a"],
  "nodes": [
    {"id": "web-a", "role": "web", "state": "serving", "revision": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa", "readerVersion": 2},
    {"id": "worker-a", "role": "worker", "state": "serving", "revision": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb", "readerVersion": 2}
  ]
}
```

Replace example revisions and versions with observed reviewed artifacts.
The checker rejects unknown formats, incomplete or duplicate inventories,
nonimmutable revisions, an unapplied schema, and incompatible serving readers
or workers. A disabled-writer inventory can still be validated while readers
or schema are being upgraded.

## Rollback

Stop writers and drain in-flight work first. Determine whether new records,
role maps, policies or indexes have been written. Returning only an application
image may leave data whose meaning differs from the old reader. Restore
reviewed paired code, database/index snapshots and Gorge contracts together,
or retain compatible readers while stopping writes. Do not guess which old
membership edges were automatic invitations or delete them during rollback.

Pure display controls may return to the native display without deleting data.
Disabling an extra file/entry restriction can weaken the constraint and needs
its own explicit deployment procedure; it is not a generic safety rollback.
External completed account changes need durable audit/recovery procedures,
not a promise of distributed transaction rollback.

## Acceptance evidence

The native package runner rejects missing dependency/discovery evidence and
undocumented skips. Keep the exact package manifest, full Phorge/Gorge
identities, PHP version and result JSON with each PR. PHP 8.5.12 is the sole
CI and production baseline; historical 8.3/8.4 results are archival evidence.
JIT must be explicitly disabled, with a zero JIT buffer.
Cold i18n validation and explicit en_US/zh_CN feature assertions are both
required: a syntactically valid translation does not prove a product's labels
are localized, and localization must not alter persisted keys.

中文说明：先部署读取和权限解释器，再升级结构，最后启用新写入；旧实例或后台
worker 仍在处理请求时不得开放不兼容的新语义。降级时先停写，保留旧记录与策略
解释，再按配对的代码、数据库、索引及 Gorge 版本恢复，不能用一次配置切换代替迁移。

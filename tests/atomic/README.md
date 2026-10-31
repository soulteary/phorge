# Atomic feature regression gates

This runner verifies the native PHP code in the current checkout. It uses the
maintained compatibility runtime, actual MySQL storage fixtures, and the
matching Gorge file, image and render services. It does not restore Arcanist clients,
retired applications, a PHP file-writing backend, or background consumers.

## Run

Use a fresh test worktree and disposable loopback services. The test MySQL
account needs permission to create and drop the random native fixture
databases. Gorge file storage must use disposable data, such as tmpfs. Never
point these variables at a deployed service, even through a local tunnel.

```sh
php scripts/runtime/build.php all
php bin/rebuild-library-map
php tests/atomic/test_gate.php
PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s tests/atomic -p 'test_*.py' -v

ATOMIC_TEST_DISPOSABLE=1 \
GORGE_CONTRACT_REF=<matching-40-character-Gorge-commit> \
GORGE_TEST_MYSQL_PORT=3307 \
GORGE_TEST_MYSQL_PASSWORD=disposable-only \
GORGE_TEST_FILE_URL=http://127.0.0.1:18100 \
GORGE_TEST_FILE_TOKEN=disposable-file-only \
GORGE_TEST_IMAGE_URL=http://127.0.0.1:18190 \
GORGE_TEST_IMAGE_TOKEN=disposable-image-only \
GORGE_TEST_RENDER_URL=http://127.0.0.1:18140 \
GORGE_TEST_RENDER_TOKEN=disposable-render-only \
python3 tests/atomic/run.py --output /tmp/phorge-atomic-evidence
```

Omitting `--package` runs every registered package. Repeat `--package D01
--package D02` for a focused selection. Include D01 when running a focused
feature package so dependency round trips and the retained core regressions
remain mandatory. `php tests/atomic/run.php --package D01 --list` only lists
discovered methods; it does not execute storage tests or claim a pass.

The orchestrator refuses an existing `conf/local/local.json`,
`conf/local/deployment.json`, `conf/local/ENVIRONMENT`, or `PHABRICATOR_ENV`.
It exclusively creates a private test configuration and removes that exact
file in `finally`. If another process changes the file, it preserves the
change and fails. Native fixture guards create their own random namespaces,
run native migrations and drop those databases at PHP process shutdown.
The surrounding Docker job removes the disposable Gorge data; transaction
rollback alone cannot clean up bytes already written to a service.

The mandatory dependency preflight lives under
`src/infrastructure/testing/atomic/__tests__/`, rather than root
`src/__tests__/`. Native path-based selection includes ancestor test
directories: placing the preflight at the root would accidentally require its
disposable services in existing cluster/runtime acceptance jobs. Discovery
regressions verify that broad cluster selection still includes ordinary
library checks, excludes the atomic-only preflight, and that explicit atomic
selection still discovers the required fixture. No dependency is skipped or
removed from the D01 gate.

## Package contract

Each package owns `tests/atomic/packages/DNN.json`:

```json
{
  "schemaVersion": 1,
  "id": "D02",
  "atoms": ["F01"],
  "tests": [{
    "class": "ExampleTestCase",
    "path": "src/applications/example/__tests__/ExampleTestCase.php",
    "methods": ["testExpectedVisibility", "testDeniedVisibility"]
  }],
  "allowedSkips": {}
}
```

Paths, map-discovered class identity, nonempty required methods, and actual
native results are checked. Required methods cannot disappear silently.
All current `test*` methods of each selected class, including inherited
methods and newly added methods, must execute exactly once; additions are
never hidden by an old count. A failure, broken test, empty selection,
missing method/result, duplicate result, or undocumented skip fails the gate.
An exceptional optional skip needs an exact `Class::method` entry and a
reason, and remains `skip` in the evidence. It is not a pass.

`native-results.json` records exact methods, statuses, durations, package
manifest hashes, checkout identity and the paired Gorge revision. Dirty trees
are marked explicitly. `native.log` and result details redact configured test
passwords/tokens. Early configuration/parser failures may precede native
result creation and fail CI directly; absence of evidence never means pass.
Each native invocation writes to a fresh private report path. Reusing an
output directory first invalidates its previous published report, so an early
successful process exit cannot reuse or relabel an earlier checkout's pass.

## CI and release scope

`.github/workflows/atomic-feature-regressions.yml` runs on every PR and main
push. It builds all three actual services from `GORGE_CONTRACT_REF`, reuses the
paired Gorge commit's `deploy/release/build-bases.json`, checks map
reproducibility/runtime integrity and retired references, and runs all
registered package suites. The fixture builder requires an exact clean Gorge
checkout, reads that commit's build-base lock, and uses Gorge's own validator
to require immutable multi-platform builder/runtime digests. Phorge's
production acceptance lock is unchanged; it must not substitute an older Go
builder for Gorge's required toolchain. Missing paired source, build-base lock
or unavailable dependencies fail instead of skipping.

PHP 8.3 remains the production baseline. PHP 8.4/8.5 are compatibility checks
for these selected native packages; they do not establish complete production
or migration support on those runtimes.

See [release protocol](../../docs/upstream/atomic-release-protocol.md) before
activating new writers across multiple web/worker instances.

## 中文运行提示

- 必须使用全新的测试工作树、临时 MySQL 及与固定提交配对的 Gorge 文件和图片服务。
- 出现“已有实例配置”时应换测试工作树，不要删除或覆盖已有配置后强行运行。
- 头像 fixture 写入失败表示依赖未就绪；不能跳过用例、恢复 PHP 文件写入后端，
  也不能把依赖异常当作权限缺陷或通过结果。
- 本工具保留实际执行状态；缺少结果、失败和未说明的跳过都会阻止通过。
- 关闭业务入口仍须保留存量策略/类型的解释和当前权限校验；新写能力需按发布协议启用。

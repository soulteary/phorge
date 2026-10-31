# D05: project records

- Source: `soulteary/phorge#49`, head
  `4051759aedb701c46b0b859185d08b6873fbd066`.
- Implementation baseline: main
  `a6d18ba72c805bc54e703ba2c02a53d5ba3d2290`.
- Included atoms: H01–H08, H13, H14.
- Adaptations: default-off publication with persistent readers; fresh account
  state at service and native-editor boundaries; English `pht()` sources and
  project-scoped Chinese translations; additional regression cases.
- Deferred: D06 workspace/drafts; D12 organization/role combinations; D13
  global task statuses; organization/member-model changes from PRs #47/#48;
  optional identity and maintenance integrations.
- Ownership: native project records/permissions stay in Phorge. Existing
  Gorge file/image, worker, and cleanup ownership remains unchanged.

Validation results and the final PR/commit identity are recorded by the
integration batch after the exact package gate runs. Author-reported upstream
acceptance is not treated as independently reproduced validation.

The final package gate covers 49 D01 foundation methods and 24 D05 record
methods (73 total), with no allowed skips. Run it on a clean tree with
`python3 tests/atomic/run.py --package D01 --package D05 --output <new-directory>`.
The JSON evidence records the exact tested commit and worktree state; an older
report or an unregistered test method cannot satisfy this gate.

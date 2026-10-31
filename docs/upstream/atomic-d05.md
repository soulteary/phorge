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

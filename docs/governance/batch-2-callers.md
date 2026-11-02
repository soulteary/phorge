# Batch 2: partial source and caller inventory

Baseline: `4f36f58a5ed658c7d39bf0a58e67f5be5ca2fb0b`. Selected ADR v1.2 branches are pinned. All runtime activation remains blocked. This inventory adds a conservative drift alarm and explicit graph, not native behavioral certification.

## Stable contracts

Consumer IDs identify source entries, not permission grants. Operation IDs match the isolated registry vocabulary; source IDs are proposed read-set categories, not authenticated current state.

Operations: `account.enable`, `object.relationship`, `object.space`, `policy.replace`, `project.description`, `project.members`, `task.description`, `task.title`, `wiki.content`, `wiki.title`.

Read-set categories: `app.capability`, `auth.session`, `org.appointment`, `org.membership`, `policy.named`, `project.member`, `project.parent`, `project.policy`, `space.policy`, `user.account`.

## Important source distinctions

- `PhrictionEditEngineController` is comments-only in this baseline. Wiki body edit still has a legacy web controller and legacy API; a single edit-engine route is not complete Wiki coverage.
- `PhorgeProjectLegacyMembersTransaction` is historical rendering, not proof of a current membership write route.
- Project forward and inverse edges reference each other; every external edge caller is not yet certified.
- Generic shared editor combines builtin policy/edge branches with modular transaction type dispatch. A file list alone does not prove all per-object policy checks.
- Account enable includes Web, `user.edit`, management CLI/base, engine, editor and user.disable transaction. Direct SQL/unsupported scripts and external identity behavior remain explicit blockers.
- Task bulk Web, bulk engine, edit engine and worker candidates are separate; queued execution is not treated as identical to an interactive request.

## Source-linked consumers

- `PROJECT.EDIT.WEB`: [src/applications/project/controller/PhabricatorProjectEditController.php](../../src/applications/project/controller/PhabricatorProjectEditController.php), `PhabricatorProjectEditController::handleRequest`; kind=web; partial/static-reference-only.
- `PROJECT.EDIT.ENGINE`: [src/applications/project/engine/PhabricatorProjectEditEngine.php](../../src/applications/project/engine/PhabricatorProjectEditEngine.php), `PhabricatorProjectEditEngine`; kind=engine; partial/static-reference-only.
- `PROJECT.EDIT.CONDUIT`: [src/applications/project/conduit/ProjectEditConduitAPIMethod.php](../../src/applications/project/conduit/ProjectEditConduitAPIMethod.php), `ProjectEditConduitAPIMethod::newEditEngine`; kind=API; partial/static-reference-only.
- `PROJECT.CREATE.CONDUIT.LEGACY`: [src/applications/project/conduit/ProjectCreateConduitAPIMethod.php](../../src/applications/project/conduit/ProjectCreateConduitAPIMethod.php), `ProjectCreateConduitAPIMethod`; kind=legacy-API; partial/static-reference-only.
- `PROJECT.MEMBER.ADD`: [src/applications/project/controller/PhabricatorProjectMembersAddController.php](../../src/applications/project/controller/PhabricatorProjectMembersAddController.php), `PhabricatorProjectMembersAddController::handleRequest`; kind=web; partial/static-reference-only.
- `PROJECT.MEMBER.REMOVE`: [src/applications/project/controller/PhabricatorProjectMembersRemoveController.php](../../src/applications/project/controller/PhabricatorProjectMembersRemoveController.php), `PhabricatorProjectMembersRemoveController::handleRequest`; kind=web; partial/static-reference-only.
- `PROJECT.MEMBER.EDITOR`: [src/applications/project/editor/PhabricatorProjectTransactionEditor.php](../../src/applications/project/editor/PhabricatorProjectTransactionEditor.php), `PhabricatorProjectTransactionEditor`; kind=transaction-editor; partial/static-reference-only.
- `PROJECT.MEMBER.INVERSE`: [src/infrastructure/edges/editor/PhabricatorEdgeEditor.php](../../src/infrastructure/edges/editor/PhabricatorEdgeEditor.php), `PhabricatorEdgeEditor::buildEdgeSpecs`; kind=shared-edge-writer; partial/static-reference-only.
- `PROJECT.MEMBER.EDGE.FORWARD`: [src/applications/project/edge/PhabricatorProjectProjectHasMemberEdgeType.php](../../src/applications/project/edge/PhabricatorProjectProjectHasMemberEdgeType.php), `PhabricatorProjectProjectHasMemberEdgeType::getInverseEdgeConstant`; kind=edge-definition; partial/static-reference-only.
- `PROJECT.MEMBER.EDGE.REVERSE`: [src/applications/project/edge/PhabricatorProjectMemberOfProjectEdgeType.php](../../src/applications/project/edge/PhabricatorProjectMemberOfProjectEdgeType.php), `PhabricatorProjectMemberOfProjectEdgeType::getInverseEdgeConstant`; kind=edge-definition; partial/static-reference-only.
- `PROJECT.MEMBER.LEGACY`: [src/applications/project/xaction/PhorgeProjectLegacyMembersTransaction.php](../../src/applications/project/xaction/PhorgeProjectLegacyMembersTransaction.php), `PhorgeProjectLegacyMembersTransaction::getTitle`; kind=historical-interpreter; partial/static-reference-only.
- `PROJECT.POLICY.SOURCE`: [src/applications/project/storage/PhabricatorProject.php](../../src/applications/project/storage/PhabricatorProject.php), `PhabricatorProject`; kind=policy-source; partial/static-reference-only.
- `TASK.EDIT.WEB`: [src/applications/maniphest/controller/ManiphestTaskEditController.php](../../src/applications/maniphest/controller/ManiphestTaskEditController.php), `ManiphestTaskEditController::handleRequest`; kind=web; partial/static-reference-only.
- `TASK.EDIT.ENGINE`: [src/applications/maniphest/editor/ManiphestEditEngine.php](../../src/applications/maniphest/editor/ManiphestEditEngine.php), `ManiphestEditEngine`; kind=engine; partial/static-reference-only.
- `TASK.EDIT.EDITOR`: [src/applications/maniphest/editor/ManiphestTransactionEditor.php](../../src/applications/maniphest/editor/ManiphestTransactionEditor.php), `ManiphestTransactionEditor`; kind=transaction-editor; partial/static-reference-only.
- `TASK.EDIT.CONDUIT`: [src/applications/maniphest/conduit/ManiphestEditConduitAPIMethod.php](../../src/applications/maniphest/conduit/ManiphestEditConduitAPIMethod.php), `ManiphestEditConduitAPIMethod::newEditEngine`; kind=API; partial/static-reference-only.
- `TASK.EDIT.CONDUIT.LEGACY`: [src/applications/maniphest/conduit/ManiphestUpdateConduitAPIMethod.php](../../src/applications/maniphest/conduit/ManiphestUpdateConduitAPIMethod.php), `ManiphestUpdateConduitAPIMethod`; kind=legacy-API; partial/static-reference-only.
- `TASK.EDIT.CONDUIT.LEGACY.BASE`: [src/applications/maniphest/conduit/ManiphestConduitAPIMethod.php](../../src/applications/maniphest/conduit/ManiphestConduitAPIMethod.php), `ManiphestConduitAPIMethod`; kind=legacy-API-shared; partial/static-reference-only.
- `TASK.EDIT.BULK`: [src/applications/maniphest/controller/ManiphestBulkEditController.php](../../src/applications/maniphest/controller/ManiphestBulkEditController.php), `ManiphestBulkEditController::handleRequest`; kind=bulk-web; partial/static-reference-only.
- `TASK.BULK.ENGINE`: [src/applications/maniphest/bulk/ManiphestTaskBulkEngine.php](../../src/applications/maniphest/bulk/ManiphestTaskBulkEngine.php), `ManiphestTaskBulkEngine::newEditEngine`; kind=bulk-engine; partial/static-reference-only.
- `TASK.TITLE.MODULAR`: [src/applications/maniphest/xaction/ManiphestTaskTitleTransaction.php](../../src/applications/maniphest/xaction/ManiphestTaskTitleTransaction.php), `ManiphestTaskTitleTransaction`; kind=modular-type; partial/static-reference-only.
- `TASK.DESCRIPTION.MODULAR`: [src/applications/maniphest/xaction/ManiphestTaskDescriptionTransaction.php](../../src/applications/maniphest/xaction/ManiphestTaskDescriptionTransaction.php), `ManiphestTaskDescriptionTransaction`; kind=modular-type; partial/static-reference-only.
- `WIKI.EDIT.WEB.LEGACY`: [src/applications/phriction/controller/PhrictionEditController.php](../../src/applications/phriction/controller/PhrictionEditController.php), `PhrictionEditController::handleRequest`; kind=web; partial/static-reference-only.
- `WIKI.COMMENT.ENGINE`: [src/applications/phriction/controller/PhrictionEditEngineController.php](../../src/applications/phriction/controller/PhrictionEditEngineController.php), `PhrictionEditEngineController::handleRequest`; kind=comments-only-web; partial/static-reference-only.
- `WIKI.EDIT.ENGINE`: [src/applications/phriction/editor/PhrictionDocumentEditEngine.php](../../src/applications/phriction/editor/PhrictionDocumentEditEngine.php), `PhrictionDocumentEditEngine`; kind=engine; partial/static-reference-only.
- `WIKI.EDIT.EDITOR`: [src/applications/phriction/editor/PhrictionTransactionEditor.php](../../src/applications/phriction/editor/PhrictionTransactionEditor.php), `PhrictionTransactionEditor`; kind=transaction-editor; partial/static-reference-only.
- `WIKI.EDIT.CONDUIT`: [src/applications/phriction/conduit/PhrictionDocumentEditConduitAPIMethod.php](../../src/applications/phriction/conduit/PhrictionDocumentEditConduitAPIMethod.php), `PhrictionDocumentEditConduitAPIMethod::newEditEngine`; kind=API; partial/static-reference-only.
- `WIKI.EDIT.CONDUIT.LEGACY`: [src/applications/phriction/conduit/PhrictionEditConduitAPIMethod.php](../../src/applications/phriction/conduit/PhrictionEditConduitAPIMethod.php), `PhrictionEditConduitAPIMethod`; kind=legacy-API; partial/static-reference-only.
- `WIKI.CREATE.CONDUIT.LEGACY`: [src/applications/phriction/conduit/PhrictionCreateConduitAPIMethod.php](../../src/applications/phriction/conduit/PhrictionCreateConduitAPIMethod.php), `PhrictionCreateConduitAPIMethod`; kind=legacy-API; partial/static-reference-only.
- `WIKI.TITLE.MODULAR`: [src/applications/phriction/xaction/PhrictionDocumentTitleTransaction.php](../../src/applications/phriction/xaction/PhrictionDocumentTitleTransaction.php), `PhrictionDocumentTitleTransaction`; kind=modular-type; partial/static-reference-only.
- `WIKI.CONTENT.MODULAR`: [src/applications/phriction/xaction/PhrictionDocumentContentTransaction.php](../../src/applications/phriction/xaction/PhrictionDocumentContentTransaction.php), `PhrictionDocumentContentTransaction`; kind=modular-type; partial/static-reference-only.
- `ACCOUNT.ENABLE.WEB`: [src/applications/people/controller/PhabricatorPeopleDisableController.php](../../src/applications/people/controller/PhabricatorPeopleDisableController.php), `PhabricatorPeopleDisableController::handleRequest`; kind=web; partial/static-reference-only.
- `ACCOUNT.ENABLE.API`: [src/applications/people/conduit/UserEditConduitAPIMethod.php](../../src/applications/people/conduit/UserEditConduitAPIMethod.php), `UserEditConduitAPIMethod::newEditEngine`; kind=API; partial/static-reference-only.
- `ACCOUNT.ENABLE.CLI`: [src/applications/people/management/PhabricatorPeopleManagementEnableWorkflow.php](../../src/applications/people/management/PhabricatorPeopleManagementEnableWorkflow.php), `PhabricatorPeopleManagementEnableWorkflow::execute`; kind=CLI; partial/static-reference-only.
- `ACCOUNT.ENABLE.CLI.BASE`: [src/applications/people/management/PhabricatorPeopleManagementWorkflow.php](../../src/applications/people/management/PhabricatorPeopleManagementWorkflow.php), `PhabricatorPeopleManagementWorkflow::applyTransactions`; kind=CLI-shared; partial/static-reference-only.
- `ACCOUNT.ENABLE.ENGINE`: [src/applications/people/editor/PhabricatorUserEditEngine.php](../../src/applications/people/editor/PhabricatorUserEditEngine.php), `PhabricatorUserEditEngine`; kind=engine; partial/static-reference-only.
- `ACCOUNT.ENABLE.EDITOR`: [src/applications/people/editor/PhabricatorUserTransactionEditor.php](../../src/applications/people/editor/PhabricatorUserTransactionEditor.php), `PhabricatorUserTransactionEditor`; kind=transaction-editor; partial/static-reference-only.
- `ACCOUNT.ENABLE.TRANSACTION`: [src/applications/people/xaction/PhabricatorUserDisableTransaction.php](../../src/applications/people/xaction/PhabricatorUserDisableTransaction.php), `PhabricatorUserDisableTransaction`; kind=modular-type; partial/static-reference-only.
- `TRANSACTION.MODULAR`: [src/applications/meta/xactions/PhabricatorApplicationTransactionType.php](../../src/applications/meta/xactions/PhabricatorApplicationTransactionType.php), `PhabricatorApplicationTransactionType`; kind=modular-base; partial/static-reference-only.
- `TRANSACTION.LEGACY`: [src/applications/transactions/editor/PhabricatorApplicationTransactionEditor.php](../../src/applications/transactions/editor/PhabricatorApplicationTransactionEditor.php), `PhabricatorApplicationTransactionEditor`; kind=shared-editor; partial/static-reference-only.
- `TRANSACTION.EDIT.ENGINE`: [src/applications/transactions/editengine/PhabricatorEditEngine.php](../../src/applications/transactions/editengine/PhabricatorEditEngine.php), `PhabricatorEditEngine`; kind=shared-dispatch; partial/static-reference-only.
- `TRANSACTION.EDIT.API`: [src/applications/transactions/editengine/PhabricatorEditEngineAPIMethod.php](../../src/applications/transactions/editengine/PhabricatorEditEngineAPIMethod.php), `PhabricatorEditEngineAPIMethod`; kind=shared-API-dispatch; partial/static-reference-only.
- `TRANSACTION.BULK.ENGINE`: [src/applications/transactions/bulk/PhabricatorBulkEngine.php](../../src/applications/transactions/bulk/PhabricatorBulkEngine.php), `PhabricatorBulkEngine`; kind=bulk-shared; partial/static-reference-only.
- `TRANSACTION.BULK.WORKER`: [src/infrastructure/daemon/workers/bulk/PhabricatorWorkerBulkJobTaskWorker.php](../../src/infrastructure/daemon/workers/bulk/PhabricatorWorkerBulkJobTaskWorker.php), `PhabricatorWorkerBulkJobTaskWorker`; kind=bulk-worker; partial/static-reference-only.

## Associated graph

- `LINK-001`: `PROJECT.EDIT.WEB` → `PROJECT.EDIT.ENGINE` (explicit-source-reference). handleRequest constructs the project edit engine. Runtime not verified.
- `LINK-002`: `PROJECT.EDIT.CONDUIT` → `PROJECT.EDIT.ENGINE` (explicit-source-reference). newEditEngine explicitly returns the project engine. Runtime not verified.
- `LINK-003`: `PROJECT.CREATE.CONDUIT.LEGACY` → `PROJECT.MEMBER.EDITOR` (explicit-source-reference). Legacy create constructs the project transaction editor. Runtime not verified.
- `LINK-004`: `PROJECT.MEMBER.ADD` → `PROJECT.MEMBER.EDITOR` (explicit-source-reference). handleRequest creates TYPE_EDGE and project editor. Runtime not verified.
- `LINK-005`: `PROJECT.MEMBER.REMOVE` → `PROJECT.MEMBER.EDITOR` (explicit-source-reference). handleRequest creates TYPE_EDGE and project editor. Runtime not verified.
- `LINK-006`: `PROJECT.MEMBER.EDGE.FORWARD` → `PROJECT.MEMBER.EDGE.REVERSE` (explicit-source-reference). getInverseEdgeConstant references reverse member edge. Runtime not verified.
- `LINK-007`: `PROJECT.MEMBER.EDGE.REVERSE` → `PROJECT.MEMBER.EDGE.FORWARD` (explicit-source-reference). getInverseEdgeConstant references forward member edge. Runtime not verified.
- `LINK-008`: `TASK.EDIT.WEB` → `TASK.EDIT.ENGINE` (explicit-source-reference). handleRequest explicitly constructs ManiphestEditEngine. Runtime not verified.
- `LINK-009`: `TASK.EDIT.CONDUIT` → `TASK.EDIT.ENGINE` (explicit-source-reference). newEditEngine explicitly returns ManiphestEditEngine. Runtime not verified.
- `LINK-010`: `TASK.EDIT.CONDUIT.LEGACY.BASE` → `TASK.EDIT.EDITOR` (explicit-source-reference). Legacy shared apply path constructs ManiphestTransactionEditor. Runtime not verified.
- `LINK-011`: `TASK.EDIT.BULK` → `TASK.BULK.ENGINE` (explicit-source-reference). handleRequest explicitly constructs ManiphestTaskBulkEngine. Runtime not verified.
- `LINK-012`: `TASK.BULK.ENGINE` → `TASK.EDIT.ENGINE` (explicit-source-reference). newEditEngine explicitly returns ManiphestEditEngine. Runtime not verified.
- `LINK-013`: `WIKI.EDIT.WEB.LEGACY` → `WIKI.EDIT.EDITOR` (explicit-source-reference). Legacy body edit explicitly constructs PhrictionTransactionEditor. Runtime not verified.
- `LINK-014`: `WIKI.EDIT.CONDUIT.LEGACY` → `WIKI.EDIT.EDITOR` (explicit-source-reference). Legacy API explicitly constructs PhrictionTransactionEditor. Runtime not verified.
- `LINK-015`: `WIKI.CREATE.CONDUIT.LEGACY` → `WIKI.EDIT.EDITOR` (explicit-source-reference). Legacy create explicitly constructs PhrictionTransactionEditor. Runtime not verified.
- `LINK-016`: `WIKI.COMMENT.ENGINE` → `WIKI.EDIT.ENGINE` (explicit-source-reference). This controller explicitly constructs engine but comments state only comments are handled. Runtime not verified.
- `LINK-017`: `WIKI.EDIT.CONDUIT` → `WIKI.EDIT.ENGINE` (explicit-source-reference). Document API explicitly returns PhrictionDocumentEditEngine; does not prove legacy body coverage. Runtime not verified.
- `LINK-018`: `ACCOUNT.ENABLE.WEB` → `ACCOUNT.ENABLE.EDITOR` (explicit-source-reference). Web disable/enable controller explicitly applies user transactions. Runtime not verified.
- `LINK-019`: `ACCOUNT.ENABLE.CLI` → `ACCOUNT.ENABLE.TRANSACTION` (explicit-source-reference). Enable workflow explicitly creates user.disable transaction with false value. Runtime not verified.
- `LINK-020`: `ACCOUNT.ENABLE.CLI.BASE` → `ACCOUNT.ENABLE.EDITOR` (explicit-source-reference). Management base obtains user application editor then applies transactions. Runtime not verified.
- `LINK-021`: `ACCOUNT.ENABLE.API` → `ACCOUNT.ENABLE.ENGINE` (explicit-source-reference). user.edit explicitly returns PhabricatorUserEditEngine. Runtime not verified.
- `LINK-022`: `PROJECT.EDIT.ENGINE` → `TRANSACTION.EDIT.ENGINE` (inherited-dispatch-candidate). Project engine inherits shared edit dispatch; target/editor and all hooks still need behavior coverage. Runtime not verified.
- `LINK-023`: `TASK.EDIT.ENGINE` → `TRANSACTION.EDIT.ENGINE` (inherited-dispatch-candidate). Task engine inherits shared edit dispatch. Runtime not verified.
- `LINK-024`: `WIKI.EDIT.ENGINE` → `TRANSACTION.EDIT.ENGINE` (inherited-dispatch-candidate). Document engine inherits shared edit dispatch; body/version semantics differ from legacy. Runtime not verified.
- `LINK-025`: `ACCOUNT.ENABLE.ENGINE` → `TRANSACTION.EDIT.ENGINE` (inherited-dispatch-candidate). People engine inherits shared dispatch. Runtime not verified.
- `LINK-026`: `TASK.EDIT.CONDUIT.LEGACY` → `TASK.EDIT.CONDUIT.LEGACY.BASE` (inherited-dispatch-candidate). Deprecated update inherits legacy task API implementation helpers. Runtime not verified.
- `LINK-027`: `ACCOUNT.ENABLE.CLI` → `ACCOUNT.ENABLE.CLI.BASE` (inherited-dispatch-candidate). CLI enable calls inherited applyTransactions helper. Runtime not verified.
- `LINK-028`: `TASK.BULK.ENGINE` → `TRANSACTION.BULK.ENGINE` (inherited-dispatch-candidate). Task bulk engine inherits shared bulk behavior. Runtime not verified.
- `LINK-029`: `TRANSACTION.BULK.ENGINE` → `TRANSACTION.EDIT.ENGINE` (inherited-dispatch-candidate). Shared bulk constructs selected edit engine; queued worker target behavior remains partial. Runtime not verified.
- `LINK-030`: `PROJECT.MEMBER.EDITOR` → `TRANSACTION.LEGACY` (shared-handler-candidate). Project editor extends shared editor; modular and builtin legacy handling coexist. Runtime not verified.
- `LINK-031`: `TASK.EDIT.EDITOR` → `TRANSACTION.LEGACY` (shared-handler-candidate). Task editor extends shared editor. Runtime not verified.
- `LINK-032`: `WIKI.EDIT.EDITOR` → `TRANSACTION.LEGACY` (shared-handler-candidate). Wiki editor extends shared editor. Runtime not verified.
- `LINK-033`: `ACCOUNT.ENABLE.EDITOR` → `TRANSACTION.LEGACY` (shared-handler-candidate). People editor extends shared editor. Runtime not verified.
- `LINK-034`: `TRANSACTION.LEGACY` → `TRANSACTION.MODULAR` (shared-handler-candidate). Shared editor obtains modular transaction types; behavior varies by object. Runtime not verified.
- `LINK-035`: `PROJECT.MEMBER.INVERSE` → `PROJECT.MEMBER.EDGE.REVERSE` (shared-handler-candidate). Edge writer resolves inverse by edge type; candidate path, not complete inverse caller audit. Runtime not verified.
- `LINK-036`: `TRANSACTION.BULK.WORKER` → `TRANSACTION.BULK.ENGINE` (shared-handler-candidate). Queued bulk worker dispatch is a shared candidate requiring explicit worker-path proof. Runtime not verified.
- `LINK-037`: `PROJECT.MEMBER.LEGACY` → `TRANSACTION.MODULAR` (historical-interpretation-only). Old project:members class preserves history display; it is not evidence of a supported active member writer. Runtime not verified.

## Deterministic candidate review loop

The scanner reads non-test PHP under `src/`, except the isolated governance tree and generated library map. It records a complete runtime-source content snapshot, textual candidate file digests, pattern IDs and line numbers. Comments and declarations may match: this is intentionally conservative, not a parser or safety proof.

Run `python3 scripts/governance/check_callers.py`. A new, changed or removed runtime source makes the check fail even when no recognized token is found. Review source changes and new candidates, update the static graph if relevant, retain unsupported items otherwise, and review the inventory/checker diff. There is no automatic accept/refresh flag.

Each candidate has an explicit mapped-static-only or unsupported disposition. Both keep activation blocked. Do not mark a broad other-product candidate irrelevant solely because its name differs; shared dispatch may affect this scope.

Current snapshot: 4433 runtime files, 277 textual candidate files, 47 manually associated consumers, 40 graph links. 241 candidates remain unsupported. Counts are scan bookkeeping, not test passes.

## Unverified activation blockers

- `U01`: Complete legacy and inverse edge caller inventory remains pending.
- `U02`: All account-enable callers and full recovery source inventory remain pending.
- `U03`: Cross-database read-set, locking and adapter consistency remain unverified.
- `U04`: Per-object native automatic capabilities and new content-field semantics remain unverified.
- `U05`: Media identity protocol, irreversible session invalidation and all file/cache routes remain unimplemented.
- `U06`: All mail producers, actual-send recipient revalidation and private payload coverage remain unverified.
- `U07`: Actual capacity, retention exceptions and cleanup/recovery proof remain unverified.
- `U08`: External identity, API credential, WebSocket and queue revocation semantics remain unverified.

## Tests and boundaries

Run `python3 -m unittest discover -s tests/governance -p test_callers.py`. Tests mutate candidate discovery, snapshot content, source existence, graph IDs, triage and pending states; they do not run native authorization. Existing batch-1 contracts and all eight pending blockers remain unchanged. No route, application, schema patch or runtime policy is registered. No deployed addresses, credentials, real users or capability links belong in this inventory.

## Additional object and custom-field sources

- `PROJECT.DESCRIPTION.CUSTOMFIELD`: [src/applications/project/customfield/PhabricatorProjectDescriptionField.php](../../src/applications/project/customfield/PhabricatorProjectDescriptionField.php), `PhabricatorProjectDescriptionField::createFields`; partial/static reference only.
- `TASK.POLICY.SOURCE`: [src/applications/maniphest/storage/ManiphestTask.php](../../src/applications/maniphest/storage/ManiphestTask.php), `ManiphestTask`; partial/static reference only.
- `WIKI.POLICY.SOURCE`: [src/applications/phriction/storage/PhrictionDocument.php](../../src/applications/phriction/storage/PhrictionDocument.php), `PhrictionDocument`; partial/static reference only.

The engine custom-field link and task/wiki object policy sources are explicit review anchors; they do not certify automatic-capability behavior. Generated scan/triage rows are compact for review; no evidence fields are removed.

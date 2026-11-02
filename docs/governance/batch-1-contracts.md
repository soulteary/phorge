# Batch 1 isolated governance contracts

This inventory records the user-selected ADR v1.2 design. It does not activate or certify any permission, route, schema patch, media credential, account recovery gate, or production application.

## Stable interfaces

- `N00` — `N00.COVERAGE.v1`; build dependencies: none; active=false, partial coverage.
- `N01` — `N01.ORG-SCHEMA.v1`; build dependencies: N00; active=false, partial coverage.
- `N02` — `N02.DIRECTORY-VIEW.v1`; build dependencies: N01; active=false, partial coverage.
- `N05-A` — `N05-A.CONTENT-CONTRACT.v1`; build dependencies: N00; active=false, partial coverage.
- `N07-A` — `N07-A.APPROVAL-CONTRACT.v1`; build dependencies: N00; active=false, partial coverage.

N01/N02 and N05-A/N07-A are isolated foundation contracts. Source mapping in the JSON is a starting inventory, not proof of complete caller coverage. All unsupported entries block activation. Pure isolated classes and their library class map may be added without registering production applications, routes, configuration or schema patches.

## Selected rules

- `V01=A`: `high_risk_independent_approval`.
- `V02=A`: `new_content_only_native_edit_preserved`.
- `V03=A`: `incomplete_recovery_blocks_enable`.
- `V04=B`: `directory_scoped_admins_only`.
- `V05=A`: `history_system_admins_only`.
- `V06=A`: `media_parent_session_nonrevival`.
- `V07=A`: `team_admin_requires_active_member`.
- `V08=A`: `native_join_unchanged`.
- `V09=A`: `handover_14_30_days_manual_disposition`.
- `V10=A`: `audit_365_days_directory_8_soft_levels`.

V02-A preserves legacy native edit management eligibility; only the new content path guarantees restricted editing. V01-A independently requires approval for high-risk execution. No global restriction of old edit is silently introduced.

V06-A future media credentials must be tied to a specific application session and irreversible invalidation state. Logout, expiry and disable invalidate old credentials; re-enable cannot revive them, including when no media request occurred during disablement. No D15 or new time SLA is selected.

V03-A recovery must include application sessions, API and media credentials; unsupported sources block recovery. This batch does not revoke or replace actual credentials. V04-B and V05-A apply to notification subjects, bodies, links and status APIs as well as directory/history pages.

## Native preservation

- Preserve native subproject visibility, edit inheritance, member aggregation and historical-edge interpretation.
- Preserve object view, Space and native automatic-capability boundaries. New contracts are not runtime authorization.
- Keep optional features, production migration and public-surface additions disabled.

## Source and consumer inventory

- `PROJECT.EDIT.WEB`: [src/applications/project/controller/PhabricatorProjectEditController.php](../../src/applications/project/controller/PhabricatorProjectEditController.php), symbol `PhabricatorProjectEditController`; project web edit. Static reference only; partial and activation blocked.
- `PROJECT.EDIT.ENGINE`: [src/applications/project/engine/PhabricatorProjectEditEngine.php](../../src/applications/project/engine/PhabricatorProjectEditEngine.php), symbol `PhabricatorProjectEditEngine`; project edit field and transaction mapping. Static reference only; partial and activation blocked.
- `PROJECT.MEMBER.ADD`: [src/applications/project/controller/PhabricatorProjectMembersAddController.php](../../src/applications/project/controller/PhabricatorProjectMembersAddController.php), symbol `PhabricatorProjectMembersAddController`; project forward member add. Static reference only; partial and activation blocked.
- `PROJECT.MEMBER.REMOVE`: [src/applications/project/controller/PhabricatorProjectMembersRemoveController.php](../../src/applications/project/controller/PhabricatorProjectMembersRemoveController.php), symbol `PhabricatorProjectMembersRemoveController`; project forward member remove. Static reference only; partial and activation blocked.
- `PROJECT.MEMBER.EDITOR`: [src/applications/project/editor/PhabricatorProjectTransactionEditor.php](../../src/applications/project/editor/PhabricatorProjectTransactionEditor.php), symbol `PhabricatorProjectTransactionEditor`; project transactions and final effects. Static reference only; partial and activation blocked.
- `PROJECT.MEMBER.INVERSE`: [src/infrastructure/edges/editor/PhabricatorEdgeEditor.php](../../src/infrastructure/edges/editor/PhabricatorEdgeEditor.php), symbol `PhabricatorEdgeEditor`; shared inverse edge edit candidate; full callers not yet inventoried. Static reference only; partial and activation blocked.
- `PROJECT.EDIT.CONDUIT`: [src/applications/project/conduit/ProjectEditConduitAPIMethod.php](../../src/applications/project/conduit/ProjectEditConduitAPIMethod.php), symbol `ProjectEditConduitAPIMethod`; project.edit API. Static reference only; partial and activation blocked.
- `PROJECT.CREATE.CONDUIT`: [src/applications/project/conduit/ProjectCreateConduitAPIMethod.php](../../src/applications/project/conduit/ProjectCreateConduitAPIMethod.php), symbol `ProjectCreateConduitAPIMethod`; legacy project create API. Static reference only; partial and activation blocked.
- `PROJECT.POLICY.SOURCE`: [src/applications/project/storage/PhabricatorProject.php](../../src/applications/project/storage/PhabricatorProject.php), symbol `PhabricatorProject`; native automatic capabilities, hierarchy and membership. Static reference only; partial and activation blocked.
- `PROJECT.QUERY.CONSUMER`: [src/applications/project/query/PhabricatorProjectQuery.php](../../src/applications/project/query/PhabricatorProjectQuery.php), symbol `PhabricatorProjectQuery`; native query visibility consumers. Static reference only; partial and activation blocked.
- `POLICY.FILTER`: [src/applications/policy/filter/PhabricatorPolicyFilter.php](../../src/applications/policy/filter/PhabricatorPolicyFilter.php), symbol `PhabricatorPolicyFilter`; native policy filter and automatic capabilities. Static reference only; partial and activation blocked.
- `ACCOUNT.ENABLE.WEB`: [src/applications/people/controller/PhabricatorPeopleDisableController.php](../../src/applications/people/controller/PhabricatorPeopleDisableController.php), symbol `PhabricatorPeopleDisableController`; web enable/disable. Static reference only; partial and activation blocked.
- `ACCOUNT.ENABLE.API`: [src/applications/people/conduit/UserEditConduitAPIMethod.php](../../src/applications/people/conduit/UserEditConduitAPIMethod.php), symbol `UserEditConduitAPIMethod`; user.edit edit-engine API. Static reference only; partial and activation blocked.
- `ACCOUNT.DISABLE.LEGACY_API`: [src/applications/people/conduit/UserDisableConduitAPIMethod.php](../../src/applications/people/conduit/UserDisableConduitAPIMethod.php), symbol `UserDisableConduitAPIMethod`; legacy user disable API; do not infer enable coverage. Static reference only; partial and activation blocked.
- `ACCOUNT.ENABLE.CLI`: [src/applications/people/management/PhabricatorPeopleManagementEnableWorkflow.php](../../src/applications/people/management/PhabricatorPeopleManagementEnableWorkflow.php), symbol `PhabricatorPeopleManagementEnableWorkflow`; people enable management workflow. Static reference only; partial and activation blocked.
- `ACCOUNT.ENABLE.ENGINE`: [src/applications/people/editor/PhabricatorUserEditEngine.php](../../src/applications/people/editor/PhabricatorUserEditEngine.php), symbol `PhabricatorUserEditEngine`; disabled edit-field mapping. Static reference only; partial and activation blocked.
- `ACCOUNT.ENABLE.TRANSACTION`: [src/applications/people/xaction/PhabricatorUserDisableTransaction.php](../../src/applications/people/xaction/PhabricatorUserDisableTransaction.php), symbol `PhabricatorUserDisableTransaction`; user.disable transaction. Static reference only; partial and activation blocked.
- `ACCOUNT.SESSION`: [src/applications/auth/engine/PhabricatorAuthSessionEngine.php](../../src/applications/auth/engine/PhabricatorAuthSessionEngine.php), symbol `PhabricatorAuthSessionEngine`; session load/expiry/logout boundary. Static reference only; partial and activation blocked.
- `FILE.DATA`: [src/applications/files/controller/PhabricatorFileDataController.php](../../src/applications/files/controller/PhabricatorFileDataController.php), symbol `PhabricatorFileDataController`; bearer data/download read and cache. Static reference only; partial and activation blocked.
- `FILE.TRANSFORM`: [src/applications/files/controller/PhabricatorFileTransformController.php](../../src/applications/files/controller/PhabricatorFileTransformController.php), symbol `PhabricatorFileTransformController`; file transform route. Static reference only; partial and activation blocked.
- `FILE.ROUTES`: [src/applications/files/application/PhabricatorFilesApplication.php](../../src/applications/files/application/PhabricatorFilesApplication.php), symbol `PhabricatorFilesApplication`; native route definitions including instance variants. Static reference only; partial and activation blocked.
- `MAIL.QUEUE`: [src/applications/metamta/PhabricatorMetaMTAWorker.php](../../src/applications/metamta/PhabricatorMetaMTAWorker.php), symbol `PhabricatorMetaMTAWorker`; queued outbound mail delivery. Static reference only; partial and activation blocked.
- `MAIL.MODEL`: [src/applications/metamta/storage/PhabricatorMetaMTAMail.php](../../src/applications/metamta/storage/PhabricatorMetaMTAMail.php), symbol `PhabricatorMetaMTAMail`; subject/body/recipient/attachment producer boundary. Static reference only; partial and activation blocked.
- `MAIL.ENGINE`: [src/applications/metamta/engine/PhabricatorMailEmailEngine.php](../../src/applications/metamta/engine/PhabricatorMailEmailEngine.php), symbol `PhabricatorMailEmailEngine`; outbound payload assembly. Static reference only; partial and activation blocked.
- `MAIL.ADAPTER`: [src/applications/metamta/adapter/PhabricatorMailGorgeAdapter.php](../../src/applications/metamta/adapter/PhabricatorMailGorgeAdapter.php), symbol `PhabricatorMailGorgeAdapter`; Gorge transport boundary; not all producers. Static reference only; partial and activation blocked.
- `MAIL.HERALD`: [src/applications/metamta/herald/PhabricatorMailOutboundMailHeraldAdapter.php](../../src/applications/metamta/herald/PhabricatorMailOutboundMailHeraldAdapter.php), symbol `PhabricatorMailOutboundMailHeraldAdapter`; outbound Herald modifications. Static reference only; partial and activation blocked.
- `CONTENT.TASK`: [src/applications/maniphest/editor/ManiphestEditEngine.php](../../src/applications/maniphest/editor/ManiphestEditEngine.php), symbol `ManiphestEditEngine`; task field mapping candidate, automatic capabilities need audit. Static reference only; partial and activation blocked.
- `CONTENT.WIKI`: [src/applications/phriction/editor/PhrictionTransactionEditor.php](../../src/applications/phriction/editor/PhrictionTransactionEditor.php), symbol `PhrictionTransactionEditor`; wiki transaction mapping candidate, hierarchy needs audit. Static reference only; partial and activation blocked.

## Unverified scope

- `U01`: Complete legacy and inverse edge caller inventory remains pending.
- `U02`: All account-enable callers and full recovery source inventory remain pending.
- `U03`: Cross-database read-set, locking and adapter consistency remain unverified.
- `U04`: Per-object native automatic capabilities and new content-field semantics remain unverified.
- `U05`: Media identity protocol, irreversible session invalidation and all file/cache routes remain unimplemented.
- `U06`: All mail producers, actual-send recipient revalidation and private payload coverage remain unverified.
- `U07`: Actual capacity, retention exceptions and cleanup/recovery proof remain unverified.
- `U08`: External identity, API credential, WebSocket and queue revocation semantics remain unverified.

## Validation

Run `python3 scripts/governance/check_contracts.py --check-worktree` and `python3 -m unittest discover -s tests/governance -p "test_contracts.py"`. The validator checks selected identifiers/effects, native constraints, interface graph, referenced regular files, states and protected-path changes. It deliberately does not grep source text to prove authorization behavior. Behavioral, native and database tests must establish the real contracts.

`--check-worktree` compares tracked changes and untracked paths against the fixed baseline. It rejects runtime tree changes except the explicitly isolated governance directory and library class map. This is a path guard, not a security or registration proof; review the class-map diff and all newly reachable symbols independently. It includes modified, staged, deleted and untracked paths.

No statuses in this inventory are runtime passes. Existing atomic counts and historical test receipts remain unchanged.

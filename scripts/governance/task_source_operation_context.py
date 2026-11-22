"""Root-signed actual Task source stage for the real issued SourceOperation.
No arbitrary reader callbacks, executable historical modules, DB or runtime grant.
"""
import base64,hashlib,json,os,re,stat,subprocess,zlib
from pathlib import Path
from types import MappingProxyType
ROOT_SIGNER='CD85ED7465AFF2FFA2FFB686DBCDD8CBF440F8DE'
BASE='356878fb64e33f62967e36eeff926abba285ff25'
BASELINE='support/governance/source-operation/task-parent5903.json'
BASELINE_SHA='e9b0860ac3d4e8fb835b18fdee07e60b20b373713a11ddfb2ac1d5a24f2d7ac0'
INVERSE='support/governance/source-operation/task-api-parent-inverse.json'
INVERSE_SHA='4af284a3da68786d2508826f69aeb50d85b7080ca7af81073b34e657473440ce'
TASK_NEW_PINS={'resources/sql/autopatches/20261112.task.contentgrants.sql': {'bytes': 1553, 'sha256': '632b4412c18ea61787ca6b38afc9d3c16b62f76591e9a99d624401eeac117236'}, 'scripts/governance/task_content_grant_source_delta.py': {'bytes': 13894, 'sha256': '25ff589d4e9a78127066ceee80ccf12830513cc96e445a2a9d7270f5e980f0b6'}, 'src/infrastructure/governance/content/PhorgeTaskContentApprovalBinding.php': {'bytes': 1017, 'sha256': 'fb5c45796754222fb0e0c608ae16ac7c3de29ee463ddc149025b7f8e910c0e8e'}, 'src/infrastructure/governance/content/PhorgeTaskContentGrantCommitProof.php': {'bytes': 1870, 'sha256': '47fa65e73361a8a17f1f428ffdc3fa402d9263a290a17c14b99902f381f3fc48'}, 'src/infrastructure/governance/content/PhorgeTaskContentGrantConfigOptions.php': {'bytes': 867, 'sha256': 'c55503b9deb2cd2077a9dcf7b08c1dea881de97c880c33c369643363f9d85e94'}, 'src/infrastructure/governance/content/PhorgeTaskContentGrantConfiguration.php': {'bytes': 794, 'sha256': '6bd5868f4b86d5918b000adea0af1447d641018aed6ad036b5a444d493c6b1d4'}, 'src/infrastructure/governance/content/PhorgeTaskContentGrantPlan.php': {'bytes': 3687, 'sha256': '5274f30a211ab36ea59a2c2c0897792c61b3e2551606df11f8a2e94df83ce158'}, 'src/infrastructure/governance/content/PhorgeTaskContentGrantRequest.php': {'bytes': 2619, 'sha256': 'b526bfc0665178a16e15bfbca09206c89b6f5a4f67041713aa8a5caf02b5b480'}, 'src/infrastructure/governance/content/PhorgeTaskContentGrantSchemaSpec.php': {'bytes': 1782, 'sha256': '7cac65fe600e2681eed05618fd584a02f37e27b7f532d6ca2b59244bf5045ccb'}, 'src/infrastructure/governance/content/PhorgeTaskContentGrantService.php': {'bytes': 1807, 'sha256': '45b2f14300ae6b308ee33ec088aa732e14fe9396fbf8f4bf4dd7e1fafd116eef'}, 'src/infrastructure/governance/content/PhorgeTaskContentGrantStore.php': {'bytes': 10327, 'sha256': '07a1d9533c5b0bc23bb0cd147c57706b3e0863fe47ecc647628fe965e1d8d85d'}, 'src/infrastructure/governance/content/PhorgeTaskContentGrantValue.php': {'bytes': 1845, 'sha256': '4b9fb818abd4126dbd184f2876606fef5751c916304434cdcebf6616ca180cb6'}, 'src/infrastructure/governance/content/PhorgeTaskContentGrantWriteScope.php': {'bytes': 29444, 'sha256': 'da48c7aa59cc9d1c2be7df2ac39230a6757e992ee8956b781c3e245f6a294374'}, 'src/infrastructure/governance/content/PhorgeTaskContentSource.php': {'bytes': 5155, 'sha256': 'd71672d278a8a3ac2d2a5f3e0f13ea44d49e4c7daffc6106125a0c846298509d'}, 'src/infrastructure/governance/content/README.task-durable-grants.md': {'bytes': 4184, 'sha256': '6fc6078b52c4d4115d7f96a329afd6039026913c6171140842205e09649783c2'}, 'src/infrastructure/governance/content/task-grant-migration-source.json': {'bytes': 541, 'sha256': '675eb678ae558bbaf0c2d9707bd7ffcc9753c4dd4ee650636b05576b3347d02d'}, 'src/infrastructure/governance/execution/PhorgeTaskGrantExecutionConfigSource.php': {'bytes': 10783, 'sha256': '8301a70ee12b6a2707d624806983ba24ede834f9881869fd656f772056f13f7f'}, 'support/governance/task-content-grants-qualification/README.md': {'bytes': 5886, 'sha256': '818236470ad5dbcc922ec00ab0c7f5cd51ef4f2f1089b05783e20a77f4b4d10e'}, 'support/governance/task-content-grants-qualification/native.php': {'bytes': 17537, 'sha256': '7500df49ba23483a61f0f5edc88661fb3a3df4f2c531bddaaeeea5eef01ba4fa'}, 'support/governance/task-content-grants-qualification/qualify.py': {'bytes': 8475, 'sha256': '322e4471a14f6b80d26bf461f8e81e5a470f988e07631b8db76362fa4428d384'}, 'support/governance/task-content-grants-qualification/source_gate.py': {'bytes': 6985, 'sha256': '6ceeb0d88e5cbf999e500ff8c8c3d0fb79e3539f06a8af2b3197c49025263476'}, 'tests/governance/test_task_current_source_admission.py': {'bytes': 17736, 'sha256': '2de9e287431540fcf375b9a900a152e3d8bfa9c75738cfc74bf68d3204238154'}, 'tests/governance/test_task_durable_grant_lifecycle.py': {'bytes': 10029, 'sha256': '2fd99c724f3a5d5d5032a9fcd4562edb683c78661a7c7dd27d7409209c0755d1'}, 'tests/governance/test_task_durable_grant_source.py': {'bytes': 11978, 'sha256': 'bafa91f4dbd2ad1d20b18ac08deef409880b0ecffc5089434f374846cdde7b4f'}}
V2_PATHS={'scripts/governance/check_directory_transports_task_source_v2.py','scripts/governance/check_media_freshness_task_source_v2.py','tests/governance/test_task_source_consumers_v2.py'}
CI_INVERSE='support/governance/source-operation/task-ci-modified-parent-v1.json'
CI_INVERSE_SHA='d71e48e70f3f23755c28b9f16cf247fa5dace3cab6c09487c4a0705317cfeb51'
CI_V2_NEW_PATHS={'support/governance/task-additive-schema-v2/producer-roles-v2.json', 'support/governance/task-additive-schema-v2/producer-prefix.php', 'scripts/governance/run_task_directory_native_v2.py', 'scripts/governance/oauth_access_task_strict_body_v2.py', 'tests/governance/test_task_native_role_adapter_v2.py', 'tests/governance/test_paired_source_facts_task_strict_body_v2.py', 'tests/governance/test_project_description_current_consumers_task_v2.py', 'tests/governance/task_paired_workflow_shell_test_api_v2.py', 'support/governance/task-additive-schema-v2/oauth-reviewed-current-body-pins.json', 'scripts/governance/conduit_task_strict_body_v2.py', 'scripts/governance/probe_conduit_task_mysql84.php', 'tests/governance/task_ci_workflow_test_api_v2.py', 'support/governance/task-additive-schema-v2/bind.py', 'tests/governance/test_oauth_access_receipt_rejection_v3.py', 'scripts/governance/check_oauth_code_task_schema_receipt_v2.py', 'tests/governance/test_oauth_access_stage_diagnostic_v3.py', 'scripts/governance/oauth_code_task_strict_body_v2.py', 'tests/governance/task_partial_schema_test_api_v2.py', 'support/governance/source-operation/ci-source-verifier.asc', 'scripts/governance/check_directory_task_schema_receipt_v4.py', 'scripts/governance/check_oauth_task_schema_receipt_v2.py', 'support/governance/task-additive-schema-v2/capture.php', 'scripts/governance/check_directory_receipt_task_v2.py', 'scripts/governance/task_ci_native_source_v2.py', 'tests/governance/test_task_additive_schema_v2.py', 'scripts/governance/paired_source_facts_task_strict_body_v2.py', 'scripts/governance/prepare_task_directory_run_v2.py', 'scripts/governance/source_facts_task_strict_body_v2.py', 'support/governance/task-additive-schema-v2/producer_adapter.py', 'tests/governance/test_project_description_admission_task_v2.py', 'support/governance/project-description-ci/task-metadata-lock-v2.json', 'scripts/governance/probe_oauth_access_task_mysql84.php', 'support/governance/task-additive-schema-v2/run.php', 'scripts/governance/check_paired_source_facts_task_schema_receipt_v2.py', 'tests/governance/task_inverse_test_api_v2.py', 'tests/governance/test_task_ci_native_adopter_v2.py', 'scripts/governance/check_conduit_task_schema_receipt_v2.py', 'scripts/governance/oauth_task_strict_body_v2.py', 'tests/governance/task_admission_test_api_v2.py', 'tests/governance/recovery_oauth_code_task_test_cohort_v2.py', 'tests/governance/test_source_facts_task_strict_body_v2.py', 'scripts/governance/check_source_facts_task_schema_receipt_v2.py', 'scripts/governance/check_assessment_task_schema_receipt_v2.py', 'support/governance/task-additive-schema-v2/validate.py', 'scripts/governance/check_oauth_access_task_schema_receipt_v2.py', 'scripts/governance/check_task_ci_head_v1.py', 'support/governance/source-operation/task-ci-modified-parent-v1.json', 'tests/governance/task_description_consumer_test_api_v2.py', 'tests/governance/run_description_task_v2_controls.py', 'scripts/governance/probe_oauth_code_task_mysql84.php', 'scripts/governance/probe_assessment_task_mysql84.php', 'tests/governance/test_project_description_source_inverse_task_v2.py', 'scripts/governance/assessment_task_strict_body_v2.py', 'tests/governance/test_task_directory_run_binding_v2.py', 'tests/governance/test_project_description_main_task_v2.py'}
CI_V2_NEW_PATHS.update({'scripts/governance/probe_full_native_task_mysql84.php', 'scripts/governance/full_native_task_strict_body_v2.py', 'scripts/governance/check_full_native_task_schema_receipt_v2.py'})
CI_V2_NEW_PATHS.add('tests/governance/task_current_phase_shell_test_v2.py')
CI_V2_NEW_PATHS.update({'tests/governance/ci_test_partition.py','tests/governance/test_ci_test_partition.py','tests/atomic/README.ci-cost.md'})
CI_V2_NEW_PATHS.add('tests/governance/task_atomic_access_shell_test_v2.py')
CI_V2_NEW_PATHS.update({'scripts/governance/check_native_directory_task_source_v2.py','tests/governance/test_native_directory_task_static_v2.py'})
TASK_PREUPGRADE_FIXTURE='support/governance/completion/fixture.php'
TASK_PREUPGRADE_RECIPE='support/governance/source-operation/task-preupgrade-fixture-inverse-v1.json'
TASK_PREUPGRADE_RECIPE_PIN={'bytes': 10929, 'sha256': '9c67f64a4dd2268cc554267a618c1078cd967e45ba978d6bfb9098eba92cb6db'}
TASK_PREUPGRADE_CURRENT_PIN={'bytes': 37961, 'sha256': '5fcb8e63a287880f32d0ea71a744a6e63bef104469086b5441be5d16cc6353e6'}
TASK_PREUPGRADE_PARENT_PIN={'bytes': 27902, 'sha256': '00c79b823ae5d92ae6a365f7f13de97243981d9efd5cefa827ca0ca70e1cee93'}
CI_V2_NEW_PATHS.update({TASK_PREUPGRADE_RECIPE,'tests/governance/test_task_preupgrade_fixture_v1.py'})
CI_V2_NEW_PATHS.update({'tests/governance/task_historical_views_test_api_v2.py', 'tests/governance/task_oauth_coordination_test_api_v2.py'})
CI_V2_NEW_PATHS.update({'tests/governance/task_description_shard_routes_v3.py','tests/governance/test_task_description_shard_routes_v3.py'})
CI_V2_NEW_PATHS.update({'scripts/governance/project_task_bootstrap_failure_v2.py','tests/governance/test_task_bootstrap_failure_diagnostic_v2.py'})
CI_V2_NEW_PATHS.add('tests/governance/test_source_operation_snapshot_task_v2.py')
CI_V2_NEW_PATHS.update({'tests/governance/assessment_task_test_api_v2.py','tests/governance/task_directory_inventory_test_api_v2.py','tests/governance/test_source_snapshot_assessment_task_v2.py'})
CI_V2_NEW_PATHS.add('tests/governance/task_notice_native_source_test_api_v2.py')
CI_V2_NEW_PATHS.update({'tests/governance/assessment_legacy_DATA_model_v3.py','tests/governance/test_assessment_legacy_DATA_model_v3.py'})
CI_V2_NEW_PATHS.add('tests/governance/test_assessment_legacy_DATA_boundaries_v3.py')
CI_V2_NEW_PATHS.update({'tests/governance/task_explicit_shard_routes_v4.py','tests/governance/test_task_explicit_shard_routes_v4.py'})
CI_V2_NEW_PATHS.update({'scripts/governance/project_conduit_stage_failure_v3.py','tests/governance/test_conduit_stage_failure_v3.py'})
CI_V2_NEW_PATHS.add('tests/governance/project_grant_source_task_test_api_v2.py')
CI_V2_NEW_PATHS.add('tests/governance/test_conduit_receipt_rejection_v3.py')
CI_V2_NEW_PATHS.add('tests/governance/calendar_sql_task_test_api_v2.py')
CI_V2_NEW_PATHS.update({'support/governance/completion-task-v3/validate.py', 'scripts/governance/check_completion_task_receipt_v3.py', 'tests/governance/test_completion_same_owner_v3.py', 'support/governance/completion-task-v3/run.php', 'support/governance/completion-task-v3/capture.php', 'support/governance/completion-task-v3/prefix.php', 'scripts/governance/run_completion_task_v3.py'})
MAX=268435456

CI_V2_NEW_PATHS.update({'scripts/governance/project_completion_ci_integration_v3.py', 'tests/governance/calendar_failure_task_test_api_v2.py', 'tests/governance/task_source_candidate_test_api_v2.py', 'tests/governance/test_completion_ci_integration_v3.py'})

CI_V2_NEW_PATHS.update({'tests/governance/task_native_gate_test_api_v2.py', 'tests/governance/test_task_native_gate_test_api_v2.py'})

CI_V2_NEW_PATHS.update({'tests/governance/test_full_native_task_capture_cleanup_v2.py', 'tests/governance/test_task_explicit_shard_routes_v5.py', 'tests/governance/test_task_current_source_admission_task_v2.py', 'tests/governance/task_explicit_shard_routes_v5.py'})

CI_V2_NEW_PATHS.update({'tests/governance/test_api_credential_task_strict_body_v2.py', 'scripts/governance/check_api_credential_task_schema_receipt_v2.py', 'scripts/governance/api_credential_task_strict_body_v2.py'})

CI_V2_NEW_PATHS.update({'scripts/governance/project_media_task_selected_v2.py', 'tests/governance/test_media_task_runtime_v2.py', 'scripts/governance/media_task_archive_v2.py', 'tests/governance/test_media_current_archive_owner_v2.py', 'scripts/governance/media_task_runtime_v2.py', 'scripts/governance/prepare_media_task_archive_v2.py', 'scripts/governance/run_media_task_selected_v2.py'})

CI_V2_NEW_PATHS.update({'scripts/governance/paired_session_facts_task_strict_body_v2.py', 'scripts/governance/check_paired_session_facts_task_schema_receipt_v2.py', 'tests/governance/test_paired_session_facts_task_strict_body_v2.py'})

CI_V2_NEW_PATHS.update({'tests/governance/test_task_paired_failure_transport_v2.py', 'tests/governance/test_push_groups_upload_causality_v2.py', 'tests/governance/task_three_paired_workflow_shell_test_api_v2.py'})

CI_V2_NEW_PATHS.update({'scripts/governance/check_workbench_task_schema_receipt_v2.py', 'tests/governance/test_workbench_task_strict_body_v2.py', 'scripts/governance/workbench_task_strict_body_v2.py'})

CI_V2_NEW_PATHS.update({'tests/governance/test_calendar_task_strict_body_v2.py', 'scripts/governance/calendar_task_strict_body_v2.py', 'scripts/governance/check_calendar_task_schema_receipt_v2.py'})

CI_V2_NEW_PATHS.add('tests/governance/test_atomic_two_owner_task_routes_v2.py')

CI_V2_NEW_PATHS.update({'tests/governance/task_workbench_workflow_shell_test_api_v2.py'})

CI_V2_NEW_PATHS.update({'scripts/governance/project_calendar_failure_task_v2.py', 'tests/governance/test_calendar_failure_task_v2.py'})

CI_V2_NEW_PATHS.update({'tests/governance/task_calendar_workflow_shell_test_api_v2.py'})
CI_V2_NEW_PATHS.add('tests/governance/test_n11_task_runtime_entry_current_v2.py')
# Versioned notification admission keeps all original parent/FD checks.
CI_V2_NEW_PATHS.update({'scripts/governance/run_n11_task_current_v2.py', 'scripts/governance/n11_task_source_current_v2.py', 'tests/governance/test_n11_task_source_current_v2.py', 'tests/governance/test_n11_task_current_source_operation_v2.py'})

CI_V2_NEW_PATHS.update({'scripts/governance/current_native_pair_metadata_v1.py','tests/governance/test_current_native_pair_metadata_v1.py'})
# Explicit reviewed CI-tier additions. Unknown source paths still refuse.
CI_V2_NEW_PATHS.update({
 '.github/workflows/release-full.yml',
 'deploy/release/ci_policy.py',
 'docs/ci/README.md',
 'docs/ci/support-baseline.json',
 'docs/upstream/native-trial-acceptance-scope.md',
 'scripts/ci/core_guard.php',
 'scripts/ci/core_guard.py',
 'scripts/ci/focused_workflows.py',
 'scripts/ci/source_footprint.py',
 'tests/ci/test_core_focused_tiers.py',
 'tests/ci/test_core_footprint.py',
 'tests/ci/test_core_guard.py',
 'tests/ci/test_core_workflow.py',
 'tests/release/test_ci_policy.py',
})
# Exact additions for the external CI proof tool and its runbook.
CI_V2_NEW_PATHS.update({'scripts/ci/create_merge_source_proof.py','docs/ci/merge-source-proof.md'})
# Exact reviewed main additions: identity regression and portable SQL isolation.
CI_V2_NEW_PATHS.update({'src/applications/auth/__tests__/PhabricatorTraefikSessionIdentityTestCase.php','tests/contract/runtime/media-isolation.php'})

# Explicit RR current-restore metadata consumer and pure negative tests.
CI_V2_NEW_PATHS.update({'scripts/governance/check_native_user_rr_mdl_current_restore_v2.py','tests/ci/test_rr_current_restore_v2.py'})

# Exact upgrade documentation additions; unknown paths still refuse.
CI_V2_NEW_PATHS.update({'docs/operations/upgrade-and-rollback.md', 'docs/operations/2026.11.19-upgrade-acceptance.json', 'docs/operations/2026.11.19-r3-components.json'})

# Exact read-only upgrade tooling additions; no new wildcard.
CI_V2_NEW_PATHS.update({'scripts/operations/upgrade_check.py', 'scripts/operations/README.upgrade-check.md', 'tests/operations/test_upgrade_check.py'})

# Exact setup-check regression fixtures; no source-path wildcard.
CI_V2_NEW_PATHS.update({'tests/operations/webserver_selfcheck_fixture.php', 'tests/operations/test_webserver_selfcheck.py'})

# Exact Almanac tests and acceptance record; unknown source paths still refuse.
CI_V2_NEW_PATHS.update({'src/applications/almanac/__tests__/AlmanacInventoryBoundaryTestCase.php', 'docs/operations/almanac-acceptance-20261120.md'})

# Fixed release history is inert literal DATA, never executable historical code.
RELEASE_HISTORY='support/governance/source-operation/task-release-source-history-v1.json'
RELEASE_HISTORY_BYTES=299869
RELEASE_HISTORY_SHA='e4399285f2afdebbd18c2c6f92d32eaf5b6e2f6e71310302bf219d3d3b656f38'
RELEASE_HISTORY_PATHS=frozenset(['.github/data/retired-reference-exceptions.txt', '.github/workflows/container-image.yml', '.github/workflows/gorge-runtime-contracts.yml', '.github/workflows/native-rr-owner-build.yml', '.github/workflows/php-core-security.yml', '.github/workflows/rebuild-phutil-map.yml', '.gitignore', 'DOCKER.md', 'Dockerfile', 'README.md', 'deploy/acceptance/image-lock-provenance.md', 'deploy/acceptance/php_security.php', 'deploy/release/README.md', 'docker/acceptance.Dockerfile', 'docs/README.md', 'docs/governance/batch-1-contracts.json', 'docs/governance/batch-1-contracts.md', 'docs/governance/batch-1-implementation.md', 'docs/governance/batch-2-callers.md', 'docs/governance/private-notification-producers.json', 'docs/releases/php-8.5.12-upgrade.md', 'docs/upstream/atomic-release-protocol.md', 'docs/upstream/atomic-required-gates-and-evidence.md', 'resources/celerity/map.php', 'resources/sql/autopatches/20141107.phriction.policy.2.php', 'resources/sql/autopatches/20141107.phriction.popkeys.php', 'resources/sql/autopatches/20180215.phriction.02.phidvalues.php', 'resources/sql/autopatches/20180828.phriction.04.migrate.php', 'resources/sql/autopatches/20180828.phriction.06.c.documentid.php', 'resources/sql/autopatches/20180829.phriction.01.mailkey.php', 'resources/sql/autopatches/20180830.phriction.02.maxes.php', 'scripts/governance/check_api_credential_concurrency.py', 'scripts/governance/check_callers.py', 'scripts/governance/check_completion.py', 'scripts/governance/check_contracts.py', 'scripts/governance/check_directory_transports_v1.py', 'scripts/governance/check_native_user_rr_mdl.py', 'scripts/governance/check_organization_approval_workbench.py', 'scripts/governance/check_private_calendar_reminders.py', 'scripts/governance/check_private_notifications.py', 'scripts/governance/check_recovery_application_config.py', 'scripts/governance/check_recovery_assessment_credentials.py', 'scripts/governance/check_recovery_assessment_credentials_v2.py', 'scripts/governance/check_recovery_borrowed_session.py', 'scripts/governance/check_recovery_conduit_producer.py', 'scripts/governance/check_recovery_file_parent_policy.py', 'scripts/governance/check_recovery_file_source.py', 'scripts/governance/check_recovery_oauth_access_producer.py', 'scripts/governance/check_recovery_oauth_access_producer_v2.py', 'scripts/governance/check_recovery_oauth_access_producer_v3.py', 'scripts/governance/check_recovery_oauth_authorization_producer_v1.py', 'scripts/governance/check_recovery_oauth_client_lifecycle.py', 'scripts/governance/check_recovery_oauth_code_producer_v1.py', 'scripts/governance/check_recovery_org_lifecycle.py', 'scripts/governance/check_recovery_org_metadata.py', 'scripts/governance/check_recovery_partial_composition.py', 'scripts/governance/check_recovery_policy_isolation.py', 'scripts/governance/check_recovery_project_source.py', 'scripts/governance/check_recovery_session_lease.py', 'scripts/governance/check_recovery_spaces_source.py', 'scripts/governance/check_recovery_task_owner_source.py', 'src/__phutil_library_map__.php', 'src/__tests__/PhorgePHPVersionTestCase.php', 'src/applications/almanac/controller/AlmanacBindingEditController.php', 'src/applications/almanac/query/AlmanacDeviceSearchEngine.php', 'src/applications/almanac/query/AlmanacServiceSearchEngine.php', 'src/applications/auth/provider/PhabricatorTraefikAuthProvider.php', 'src/applications/base/controller/PhabricatorController.php', 'src/applications/config/check/PhabricatorWebServerSetupCheck.php', 'src/applications/config/check/__tests__/PhabricatorSetupCheckTestCase.php', 'src/applications/config/schema/PhabricatorConfigRetiredSchema.php', 'src/applications/config/schema/__tests__/PhabricatorConfigRetiredSchemaTestCase.php', 'src/applications/metamta/storage/PhabricatorMetaMTAMail.php', 'src/applications/remarkup/conduit/RemarkupProcessConduitAPIMethod.php', 'src/applications/remarkup/content/RemarkupReferenceBasicModule.php', 'src/applications/remarkup/remarkupcontroller/PhabricatorRemarkupControl.php', 'src/applications/search/menuitem/PhabricatorProfileMenuItemIconSet.php', 'src/docs/user/installation_guide.diviner', 'src/infrastructure/governance/consistency/PhorgeGovernanceNativeReadView.php', 'src/infrastructure/governance/contracts/PhorgeGovernanceContentDecision.php', 'src/infrastructure/governance/contracts/__tests__/PhorgeGovernanceContentDecisionTestCase.php', 'src/infrastructure/governance/mapping/PhorgeGovernanceCapabilityMap.php', 'src/infrastructure/governance/mapping/__tests__/PhorgeGovernanceCapabilityMapTestCase.php', 'src/infrastructure/governance/media/PhorgeGovernanceMediaExchangeService.php', 'src/infrastructure/governance/media/README.md', 'src/infrastructure/governance/media/__tests__/PhorgeGovernanceMediaExchangeServiceTestCase.php', 'src/infrastructure/governance/media/__tests__/PhorgeGovernanceMediaFileGuardTestCase.php', 'src/infrastructure/governance/media/__tests__/PhorgeGovernanceMediaHTTPProtocolTestCase.php', 'src/infrastructure/governance/native/PhorgeGovernanceNativePolicyCompositionVerifier.php', 'src/infrastructure/governance/native/PhorgeGovernanceNativeRuleHierarchyVerifier.php', 'src/infrastructure/governance/native/PhorgeGovernanceNativeSourceReader.php', 'src/infrastructure/governance/notification/PhorgePrivateMailPolicy.php', 'src/infrastructure/governance/observation/PhorgeGovernanceNativeObservationVerifier.php', 'src/infrastructure/governance/observation/README.md', 'src/infrastructure/governance/observation/__tests__/PhorgeGovernanceNativeObservationTestCase.php', 'src/infrastructure/governance/readset/PhorgeGovernanceReadSetValidator.php', 'src/infrastructure/governance/readset/__tests__/PhorgeGovernanceReadSetTestCase.php', 'src/infrastructure/internationalization/translation/PhabricatorChineseTranslation.php', 'src/infrastructure/markup/PhabricatorMarkupEngine.php', 'src/infrastructure/markup/markuprule/PhutilRemarkupDocumentLinkRule.php', 'src/infrastructure/storage/management/workflow/PhabricatorStorageManagementWorkflow.php', 'support/governance/completion/fixture-consistency.php', 'support/governance/completion/fixture.php', 'support/governance/completion/pipeline-matrix.json', 'support/governance/completion/pipeline.php', 'support/governance/fixtures/PhorgeGovernanceNativeFixture.php', 'support/governance/fixtures/test-native-pipeline-composition-mysql.php', 'support/governance/fixtures/test-native-rule-branches-mysql.php', 'support/governance/fixtures/test-native-source-mysql.php', 'support/governance/fixtures/test-readset-mysql.php', 'support/governance/media-session-ci/prepare.py', 'support/governance/media-session-ci/project.py', 'support/governance/media-session-docker/README.md', 'support/governance/media-session-docker/run.py', 'support/governance/notification/native-cases.php', 'support/governance/project-content-grants-ci/README.md', 'support/governance/project-description-ci/README.md', 'support/governance/project-description-ci/fixture.php', 'support/governance/project-description-qualification/README.md', 'support/governance/project-description-qualification/native.php', 'support/governance/recovery/native-writer-rr-ci-profile.php', 'support/governance/revocation-measurement/README.md', 'support/runtime/README.md', 'support/runtime/manifest.json', 'support/runtime/php-version.php', 'tests/atomic/README.md', 'tests/contract/runtime/products.php', 'tests/governance/native_rr_ci/build_owner.py', 'tests/governance/native_rr_ci/execution.py', 'tests/governance/native_rr_ci/runner.py', 'tests/governance/private_mail_ci/Dockerfile', 'tests/governance/private_mail_ci/IMAGE-PLAN.md', 'tests/governance/private_mail_ci/README.md', 'tests/governance/private_mail_ci/pure_inventory.py', 'tests/governance/private_mail_ci/run_matrix.py', 'tests/governance/private_mail_ci/run_pending.py', 'tests/governance/private_mail_ci/test_pure_inventory.py', 'tests/governance/test_completion.py', 'tests/governance/test_directory_restore_progress.py', 'tests/governance/test_native_rr_ci_anchor.py', 'tests/governance/test_native_rr_ci_execution.py', 'tests/governance/test_native_rr_ci_provenance.py', 'tests/governance/test_native_rr_ci_runner.py', 'tests/governance/test_native_user_rr_mdl_source.py', 'tests/governance/test_private_mail_native_settings.py', 'tests/governance/test_private_notifications.py', 'tests/governance/test_project_description_ci.py', 'tests/governance/test_recovery_org_lifecycle_gate.py', 'tests/governance/test_recovery_org_metadata_gate.py', 'tests/governance/test_recovery_partial_failure_diagnostic.py', 'tests/governance/test_recovery_policy_isolation.py', 'tests/governance/test_task_wire_parent.py', 'tests/operations/php.Dockerfile', 'tests/permissions/xlsx/composer.json', 'tests/permissions/xlsx/composer.lock', 'tests/project-workspace/README.md'])
CI_V2_NEW_PATHS.add(RELEASE_HISTORY)

# Explicit retirement, not a wildcard omission or archived executable source.
# The manifest binds each absent path to its original parent hash. Restoring an
# old runtime file or omitting any other source still fails the exact inventory.
WIKI_RETIREMENT_MANIFEST='docs/operations/retirement/wiki.json'
WIKI_RETIREMENT_SHA='724e3bbc6b9ffe04578184a3e0c33af1620ef698cbe7281ac21841b3dd46cbea'
WIKI_RETIRED_PATHS=frozenset(['src/applications/phriction/application/PhabricatorPhrictionApplication.php', 'src/applications/phriction/codex/PhrictionDocumentPolicyCodex.php', 'src/applications/phriction/conduit/PhrictionConduitAPIMethod.php', 'src/applications/phriction/conduit/PhrictionContentSearchConduitAPIMethod.php', 'src/applications/phriction/conduit/PhrictionCreateConduitAPIMethod.php', 'src/applications/phriction/conduit/PhrictionDocumentEditConduitAPIMethod.php', 'src/applications/phriction/conduit/PhrictionDocumentSearchConduitAPIMethod.php', 'src/applications/phriction/conduit/PhrictionEditConduitAPIMethod.php', 'src/applications/phriction/conduit/PhrictionHistoryConduitAPIMethod.php', 'src/applications/phriction/conduit/PhrictionInfoConduitAPIMethod.php', 'src/applications/phriction/constants/PhrictionChangeType.php', 'src/applications/phriction/constants/PhrictionConstants.php', 'src/applications/phriction/constants/PhrictionDocumentStatus.php', 'src/applications/phriction/controller/PhrictionController.php', 'src/applications/phriction/controller/PhrictionDeleteController.php', 'src/applications/phriction/controller/PhrictionDiffController.php', 'src/applications/phriction/controller/PhrictionDocumentController.php', 'src/applications/phriction/controller/PhrictionEditController.php', 'src/applications/phriction/controller/PhrictionEditEngineController.php', 'src/applications/phriction/controller/PhrictionHistoryController.php', 'src/applications/phriction/controller/PhrictionListController.php', 'src/applications/phriction/controller/PhrictionMarkupPreviewController.php', 'src/applications/phriction/controller/PhrictionMoveController.php', 'src/applications/phriction/controller/PhrictionNewController.php', 'src/applications/phriction/controller/PhrictionPublishController.php', 'src/applications/phriction/editor/PhrictionDocumentEditEngine.php', 'src/applications/phriction/editor/PhrictionTransactionEditor.php', 'src/applications/phriction/engineextension/PhrictionContentSearchEngineAttachment.php', 'src/applications/phriction/engineextension/PhrictionDatasourceEngineExtension.php', 'src/applications/phriction/engineextension/PhrictionHovercardEngineExtension.php', 'src/applications/phriction/herald/PhrictionDocumentAuthorHeraldField.php', 'src/applications/phriction/herald/PhrictionDocumentContentHeraldField.php', 'src/applications/phriction/herald/PhrictionDocumentHeraldAdapter.php', 'src/applications/phriction/herald/PhrictionDocumentHeraldField.php', 'src/applications/phriction/herald/PhrictionDocumentHeraldFieldGroup.php', 'src/applications/phriction/herald/PhrictionDocumentPathHeraldField.php', 'src/applications/phriction/herald/PhrictionDocumentPublishedHeraldField.php', 'src/applications/phriction/herald/PhrictionDocumentTitleHeraldField.php', 'src/applications/phriction/mail/PhrictionReplyHandler.php', 'src/applications/phriction/markup/PhrictionRemarkupRule.php', 'src/applications/phriction/phid/PhrictionContentPHIDType.php', 'src/applications/phriction/phid/PhrictionDocumentPHIDType.php', 'src/applications/phriction/query/PhrictionContentQuery.php', 'src/applications/phriction/query/PhrictionContentSearchEngine.php', 'src/applications/phriction/query/PhrictionDocumentQuery.php', 'src/applications/phriction/query/PhrictionDocumentSearchEngine.php', 'src/applications/phriction/query/PhrictionTransactionQuery.php', 'src/applications/phriction/search/PhrictionDocumentFerretEngine.php', 'src/applications/phriction/search/PhrictionDocumentFulltextEngine.php', 'src/applications/phriction/storage/PhrictionContent.php', 'src/applications/phriction/storage/PhrictionDAO.php', 'src/applications/phriction/storage/PhrictionDocument.php', 'src/applications/phriction/storage/PhrictionSchemaSpec.php', 'src/applications/phriction/storage/PhrictionTransaction.php', 'src/applications/phriction/storage/PhrictionTransactionComment.php', 'src/applications/phriction/typeahead/PhrictionDocumentDatasource.php', 'src/applications/phriction/xaction/PhrictionDocumentContentTransaction.php', 'src/applications/phriction/xaction/PhrictionDocumentDeleteTransaction.php', 'src/applications/phriction/xaction/PhrictionDocumentDraftTransaction.php', 'src/applications/phriction/xaction/PhrictionDocumentEditTransaction.php', 'src/applications/phriction/xaction/PhrictionDocumentMoveAwayTransaction.php', 'src/applications/phriction/xaction/PhrictionDocumentMoveToTransaction.php', 'src/applications/phriction/xaction/PhrictionDocumentPublishTransaction.php', 'src/applications/phriction/xaction/PhrictionDocumentTitleTransaction.php', 'src/applications/phriction/xaction/PhrictionDocumentTransactionType.php', 'src/applications/phriction/xaction/PhrictionDocumentVersionTransaction.php', 'src/docs/user/userguide/phriction.diviner', 'webroot/rsrc/css/application/phriction/phriction-document-css.css'])
WIKI_RETIREMENT_HISTORY_PATHS=frozenset(['.github/data/retired-reference-exceptions.txt', 'docs/governance/batch-1-contracts.json', 'docs/governance/private-notification-producers.json', 'resources/celerity/map.php', 'resources/sql/autopatches/20141107.phriction.policy.2.php', 'resources/sql/autopatches/20141107.phriction.popkeys.php', 'resources/sql/autopatches/20180215.phriction.02.phidvalues.php', 'resources/sql/autopatches/20180828.phriction.04.migrate.php', 'resources/sql/autopatches/20180828.phriction.06.c.documentid.php', 'resources/sql/autopatches/20180829.phriction.01.mailkey.php', 'resources/sql/autopatches/20180830.phriction.02.maxes.php', 'scripts/governance/check_callers.py', 'scripts/governance/check_completion.py', 'scripts/governance/check_contracts.py', 'scripts/governance/check_private_notifications.py', 'src/applications/config/schema/PhabricatorConfigRetiredSchema.php', 'src/applications/config/schema/__tests__/PhabricatorConfigRetiredSchemaTestCase.php', 'src/applications/metamta/storage/PhabricatorMetaMTAMail.php', 'src/applications/remarkup/conduit/RemarkupProcessConduitAPIMethod.php', 'src/applications/remarkup/content/RemarkupReferenceBasicModule.php', 'src/applications/remarkup/remarkupcontroller/PhabricatorRemarkupControl.php', 'src/applications/search/menuitem/PhabricatorProfileMenuItemIconSet.php', 'src/infrastructure/governance/consistency/PhorgeGovernanceNativeReadView.php', 'src/infrastructure/governance/contracts/PhorgeGovernanceContentDecision.php', 'src/infrastructure/governance/contracts/__tests__/PhorgeGovernanceContentDecisionTestCase.php', 'src/infrastructure/governance/mapping/PhorgeGovernanceCapabilityMap.php', 'src/infrastructure/governance/mapping/__tests__/PhorgeGovernanceCapabilityMapTestCase.php', 'src/infrastructure/governance/native/PhorgeGovernanceNativePolicyCompositionVerifier.php', 'src/infrastructure/governance/native/PhorgeGovernanceNativeRuleHierarchyVerifier.php', 'src/infrastructure/governance/native/PhorgeGovernanceNativeSourceReader.php', 'src/infrastructure/governance/notification/PhorgePrivateMailPolicy.php', 'src/infrastructure/governance/observation/PhorgeGovernanceNativeObservationVerifier.php', 'src/infrastructure/governance/observation/README.md', 'src/infrastructure/governance/observation/__tests__/PhorgeGovernanceNativeObservationTestCase.php', 'src/infrastructure/governance/readset/PhorgeGovernanceReadSetValidator.php', 'src/infrastructure/governance/readset/__tests__/PhorgeGovernanceReadSetTestCase.php', 'src/infrastructure/markup/PhabricatorMarkupEngine.php', 'src/infrastructure/markup/markuprule/PhutilRemarkupDocumentLinkRule.php', 'src/infrastructure/storage/management/workflow/PhabricatorStorageManagementWorkflow.php', 'support/governance/completion/fixture-consistency.php', 'support/governance/completion/pipeline-matrix.json', 'support/governance/completion/pipeline.php', 'support/governance/fixtures/PhorgeGovernanceNativeFixture.php', 'support/governance/fixtures/test-native-pipeline-composition-mysql.php', 'support/governance/fixtures/test-native-rule-branches-mysql.php', 'support/governance/fixtures/test-native-source-mysql.php', 'support/governance/fixtures/test-readset-mysql.php', 'support/governance/notification/native-cases.php', 'tests/contract/runtime/products.php', 'tests/governance/test_completion.py', 'tests/governance/test_private_mail_native_settings.py', 'tests/governance/test_private_notifications.py'])
CI_V2_NEW_PATHS.update(['docs/operations/retirement/wiki.json', 'docs/operations/retirement/wiki.md', 'tests/operations/test_wiki_retirement.py', 'tests/operations/wiki_retirement_mail_fixture.php', 'tests/operations/wiki_retirement_native.php'])

CI_V2_NEW_PATHS.update(['tests/operations/wiki_retirement_http.php', 'tests/operations/wiki_retirement_http_router.php', 'tests/operations/wiki_retirement_migrations.php'])





CI_V2_NEW_PATHS.update({'docs/releases/php-8.5.13-upgrade.md'})









CI_V2_NEW_PATHS.add('tests/release/test_php_security.py')






PHP_SECURITY_HISTORY_PATHS=frozenset(['.github/workflows/php-core-security.yml', '.github/workflows/rebuild-phutil-map.yml', 'DOCKER.md', 'Dockerfile', 'README.md', 'deploy/acceptance/image-lock-provenance.md', 'deploy/acceptance/php_security.php', 'deploy/release/README.md', 'docker/acceptance.Dockerfile', 'docs/governance/batch-1-contracts.md', 'docs/governance/batch-1-implementation.md', 'docs/governance/batch-2-callers.md', 'docs/releases/php-8.5.12-upgrade.md', 'docs/upstream/atomic-required-gates-and-evidence.md', 'scripts/governance/check_api_credential_concurrency.py', 'scripts/governance/check_directory_transports_v1.py', 'scripts/governance/check_native_user_rr_mdl.py', 'scripts/governance/check_organization_approval_workbench.py', 'scripts/governance/check_private_calendar_reminders.py', 'scripts/governance/check_recovery_application_config.py', 'scripts/governance/check_recovery_assessment_credentials.py', 'scripts/governance/check_recovery_assessment_credentials_v2.py', 'scripts/governance/check_recovery_borrowed_session.py', 'scripts/governance/check_recovery_conduit_producer.py', 'scripts/governance/check_recovery_file_parent_policy.py', 'scripts/governance/check_recovery_file_source.py', 'scripts/governance/check_recovery_oauth_access_producer.py', 'scripts/governance/check_recovery_oauth_access_producer_v2.py', 'scripts/governance/check_recovery_oauth_access_producer_v3.py', 'scripts/governance/check_recovery_oauth_authorization_producer_v1.py', 'scripts/governance/check_recovery_oauth_client_lifecycle.py', 'scripts/governance/check_recovery_oauth_code_producer_v1.py', 'scripts/governance/check_recovery_org_lifecycle.py', 'scripts/governance/check_recovery_org_metadata.py', 'scripts/governance/check_recovery_partial_composition.py', 'scripts/governance/check_recovery_policy_isolation.py', 'scripts/governance/check_recovery_project_source.py', 'scripts/governance/check_recovery_session_lease.py', 'scripts/governance/check_recovery_spaces_source.py', 'scripts/governance/check_recovery_task_owner_source.py', 'src/__tests__/PhorgePHPVersionTestCase.php', 'src/docs/user/installation_guide.diviner', 'support/governance/completion/fixture.php', 'support/governance/media-session-ci/prepare.py', 'support/governance/media-session-ci/project.py', 'support/governance/media-session-docker/README.md', 'support/governance/media-session-docker/run.py', 'support/governance/project-content-grants-ci/README.md', 'support/governance/project-description-ci/README.md', 'support/governance/project-description-ci/fixture.php', 'support/governance/project-description-qualification/README.md', 'support/governance/project-description-qualification/native.php', 'support/governance/revocation-measurement/README.md', 'support/runtime/README.md', 'support/runtime/manifest.json', 'support/runtime/php-version.php', 'tests/atomic/README.md', 'tests/governance/private_mail_ci/Dockerfile', 'tests/governance/private_mail_ci/IMAGE-PLAN.md', 'tests/governance/private_mail_ci/README.md', 'tests/governance/private_mail_ci/pure_inventory.py', 'tests/governance/private_mail_ci/run_matrix.py', 'tests/governance/private_mail_ci/run_pending.py', 'tests/governance/private_mail_ci/test_pure_inventory.py', 'tests/governance/test_directory_restore_progress.py', 'tests/governance/test_project_description_ci.py', 'tests/governance/test_recovery_org_lifecycle_gate.py', 'tests/governance/test_recovery_org_metadata_gate.py', 'tests/governance/test_recovery_partial_failure_diagnostic.py', 'tests/governance/test_recovery_policy_isolation.py', 'tests/governance/test_task_wire_parent.py', 'tests/operations/php.Dockerfile', 'tests/permissions/xlsx/composer.json', 'tests/permissions/xlsx/composer.lock', 'tests/project-workspace/README.md'])

# Exact three-source security successor. Prior bytes below remain inert DATA;
# they are never admitted as the new runtime or compiled as application code.
PROXY_IDENTITY_SECURITY_PATHS=frozenset(['src/applications/auth/provider/PhabricatorTraefikAuthProvider.php', 'src/applications/base/controller/PhabricatorController.php', 'src/infrastructure/governance/media/http/PhorgeGovernanceMediaFileGuard.php'])
PROXY_IDENTITY_SECURITY_RECIPE=b'{"base_checkpoint":"0a48f072bbfca5de398f491fb3a1dec63554dd24","files":{"src/applications/auth/provider/PhabricatorTraefikAuthProvider.php":{"current_bytes":9828,"current_sha256":"34d0032c0f8b402a597b936849d4107eb27da5da7f2dca406872810b2f4b9e37","edits":[{"current":"s","offset":2412,"parent":"PhabricatorAuthProvid"},{"current":"lf","offset":2414,"parent":"r"},{"current":"Current","offset":2421,"parent":"All"},{"current":"Proxy","offset":2435,"parent":""},{"current":"","offset":2467,"parent":"\\n      if (!($provider instanceof self)) {\\n        continue;\\n      }"},{"current":";\\n  }\\n\\n  /**\\n   * Match an already authenticated media user to the current gateway identity.\\n   * This trusts the same protected proxy ingress as this authentication provider;\\n   * a client-supplied header alone is not proof of gateway authentication.\\n   */\\n  public static function canUseMediaSessionForProxyIdentity(\\n    PhabricatorUser $user) {\\n\\n    $providers = self::getCurrentEnabledProxyProviders();\\n\\n    // Native password-only installations do not acquire a gateway dependency.\\n    if (!$providers) {\\n      return true;\\n    }\\n\\n    $identity = AphrontRequest::getHTTPHeader(\'X-Auth-User\');\\n    if (!phutil_nonempty_string($identity)) {\\n      return false;\\n    }\\n\\n    foreach ($providers as $provider) {\\n      if ($provider->isSessionIdentityCompatible($user, $identity)) {\\n        return true;\\n      }\\n    }\\n\\n    // The account-linking exception is deliberately unavailable to media reads.\\n    return false;\\n  }\\n\\n  private static function getCurrentEnabledProxyProviders() {\\n    $configs = id(new PhabricatorAuthProviderConfigQuery())\\n      ->setViewer(PhabricatorUser::getOmnipotentUser())\\n      ->withProviderClasses(array(self::class))\\n      ->withIsEnabled(true)\\n      ->execute();\\n\\n    // This final provider has one adapter key. Preserve the ordinary provider\\n    // loader\'s refusal of duplicate keys instead of accepting any mapping.\\n    if (count($configs) > 1) {\\n      throw new RuntimeException(\'proxy_identity_provider_ambiguous\');\\n    }\\n\\n    return mpull($configs, \'getProvider\')","offset":2762,"parent":""}],"target_bytes":8406,"target_sha256":"f22818d852fd78d8a6f442dd78339d1d7e1e405bf832ae4682f0f1bc3fb99d72"},"src/applications/base/controller/PhabricatorController.php":{"current_bytes":20830,"current_sha256":"dbb0b3e683d28c4427e815447eac08522c7867066c32c07be4b87df21969ea02","edits":[{"current":"\\n          // Changing the browser cookie alone leaves media credentials bound\\n          // to the old database session valid. Revoke only the session which\\n          // this request actually authenticated, preserving other browsers.\\n          $unguarded = AphrontWriteGuard::beginScopedUnguardedWrites();\\n          try {\\n            $session_engine->logoutSession(\\n              $session_user,\\n              $session_user->getSession());\\n          } finally {\\n            unset($unguarded);\\n          }","offset":2553,"parent":""}],"target_bytes":20327,"target_sha256":"3d870b9a8d41cfd81b4794ed1bfa3149c8e9df39850f8af500ce9b4d5a0fa354"},"src/infrastructure/governance/media/http/PhorgeGovernanceMediaFileGuard.php":{"current_bytes":4015,"current_sha256":"a8679638a240c7bb436afe46cd1ae46c9311ab6d8b8596276384d8dec30332d4","edits":[{"current":"if(!PhabricatorTraefikAuthProvider::canUseMediaSessionForProxyIdentity($viewer)){\\n      throw new RuntimeException(\'media_file_identity_unavailable\');}\\n    ","offset":2126,"parent":""}],"target_bytes":3859,"target_sha256":"79afd0eb6661c0665d5af8aeba10080ac5accb9244dc33a647a91b91217f0379"}},"not_runtime_authority":true,"schema":"Task-proxy-identity-security-inverse-v1"}\n'
PROXY_IDENTITY_SECURITY_BYTES=3679
PROXY_IDENTITY_SECURITY_SHA='d13dc62dd9901d490c9b26df913ab360bf875db4797b30083e83768d01462cea'
HEADER_EMISSION_SECURITY_PATH='src/aphront/sink/AphrontHTTPSink.php'
HEADER_EMISSION_SECURITY_CURRENT_PIN={'bytes': 5703, 'sha256': 'b8df4a83b8a294248e9e3aa6ad8c88162f70a5120614c2ae0651fd5a9d72f606'}
HEADER_EMISSION_SECURITY_PARENT_PIN={'bytes': 5248, 'sha256': '6a13c3acf1f9e3ed17b7c350b07ab33398824816803315cb0e11810383e428c6'}
HEADER_EMISSION_SECURITY_RECIPE=b'{"base_checkpoint":"06aa03501894381577f3c60d042e35acff2868dc","current_bytes":5703,"current_sha256":"b8df4a83b8a294248e9e3aa6ad8c88162f70a5120614c2ae0651fd5a9d72f606","edits":[{"current":"// Response-specific hardening and default cache headers may both add\\n    // nosniff. Validate the entire input above before suppressing this one\\n    // identical security directive; other repeated headers remain unchanged.\\n    $emitted_nosniff = false;\\n    ","offset":2356,"parent":""},{"current":"\\n      if (strtolower($name) === \'x-content-type-options\' &&\\n          $value === \'nosniff\') {\\n        if ($emitted_nosniff) {\\n          continue;\\n        }\\n        $emitted_nosniff = true;\\n      }","offset":2682,"parent":""}],"not_runtime_authority":true,"path":"src/aphront/sink/AphrontHTTPSink.php","schema":"Task-header-emission-security-inverse-v1","target_bytes":5248,"target_sha256":"6a13c3acf1f9e3ed17b7c350b07ab33398824816803315cb0e11810383e428c6"}\n'
HEADER_EMISSION_SECURITY_BYTES=956
HEADER_EMISSION_SECURITY_SHA='c6d90e69ba91cdbc7eeb3f416df09911c630cacaf9a8298c2746aaa409539d4e'


def need(v):
 if v is not True:raise ValueError('task_SourceOperation_admission_refused')
def sha(raw):return hashlib.sha256(raw).hexdigest()
def stamp(s):return(s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid,s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
def read(path):
 a=path.lstat();need(stat.S_ISREG(a.st_mode)and a.st_uid==os.getuid()and a.st_nlink==1 and not a.st_mode&0o022 and a.st_size<=16777216)
 fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
 try:
  raw=bytearray()
  while len(raw)<a.st_size:
   b=os.read(fd,min(65536,a.st_size-len(raw)));need(bool(b));raw.extend(b)
  need(os.read(fd,1)==b''and stamp(a)==stamp(os.fstat(fd))==stamp(path.lstat()));return bytes(raw),stamp(a)
 finally:os.close(fd)
def decode(raw):
 def pairs(items):
  d={}
  for k,v in items:need(k not in d);d[k]=v
  return d
 return json.loads(raw,object_pairs_hook=pairs,parse_constant=lambda _:need(False))
def validate_release_history(doc):
 need(type(doc)is dict and set(doc)=={'schema','base_checkpoint','files'})
 need(doc['schema']=='Task-release-source-history-literal-inverse-v1'
      and doc['base_checkpoint']=='e3c4632f5ca63b79fb8ec08875a651080cba3d7c')
 files=doc['files'];need(type(files)is dict and set(files)==RELEASE_HISTORY_PATHS)
 total=0
 for relative,row in files.items():
  need(type(relative)is str and type(row)is dict
       and set(row)=={'current_bytes','current_sha256','target_bytes','target_sha256','edits'})
  need(all(type(row[k])is int and 0<row[k]<=16777216 for k in ('current_bytes','target_bytes')))
  need(all(type(row[k])is str and re.fullmatch('[a-f0-9]{64}',row[k])is not None for k in ('current_sha256','target_sha256')))
  edits=row['edits'];need(type(edits)is list and 0<len(edits)<=128)
  previous=-1;current_end=target_end=delta=0
  for edit in edits:
   need(type(edit)is dict and set(edit)=={'offset','current','parent'}
        and type(edit['offset'])is int and type(edit['current'])is type(edit['parent'])is str)
   offset=edit['offset'];old=edit['current'].encode('utf-8');new=edit['parent'].encode('utf-8')
   total+=len(old)+len(new);need(total<=1048576 and old!=new)
   need(offset>previous and offset>=current_end and offset+len(old)<=row['current_bytes'])
   target_offset=offset+delta
   need(target_offset>=target_end and target_offset+len(new)<=row['target_bytes'])
   previous=offset;current_end=offset+len(old);target_end=target_offset+len(new)
   delta+=len(new)-len(old)
  need(row['current_bytes']+delta==row['target_bytes'])


def load_release_history(raw):
 need(type(raw)is bytes and len(raw)==RELEASE_HISTORY_BYTES and sha(raw)==RELEASE_HISTORY_SHA)
 doc=decode(raw);validate_release_history(doc);return doc


def release_history_roundtrip(relative,target,doc):
 # Independent forward reconstruction, using target offsets derived from the
 # original current offsets. No historical payload is compiled or executed.
 validate_release_history(doc);need(relative in RELEASE_HISTORY_PATHS and type(target)is bytes)
 row=doc['files'][relative]
 need(len(target)==row['target_bytes']and sha(target)==row['target_sha256'])
 parts=[];cursor=delta=0
 for edit in row['edits']:
  old=edit['current'].encode('utf-8');new=edit['parent'].encode('utf-8');offset=edit['offset']+delta
  need(target[offset:offset+len(new)]==new)
  parts.extend((target[cursor:offset],old));cursor=offset+len(new);delta+=len(new)-len(old)
 result=b''.join(parts)+target[cursor:]
 need(len(result)==row['current_bytes']and sha(result)==row['current_sha256']);return result


def release_history_inverse(relative,current,doc):
 validate_release_history(doc);need(relative in RELEASE_HISTORY_PATHS and type(current)is bytes)
 row=doc['files'][relative]
 need(len(current)==row['current_bytes']and sha(current)==row['current_sha256'])
 parts=[];cursor=0
 for edit in row['edits']:
  offset=edit['offset'];old=edit['current'].encode('utf-8');new=edit['parent'].encode('utf-8')
  need(current[offset:offset+len(old)]==old)
  parts.extend((current[cursor:offset],new));cursor=offset+len(old)
 target=b''.join(parts)+current[cursor:]
 need(len(target)==row['target_bytes']and sha(target)==row['target_sha256'])
 need(release_history_roundtrip(relative,target,doc)==current);return target


def validate_proxy_identity_security_history(doc):
 need(type(doc)is dict and set(doc)=={'schema','base_checkpoint','not_runtime_authority','files'})
 canonical=json.dumps(doc,sort_keys=True,separators=(',',':')).encode()+b'\n'
 need(len(canonical)==PROXY_IDENTITY_SECURITY_BYTES and sha(canonical)==PROXY_IDENTITY_SECURITY_SHA)
 need(doc['schema']=='Task-proxy-identity-security-inverse-v1'and doc['base_checkpoint']=='0a48f072bbfca5de398f491fb3a1dec63554dd24'and doc['not_runtime_authority']is True)
 files=doc['files'];need(type(files)is dict and set(files)==PROXY_IDENTITY_SECURITY_PATHS)
 total=0
 for path,row in files.items():
  need(type(row)is dict and set(row)=={'current_bytes','current_sha256','target_bytes','target_sha256','edits'})
  need(all(type(row[k])is int and 0<row[k]<=16777216 for k in ('current_bytes','target_bytes')))
  need(all(type(row[k])is str and re.fullmatch('[a-f0-9]{64}',row[k])is not None for k in ('current_sha256','target_sha256')))
  need(row['current_sha256']!=row['target_sha256'])
  edits=row['edits'];need(type(edits)is list and 0<len(edits)<=64)
  previous=-1;current_end=target_end=delta=0
  for edit in edits:
   need(type(edit)is dict and set(edit)=={'offset','current','parent'}and type(edit['offset'])is int and type(edit['current'])is type(edit['parent'])is str)
   old=edit['current'].encode();new=edit['parent'].encode();offset=edit['offset'];total+=len(old)+len(new)
   need(total<=65536 and old!=new and offset>previous and offset>=current_end and offset+len(old)<=row['current_bytes'])
   target_offset=offset+delta;need(target_offset>=target_end and target_offset+len(new)<=row['target_bytes'])
   previous=offset;current_end=offset+len(old);target_end=target_offset+len(new);delta+=len(new)-len(old)
  need(row['current_bytes']+delta==row['target_bytes'])
 return doc

def load_proxy_identity_security_history(raw=None):
 if raw is None:raw=PROXY_IDENTITY_SECURITY_RECIPE
 need(type(raw)is bytes and len(raw)==PROXY_IDENTITY_SECURITY_BYTES and sha(raw)==PROXY_IDENTITY_SECURITY_SHA)
 return validate_proxy_identity_security_history(decode(raw))

def proxy_identity_security_roundtrip(relative,target,doc=None):
 if doc is None:doc=load_proxy_identity_security_history()
 validate_proxy_identity_security_history(doc);need(relative in PROXY_IDENTITY_SECURITY_PATHS and type(target)is bytes)
 row=doc['files'][relative];need(len(target)==row['target_bytes']and sha(target)==row['target_sha256'])
 parts=[];cursor=delta=0
 for edit in row['edits']:
  old=edit['current'].encode();new=edit['parent'].encode();offset=edit['offset']+delta
  need(target[offset:offset+len(new)]==new)
  parts.extend((target[cursor:offset],old));cursor=offset+len(new);delta+=len(new)-len(old)
 current=b''.join(parts)+target[cursor:]
 need(len(current)==row['current_bytes']and sha(current)==row['current_sha256']);return current

def proxy_identity_security_inverse(relative,current,doc=None):
 if doc is None:doc=load_proxy_identity_security_history()
 validate_proxy_identity_security_history(doc);need(relative in PROXY_IDENTITY_SECURITY_PATHS and type(current)is bytes)
 row=doc['files'][relative];need(len(current)==row['current_bytes']and sha(current)==row['current_sha256'])
 parts=[];cursor=0
 for edit in row['edits']:
  old=edit['current'].encode();new=edit['parent'].encode();offset=edit['offset']
  need(current[offset:offset+len(old)]==old)
  parts.extend((current[cursor:offset],new));cursor=offset+len(old)
 target=b''.join(parts)+current[cursor:]
 need(len(target)==row['target_bytes']and sha(target)==row['target_sha256'])
 need(proxy_identity_security_roundtrip(relative,target,doc)==current);return target

def proxy_identity_security_cohort(current):
 # All selected runtime leaves must be the new secure cohort, including when an
 # old leaf is byte-valid for the historical reducers. No mixed/old fallback.
 need(type(current)is dict and set(current)==PROXY_IDENTITY_SECURITY_PATHS)
 doc=load_proxy_identity_security_history()
 return {path:proxy_identity_security_inverse(path,current[path],doc)for path in sorted(PROXY_IDENTITY_SECURITY_PATHS)}

def validate_header_emission_security_history(doc):
 need(type(doc)is dict and set(doc)=={'schema','base_checkpoint','path','not_runtime_authority','current_bytes','current_sha256','target_bytes','target_sha256','edits'})
 canonical=json.dumps(doc,sort_keys=True,separators=(',',':')).encode()+b'\n'
 need(len(canonical)==HEADER_EMISSION_SECURITY_BYTES and sha(canonical)==HEADER_EMISSION_SECURITY_SHA)
 need(doc['schema']=='Task-header-emission-security-inverse-v1'and doc['base_checkpoint']=='06aa03501894381577f3c60d042e35acff2868dc'and doc['path']==HEADER_EMISSION_SECURITY_PATH and doc['not_runtime_authority']is True)
 for role,pin in (('current',HEADER_EMISSION_SECURITY_CURRENT_PIN),('target',HEADER_EMISSION_SECURITY_PARENT_PIN)):
  need(type(doc[role+'_bytes'])is int and doc[role+'_bytes']==pin['bytes']and doc[role+'_sha256']==pin['sha256'])
 edits=doc['edits'];need(type(edits)is list and 0<len(edits)<=16)
 previous=-1;current_end=target_end=delta=total=0
 for edit in edits:
  need(type(edit)is dict and set(edit)=={'offset','current','parent'}and type(edit['offset'])is int and type(edit['current'])is type(edit['parent'])is str)
  old=edit['current'].encode();new=edit['parent'].encode();offset=edit['offset'];total+=len(old)+len(new)
  need(total<=32768 and old!=new and offset>previous and offset>=current_end and offset+len(old)<=doc['current_bytes'])
  target_offset=offset+delta;need(target_offset>=target_end and target_offset+len(new)<=doc['target_bytes'])
  previous=offset;current_end=offset+len(old);target_end=target_offset+len(new);delta+=len(new)-len(old)
 need(doc['current_bytes']+delta==doc['target_bytes']);return doc

def load_header_emission_security_history(raw=None):
 if raw is None:raw=HEADER_EMISSION_SECURITY_RECIPE
 need(type(raw)is bytes and len(raw)==HEADER_EMISSION_SECURITY_BYTES and sha(raw)==HEADER_EMISSION_SECURITY_SHA)
 return validate_header_emission_security_history(decode(raw))

def header_emission_security_roundtrip(relative,target,doc=None):
 if doc is None:doc=load_header_emission_security_history()
 validate_header_emission_security_history(doc);need(relative==HEADER_EMISSION_SECURITY_PATH and type(target)is bytes)
 need(len(target)==doc['target_bytes']and sha(target)==doc['target_sha256'])
 parts=[];cursor=delta=0
 for edit in doc['edits']:
  old=edit['current'].encode();new=edit['parent'].encode();offset=edit['offset']+delta
  need(target[offset:offset+len(new)]==new)
  parts.extend((target[cursor:offset],old));cursor=offset+len(new);delta+=len(new)-len(old)
 result=b''.join(parts)+target[cursor:]
 need(len(result)==doc['current_bytes']and sha(result)==doc['current_sha256']);return result

def header_emission_security_inverse(relative,current,doc=None):
 # The prior Sink is authenticated DATA, never an admitted runtime fallback.
 if doc is None:doc=load_header_emission_security_history()
 validate_header_emission_security_history(doc);need(relative==HEADER_EMISSION_SECURITY_PATH and type(current)is bytes)
 need(len(current)==doc['current_bytes']and sha(current)==doc['current_sha256'])
 parts=[];cursor=0
 for edit in doc['edits']:
  old=edit['current'].encode();new=edit['parent'].encode();offset=edit['offset']
  need(current[offset:offset+len(old)]==old)
  parts.extend((current[cursor:offset],new));cursor=offset+len(old)
 target=b''.join(parts)+current[cursor:]
 need(len(target)==doc['target_bytes']and sha(target)==doc['target_sha256'])
 need(header_emission_security_roundtrip(relative,target,doc)==current);return target

def task_preupgrade_fixture_inverse(relative,raw,recipe_raw):
 """Pure fixed DATA inverse; never executes a fixture, recipe, or source code."""
 need(type(relative)is str and relative==TASK_PREUPGRADE_FIXTURE)
 need(type(raw)is bytes and len(raw)==TASK_PREUPGRADE_CURRENT_PIN['bytes']and sha(raw)==TASK_PREUPGRADE_CURRENT_PIN['sha256'])
 need(type(recipe_raw)is bytes and len(recipe_raw)==TASK_PREUPGRADE_RECIPE_PIN['bytes']and sha(recipe_raw)==TASK_PREUPGRADE_RECIPE_PIN['sha256'])
 recipe=decode(recipe_raw)
 need(type(recipe)is dict and set(recipe)=={'schema','path','preimage_sha256','current_sha256','edits','exact_preimage_reconstructed','not_an_admission_grant'})
 need(recipe['schema']=='Task-preupgrade-fixture-literal-inverse-recipe-v1'and recipe['path']==TASK_PREUPGRADE_FIXTURE and recipe['preimage_sha256']==TASK_PREUPGRADE_PARENT_PIN['sha256']and recipe['current_sha256']==TASK_PREUPGRADE_CURRENT_PIN['sha256']and recipe['exact_preimage_reconstructed']is True and recipe['not_an_admission_grant']is True)
 edits=recipe['edits'];names=('private_stage_fields','closed_versioned_methods','explicit_preTask_audit_branch')
 need(type(edits)is list and len(edits)==len(names))
 restored=raw
 for edit,name in zip(edits,names):
  need(type(edit)is dict and set(edit)=={'name','old','added'}and edit['name']==name and edit['old']==''and type(edit['added'])is str and bool(edit['added']))
  addition=edit['added'].encode('utf-8');need(restored.count(addition)==1)
  restored=restored.replace(addition,b'',1)
 need(len(restored)==TASK_PREUPGRADE_PARENT_PIN['bytes']and sha(restored)==TASK_PREUPGRADE_PARENT_PIN['sha256'])
 return restored

# Exact directory-product compatibility DATA. This grants no source permit;
# CURRENT code still requires the unchanged project signature and whole-tree
# capture. Historical strings are only compared/reconstructed, never executed.
DIRECTORY_PRODUCT_HISTORY='support/governance/source-operation/directory-product-inverse-v1.json'
DIRECTORY_PRODUCT_HISTORY_SHA='a1c97dcf4dfb30a90af61e8135d72f5d734fb5db1381265ce86e0e52e45a0edb'
DIRECTORY_PRODUCT_HISTORY_BYTES=49754
DIRECTORY_PRODUCT_PATHS=frozenset(('.github/workflows/atomic-feature-regressions.yml', 'docs/governance/batch-2-callers.json', 'docs/governance/private-notification-producers.json', 'src/applications/people/controller/PhabricatorPeopleProfileViewController.php', 'src/applications/people/governance/README.md', 'src/applications/people/governance/__tests__/PhorgeOrganizationRequestTestCase.php', 'src/applications/people/governance/controller/PhorgeOrganizationController.php', 'src/applications/people/governance/query/PhorgeOrganizationQuery.php', 'src/applications/people/governance/service/PhorgeOrganizationEndpoint.php', 'src/applications/people/governance/service/PhorgeOrganizationRequest.php', 'src/applications/people/governance/service/PhorgeOrganizationService.php', 'src/infrastructure/internationalization/translation/PhabricatorChineseTranslation.php', 'tests/governance/native_rr_ci/execution.py', 'tests/governance/test_native_directory_task_static_v2.py', 'tests/governance/test_native_rr_ci_execution.py', 'tests/governance/test_native_rr_ci_runner.py', 'tests/governance/test_native_user_rr_mdl_source.py'))
DIRECTORY_PRODUCT_NEW_PINS={'docs/governance/directory-membership-tools.md': {'bytes': 6024, 'sha256': '22fa15ba6e9b07d17155c9450a6c764380ff3191145ab72ce3506581bc44da90', 'mode': 420}, 'support/governance/native-organization/directory-product-browser-fixture.php': {'bytes': 4816, 'sha256': '39b23611518f76b250a3c4c2771928939ed1b242da79bbca06a140a27361debd', 'mode': 420}, 'support/governance/native-organization/directory-product-cases.php': {'bytes': 16243, 'sha256': '499af9772f41bbbed6dcc9c399ef466fcb74a3d7b82bb63d95898683dc875115', 'mode': 420}, 'support/governance/native-organization/directory-product-http-router.php': {'bytes': 6497, 'sha256': 'a6eeac29c9cb55c090e6cfb485a4ac96ffe0acc56b4e3175b2190ab293d37d73', 'mode': 420}, 'support/governance/native-organization/directory-product-run.php': {'bytes': 11487, 'sha256': 'd14aea70a4c315ccd2f182b3187b9637ac3831ba15e7ac62fcb7d4aa70253893', 'mode': 420}, 'support/governance/source-operation/directory-product-inverse-v1.json': {'bytes': 49754, 'sha256': 'a1c97dcf4dfb30a90af61e8135d72f5d734fb5db1381265ce86e0e52e45a0edb', 'mode': 420}, 'tests/governance/directory_product/browser.cjs': {'bytes': 6770, 'sha256': '3832f69ffb70c3566621f74a949bde8dd706df624fe257aed301bf0d2ad6de1e', 'mode': 420}, 'tests/governance/directory_product/docker_relay.py': {'bytes': 2521, 'sha256': 'd07b53802be2849036617b53b708ced0f1c361f3f054aab350e76a31a0b5b3cc', 'mode': 420}, 'tests/governance/directory_product/run.py': {'bytes': 9360, 'sha256': 'b9ac99b9ff205954b4550aa8218bf299e0a62470269e9ce79c9cda5c983ec327', 'mode': 420}, 'tests/governance/directory_product/test_runner.py': {'bytes': 2827, 'sha256': '71f3859733098b434fd3a5d6d8fcb5880fdde85864140ecb7ed1f0c4bedd546b', 'mode': 420}, 'tests/governance/test_directory_product_source_cohort.py': {'bytes': 2685, 'sha256': 'e8b211a8290fb31eced62f61b7abc79ec2672ee7da545e44222e21dc65e1883e', 'mode': 420}}

def directory_product_history(raw):
 need(type(raw)is bytes and len(raw)==DIRECTORY_PRODUCT_HISTORY_BYTES and sha(raw)==DIRECTORY_PRODUCT_HISTORY_SHA)
 doc=decode(raw)
 need(type(doc)is dict and set(doc)=={'schema','base_checkpoint','not_runtime_authority','files'})
 need(doc['schema']=='directory-product-source-inverse-v1'and doc['base_checkpoint']=='5762c577170ba16f274775ae3ef99e4c5005603d'and doc['not_runtime_authority']is True)
 need(type(doc['files'])is dict and set(doc['files'])==DIRECTORY_PRODUCT_PATHS)
 for item in doc['files'].values():
  need(type(item)is dict and set(item)=={'parent_bytes','parent_sha256','current_bytes','current_sha256','edits'})
  need(type(item['edits'])is list and bool(item['edits']))
  for edit in item['edits']:
   need(type(edit)is dict and set(edit)=={'parent_offset','current_offset','parent','current'})
   need(type(edit['parent_offset'])is int and edit['parent_offset']>=0 and type(edit['current_offset'])is int and edit['current_offset']>=0)
   need(type(edit['parent'])is str and type(edit['current'])is str and edit['parent']!=edit['current'])
 return doc

def _directory_product_apply(relative,value,raw,reverse):
 doc=directory_product_history(raw)
 need(relative in DIRECTORY_PRODUCT_PATHS and type(value)is bytes)
 item=doc['files'][relative];origin='current'if reverse else'parent';target='parent'if reverse else'current'
 need(len(value)==item[origin+'_bytes']and sha(value)==item[origin+'_sha256'])
 pieces=[];offset=0
 for edit in item['edits']:
  start=edit[origin+'_offset'];old=edit[origin].encode('utf-8');new=edit[target].encode('utf-8')
  need(offset<=start<=len(value)and value[start:start+len(old)]==old)
  pieces.extend((value[offset:start],new));offset=start+len(old)
 pieces.append(value[offset:]);result=b''.join(pieces)
 need(len(result)==item[target+'_bytes']and sha(result)==item[target+'_sha256']);return result

def directory_product_roundtrip(relative,parent,raw):
 return _directory_product_apply(relative,parent,raw,False)

def directory_product_inverse(relative,current,raw):
 parent=_directory_product_apply(relative,current,raw,True)
 need(directory_product_roundtrip(relative,parent,raw)==current);return parent

def directory_product_cohort(current,raw):
 need(type(current)is dict and set(current)==DIRECTORY_PRODUCT_PATHS)
 return {name:directory_product_inverse(name,current[name],raw)for name in sorted(DIRECTORY_PRODUCT_PATHS)}

PHP86_COMPATIBILITY_HISTORY='support/governance/source-operation/php86-isolated-inverse-v1.json'
PHP86_COMPATIBILITY_HISTORY_SHA='3de3de6d5700011237a344ef134e6eee2533bd273b00e1fd042e7407ae02caef'
PHP86_COMPATIBILITY_HISTORY_BYTES=24414
PHP86_COMPATIBILITY_PATHS=frozenset(('.github/workflows/atomic-feature-regressions.yml', 'scripts/governance/check_full_native_task_schema_receipt_v2.py', 'scripts/governance/check_native_organization.py', 'scripts/governance/current_native_pair_metadata_v1.py', 'scripts/governance/full_native_task_strict_body_v2.py', 'scripts/governance/oauth_task_strict_body_v2.py', 'scripts/governance/project_description_admission_delta.py', 'support/runtime/manifest.json', 'support/runtime/php-version.php', 'support/runtime/src/filesystem/Filesystem.php', 'tests/atomic/extract_native_renderer.py', 'tests/ci/test_core_focused_tiers.py', 'tests/ci/test_core_workflow.py', 'tests/governance/assessment_legacy_DATA_model_v3.py', 'tests/governance/directory_product/run.py', 'tests/governance/native_rr_ci/execution.py', 'tests/governance/test_current_native_pair_metadata_v1.py'))
PHP86_COMPATIBILITY_NEW_PINS={'.github/workflows/php86-isolated-compatibility.yml': {'bytes': 18610, 'sha256': 'c7489d938aa18a25d4072c1ccf8143bb0d7e2cbf50fd0773a304bf4b6ab51ead', 'mode': 420}, 'docs/releases/php86-isolated-compatibility.md': {'bytes': 3576, 'sha256': 'eb70dca47b5477670ec775b8b2ff88395a35a075718da4ccf00512f6a3cf966d', 'mode': 420}, 'scripts/runtime/php86-candidate-dockerfile.py': {'bytes': 2144, 'sha256': 'e1885d616a8fd938df6f34d63e77bec28107d359704715974e980163c9386fa8', 'mode': 420}, 'scripts/runtime/verify-php86-candidate.php': {'bytes': 1146, 'sha256': '72879974d51c92a079b60d48ce464097b92e23610c236239d952c3fc8aca75f0', 'mode': 420}, 'support/governance/source-operation/php86-isolated-inverse-v1.json': {'bytes': 24414, 'sha256': '3de3de6d5700011237a344ef134e6eee2533bd273b00e1fd042e7407ae02caef', 'mode': 420}, 'tests/operations/php86_runtime_controls.php': {'bytes': 4790, 'sha256': '0e6b7f27e97b1a2249bd681f23f94a577fadfe679afea8ec09f1d13f225c9845', 'mode': 420}, 'tests/runtime86/test_controls.py': {'bytes': 4826, 'sha256': '9f24b3f4beffb4114b161c273c8342561c47f1113b6438fa563078d38168f408', 'mode': 420}, 'tests/runtime86/test_source_cohort.py': {'bytes': 2721, 'sha256': '605ed550b6392d2dfbf20687b6323c7e8c95d21d23c1c46db1c1bf3307fdc190', 'mode': 420}}

def php86_compatibility_history(raw):
 need(type(raw)is bytes and len(raw)==PHP86_COMPATIBILITY_HISTORY_BYTES and sha(raw)==PHP86_COMPATIBILITY_HISTORY_SHA)
 doc=decode(raw)
 need(type(doc)is dict and set(doc)=={'schema','base_checkpoint','not_runtime_authority','files'})
 need(doc['schema']=='php86-isolated-source-inverse-v1'and doc['base_checkpoint']=='212eb2a65d12a0fc3d07edc3acff9077de3437d7'and doc['not_runtime_authority']is True)
 need(type(doc['files'])is dict and set(doc['files'])==PHP86_COMPATIBILITY_PATHS)
 for item in doc['files'].values():
  need(type(item)is dict and set(item)=={'parent_bytes','parent_sha256','current_bytes','current_sha256','edits'})
  need(type(item['edits'])is list and bool(item['edits']))
  for edit in item['edits']:
   need(type(edit)is dict and set(edit)=={'parent_offset','current_offset','parent','current'})
   need(type(edit['parent_offset'])is int and edit['parent_offset']>=0 and type(edit['current_offset'])is int and edit['current_offset']>=0)
   need(type(edit['parent'])is str and type(edit['current'])is str and edit['parent']!=edit['current'])
 return doc

def _php86_compatibility_apply(relative,value,raw,reverse):
 doc=php86_compatibility_history(raw)
 need(relative in PHP86_COMPATIBILITY_PATHS and type(value)is bytes)
 item=doc['files'][relative];origin='current'if reverse else'parent';target='parent'if reverse else'current'
 need(len(value)==item[origin+'_bytes']and sha(value)==item[origin+'_sha256'])
 pieces=[];offset=0
 for edit in item['edits']:
  start=edit[origin+'_offset'];old=edit[origin].encode('utf-8');new=edit[target].encode('utf-8')
  need(offset<=start<=len(value)and value[start:start+len(old)]==old)
  pieces.extend((value[offset:start],new));offset=start+len(old)
 pieces.append(value[offset:]);result=b''.join(pieces)
 need(len(result)==item[target+'_bytes']and sha(result)==item[target+'_sha256']);return result

def php86_compatibility_roundtrip(relative,parent,raw):
 return _php86_compatibility_apply(relative,parent,raw,False)

def php86_compatibility_inverse(relative,current,raw):
 parent=_php86_compatibility_apply(relative,current,raw,True)
 need(php86_compatibility_roundtrip(relative,parent,raw)==current);return parent

def php86_compatibility_cohort(current,raw):
 need(type(current)is dict and set(current)==PHP86_COMPATIBILITY_PATHS)
 return {name:php86_compatibility_inverse(name,current[name],raw)for name in sorted(PHP86_COMPATIBILITY_PATHS)}

def git_tree(root,expected_checkpoint):
 need(type(expected_checkpoint)is str and re.fullmatch('[a-f0-9]{40}',expected_checkpoint)is not None)
 env={'PATH':'/usr/local/bin:/opt/homebrew/bin:/usr/bin:/bin','HOME':str(Path.home()),'LANG':'C','GIT_NO_REPLACE_OBJECTS':'1','GIT_OPTIONAL_LOCKS':'0'}
 def git(*args,**kw):return subprocess.run(['git','-C',str(root),*args],env=env,stdin=None if'input'in kw else subprocess.DEVNULL,capture_output=True,timeout=30,check=True,**kw)
 revision=git('rev-parse','HEAD').stdout.decode().strip();need(revision==expected_checkpoint)
 try:
  verified=git('verify-commit','--raw',revision)
  need(re.search(rb'^\[GNUPG:\] (?:BADSIG|ERRSIG|EXPSIG|EXPKEYSIG|REVKEYSIG|KEYREVOKED|SIGEXPIRED|KEYEXPIRED)(?: |$)',verified.stderr,re.M)is None)
  need(re.findall(rb'^\[GNUPG:\] VALIDSIG ([A-F0-9]{40}) ',verified.stderr,re.M)==[ROOT_SIGNER.encode()])
 except (ValueError,subprocess.CalledProcessError):
  # External CI only: independently reverify the exact merge proof with the
  # fixed public-only trust home. Never consume a boolean admission receipt.
  tag=os.environ.get('PHORGE_CI_MERGE_SOURCE_PROOF')
  need(tag=='source-proof/'+revision)
  import check_task_ci_head_v1 as admission
  need(admission.SIGNER==ROOT_SIGNER)
  admission.verify_checkout(root,revision,trust_home=str(Path.home()),external_proof_tag=tag)
 git('merge-base','--is-ancestor',BASE,revision);git('diff','--quiet',revision,'--');git('diff','--cached','--quiet',revision,'--')
 rows=[];names=set()
 for item in git('ls-tree','-rz','--full-tree',revision).stdout.split(b'\0'):
  if not item:continue
  a,p=item.split(b'\t',1);mode,kind,oid=a.decode().split();p=p.decode();need(kind=='blob'and mode in('100644','100755','120000')and p not in names);names.add(p);rows.append((p,mode,oid))
 need(len(rows)<=12000);batch=git('cat-file','--batch',input=('\n'.join(r[2]for r in rows)+'\n').encode()).stdout;need(len(batch)<=MAX+len(rows)*128)
 entries={};raws={};offset=0
 for p,mode,oid in rows:
  end=batch.find(b'\n',offset);need(offset<=end<offset+128);h=batch[offset:end].decode().split();need(len(h)==3 and h[0]==oid and h[1]=='blob'and re.fullmatch('0|[1-9][0-9]*',h[2])is not None);n=int(h[2]);need(n<=16777216);offset=end+1;raw=batch[offset:offset+n];offset+=n;need(len(raw)==n and batch[offset:offset+1]==b'\n');offset+=1
  entries[p]={'kind':'symlink','target':raw.decode(),'sha256':sha(raw)}if mode=='120000'else{'kind':'file','mode':int(mode[-3:],8),'bytes':n,'sha256':sha(raw)};raws[p]=raw
 need(offset==len(batch));return revision,entries,raws

class TaskContext:
 def __init__(self,operation,root,entries,git_raws):
  self.operation=operation;self.root=Path(root);self.entries=entries;self.files={};self.directories={};self.current_raw={};self.closed=False
  self._path_cache_root=self.root;paths={};file_parents={};parent_chains={};ancestor_objects={}
  for rel,pin in entries.items():
   path=self.root/rel;paths[rel]=path
   directory=path.parent
   if directory not in parent_chains:
    parent_chains[directory]=tuple(ancestor_objects.setdefault(parent,parent)for parent in path.parents)
    # Previously encountered chains contain only already populated directory
    # keys. Skip repeated pure membership queries, never filesystem checks.
    for parent in parent_chains[directory]:
     if parent not in self.directories:
      s=parent.lstat();need(stat.S_ISDIR(s.st_mode)and not parent.is_symlink());self.directories[parent]=self.directory_stamp(parent,s)
   file_parents[rel]=parent_chains[directory]
   if pin['kind']=='file':
    raw,identity=read(path);need(stat.S_IMODE(identity[2])==pin['mode']and len(raw)==pin['bytes']and sha(raw)==pin['sha256']);self.current_raw[rel]=raw
   else:
    st=path.lstat();need(stat.S_ISLNK(st.st_mode)and os.readlink(path)==pin['target']);identity=stamp(st)
   self.files[rel]=identity
  self._captured_paths=MappingProxyType(paths);self._captured_file_parents=MappingProxyType(file_parents)
  self._directory_scopes_root=self.root
  self._directory_scopes=MappingProxyType({path:path.is_relative_to(self.root)for path in self.directories})
  need(sha(self.current_raw[BASELINE])==BASELINE_SHA and sha(self.current_raw[INVERSE])==INVERSE_SHA)
  self.parent_pins=decode(self.current_raw[BASELINE])['entries'];self.api_inverse=decode(self.current_raw[INVERSE]);self.reduced={}
  retirement_raw=self.current_raw[WIKI_RETIREMENT_MANIFEST]
  need(sha(retirement_raw)==WIKI_RETIREMENT_SHA)
  retirement=decode(retirement_raw)
  need(retirement['schema']=='phorge-application-retirement-v1')
  removed=retirement['removed_files']
  need(type(removed)is list and len(removed)==len(WIKI_RETIRED_PATHS)
       and {row['path'] for row in removed}==WIKI_RETIRED_PATHS)
  for row in removed:
   need(set(row)=={'path','sha256'} and row['path'] in self.parent_pins
        and self.parent_pins[row['path']]['kind']=='file'
        and self.parent_pins[row['path']]['sha256']==row['sha256'])

  need(self.parent_pins.get(HEADER_EMISSION_SECURITY_PATH)==dict(HEADER_EMISSION_SECURITY_PARENT_PIN,kind='file',mode=0o644))
  header_predecessor=header_emission_security_inverse(HEADER_EMISSION_SECURITY_PATH,self.current_raw[HEADER_EMISSION_SECURITY_PATH])
  need(PROXY_IDENTITY_SECURITY_PATHS.issubset(self.parent_pins))
  proxy_predecessor=proxy_identity_security_cohort({path:self.current_raw[path]for path in PROXY_IDENTITY_SECURITY_PATHS})
  release_history=load_release_history(self.current_raw[RELEASE_HISTORY])
  need(sha(self.current_raw[CI_INVERSE])==CI_INVERSE_SHA)
  ci=decode(self.current_raw[CI_INVERSE]);need(set(ci)=={'schema','parent_revision','files'}and ci['schema']=='Task-CI-modified-parent5903-v1'and ci['parent_revision']=='5903d98b14926401f0be49cb0a9d6168026a2642')
  self.ci_inverse=ci['files'];need(set(self.ci_inverse)=={'tests/governance/test_recovery_spaces_source_gate.py', 'tests/governance/test_restore_current_schema_profile.py', 'tests/governance/test_recovery_borrowed_native_producer.py', 'tests/governance/test_application_config_atomic_ci.py', 'scripts/governance/check_native_organization.py', '.github/workflows/atomic-feature-regressions.yml', 'tests/atomic/test_current_pair_consumers.py', 'tests/governance/test_recovery_session_lease_ci.py', 'tests/governance/recovery_oauth_code_test_cohort.py', 'tests/governance/test_task_owner_atomic_ci.py', 'tests/governance/test_recovery_oauth_access_sql.py', 'src/applications/auth/__tests__/PhabricatorTraefikAuthProviderTestCase.php', 'deploy/acceptance/build-lock.json', 'tests/governance/test_recovery_oauth_authorization_coordination.py', 'support/governance/notification/wiki-render-service.php', 'tests/governance/test_private_calendar_sql_gate.py', 'tests/governance/test_notice_native_admin_wire_source.py', 'tests/governance/test_callers.py', 'tests/governance/test_n05_r3_historical_test_view.py', 'tests/governance/test_directory_transports_receipt_v1.py', 'tests/governance/test_recovery_session_lease_gate.py', 'tests/governance/test_recovery_conduit_participant.py', 'tests/governance/test_native_directory.py', 'tests/governance/test_media_fixture_contentgrant_migration.py', 'tests/governance/test_directory_terminal_failure_v1.py', 'tests/governance/test_directory_transport_failure_v1.py', 'tests/governance/test_organization_approval_workbench_atomic_ci.py', 'tests/governance/test_recovery_borrowed_wiring.py', 'tests/governance/test_project_content_grant_source_cohort.py', 'tests/governance/test_source_operation_snapshot.py', 'tests/governance/test_recovery_oauth_code_ci.py', '.github/workflows/private-mail-wire.yml', '.github/workflows/native-media-session-positive-sql.yml', 'tests/governance/test_project_content_grant_ci_runtime.py', 'tests/governance/test_recovery_assessment_current.py', 'tests/governance/test_project_content_grant_ci_workflow.py', 'tests/governance/test_recovery_oauth_authorization_mysql84.py', 'tests/governance/test_file_source_atomic_ci.py', 'tests/atomic/packages/D01.json', 'tests/governance/test_recovery_oauth_authorization_sql.py', 'scripts/governance/project_description_admission_delta.py', 'tests/governance/test_recovery_borrowed_session.py', 'tests/atomic/paired-gorge.json', 'src/applications/config/schema/PhabricatorConfigSchemaSpec.php', 'tests/governance/test_native_worker_terminal.py', '.github/workflows/oauth-access-producer-focused.yml', '.github/workflows/project-content-grants-focused.yml', 'tests/governance/test_recovery_conduit_wiring.py', 'tests/governance/test_private_calendar_wire.py', 'tests/governance/test_recovery_borrowed_worker_diagnostic.py', 'support/governance/project-content-grants-ci/ci.py', 'tests/governance/test_recovery_assessment_credentials.py', 'tests/governance/test_recovery_oauth_access_source.py', 'tests/governance/test_recovery_oauth_authorization_ci.py', 'tests/governance/test_recovery_session_actor_order.py', 'tests/governance/test_notice_history_lifecycle.py', 'tests/governance/test_recovery_file_parent_ci.py', '.github/workflows/oauth-authorization-producer-focused.yml', 'tests/atomic/extract_native_renderer.py', '.github/workflows/conduit-producer-focused.yml', 'tests/governance/test_project_content_grant_target_binding.py', 'tests/governance/test_calendar_conduit_union.py', 'docs/governance/batch-2-callers.json', 'tests/governance/test_api_concurrency_atomic_ci.py', '.github/workflows/project-description-focused.yml', 'tests/governance/test_recovery_oauth_code_sql.py', '.github/workflows/native-directory-transports-v1.yml', 'tests/governance/test_project_source_atomic_ci.py', 'tests/governance/test_media_remote_native_profile.py', 'tests/governance/test_recovery_oauth_code_media_binding.py', '.github/workflows/oauth-code-producer-focused.yml', 'tests/governance/test_private_calendar_failure_diagnostic.py', 'tests/governance/test_recovery_assessment_credential_sql.py', 'tests/governance/test_recovery_oauth_code_coordination.py', 'tests/governance/test_native_organization.py', 'tests/governance/test_directory_source_inventory_pin.py', 'tests/governance/test_recovery_oauth_access_ci.py', 'tests/governance/test_recovery_file_parent_gate.py', 'support/governance/project-description-ci/ci.py', '.github/workflows/assessment-credential-focused.yml', 'support/governance/media-session-ci/test_ci.py', 'tests/governance/test_recovery_file_parent_policy.py', 'tests/governance/test_recovery_oauth_client_lifecycle_ci.py', 'tests/governance/test_n05_legacy_audit_test_view.py', 'tests/governance/test_recovery_partial_wiring.py', '.github/workflows/oauth-client-lifecycle-focused.yml', 'tests/governance/test_recovery_oauth_client_lifecycle_coordination.py', 'tests/governance/test_recovery_partial_schema_coordination.py'})
  need(set(entries)==(set(self.parent_pins)-WIKI_RETIRED_PATHS)|set(TASK_NEW_PINS)|{BASELINE,INVERSE,'scripts/governance/task_source_operation_context.py'}|V2_PATHS|CI_V2_NEW_PATHS|set(DIRECTORY_PRODUCT_NEW_PINS)|set(PHP86_COMPATIBILITY_NEW_PINS))
  for rel,pin in PHP86_COMPATIBILITY_NEW_PINS.items():
   need(entries[rel]['kind']=='file'and entries[rel]['mode']==pin['mode']and len(self.current_raw[rel])==pin['bytes']and sha(self.current_raw[rel])==pin['sha256'])
  php86_predecessor=php86_compatibility_cohort({name:self.current_raw[name]for name in PHP86_COMPATIBILITY_PATHS},self.current_raw[PHP86_COMPATIBILITY_HISTORY])
  for rel,pin in DIRECTORY_PRODUCT_NEW_PINS.items():
   need(entries[rel]['kind']=='file'and entries[rel]['mode']==pin['mode']and len(php86_predecessor.get(rel,self.current_raw[rel]))==pin['bytes']and sha(php86_predecessor.get(rel,self.current_raw[rel]))==pin['sha256'])
  directory_predecessor=directory_product_cohort({name:php86_predecessor.get(name,self.current_raw[name])for name in DIRECTORY_PRODUCT_PATHS},self.current_raw[DIRECTORY_PRODUCT_HISTORY])
  for rel,pin in TASK_NEW_PINS.items():need(len(self.current_raw[rel])==pin['bytes']and sha(self.current_raw[rel])==pin['sha256'])
  # Compile CURRENT Task inverse, captured from this real source; never Git old code.
  path='scripts/governance/task_content_grant_source_delta.py';namespace={'__file__':str(self.root/path),'__name__':'TaskOperation_current_inverse'};exec(compile(self.current_raw[path],str(self.root/path),'exec'),namespace);self.reduce=namespace['reduce_selected']
  for rel,pin in entries.items():
   if pin['kind']!='file':continue
   raw=directory_predecessor.get(rel,php86_predecessor.get(rel,self.current_raw[rel]))
   if rel in self.ci_inverse:
    inverse=self.ci_inverse[rel];decoder=zlib.decompressobj();raw=decoder.decompress(base64.b64decode(inverse['zlib_base64'],validate=True),inverse['bytes']+1);need(decoder.eof and not decoder.unused_data and not decoder.unconsumed_tail and len(raw)==inverse['bytes']and sha(raw)==inverse['sha256'])
   elif rel in self.api_inverse:
    inverse=self.api_inverse[rel];decoder=zlib.decompressobj();raw=decoder.decompress(base64.b64decode(inverse['zlib_base64'],validate=True),inverse['bytes']+1);need(decoder.eof and not decoder.unused_data and not decoder.unconsumed_tail and len(raw)==inverse['bytes']and sha(raw)==inverse['sha256'])
   elif rel in self.parent_pins:
    if rel==HEADER_EMISSION_SECURITY_PATH:raw=header_predecessor
    if rel in PROXY_IDENTITY_SECURITY_PATHS:raw=proxy_predecessor[rel]
    if rel in RELEASE_HISTORY_PATHS:raw=release_history_inverse(rel,raw,release_history)
    if rel==TASK_PREUPGRADE_FIXTURE:
     raw=task_preupgrade_fixture_inverse(rel,raw,self.current_raw[TASK_PREUPGRADE_RECIPE])
    raw=self.reduce(rel,raw)
   else:raw=None
   if rel in self.parent_pins:
    old=self.parent_pins[rel];need(old['kind']=='file'and raw is not None and len(raw)==old['bytes']and sha(raw)==old['sha256']);self.reduced[rel]=raw
   else:need(raw is None)
  need((set(self.parent_pins)-WIKI_RETIRED_PATHS).issubset(entries))
  for rel,pin in self.parent_pins.items():
   if pin['kind']=='symlink':need(entries[rel]==pin)
 def directory_stamp(self,path,info=None):
  # os.lstat is the same non-following stat, without Path.lstat/stat wrappers.
  value=stamp(os.lstat(path)if info is None else info)
  # Cache pure lexical scope only. Every call still obtains/checks actual stat.
  scopes=getattr(self,'_directory_scopes',None)
  if scopes is not None and (self.root is self._directory_scopes_root or self.root==self._directory_scopes_root):
   try:inside=scopes[path]
   except KeyError:inside=path.is_relative_to(self.root)
  else:inside=path.is_relative_to(self.root)
  return value if inside else value[:5]
 def require(self):
  self.operation._require_task_context(self);need(not self.closed)
 def current(self,relative):
  self.require();need(relative in self.current_raw)
  cached_root=getattr(self,'_path_cache_root',None)
  bound=cached_root is self.root or cached_root==self.root
  paths=getattr(self,'_captured_paths',None);parents=getattr(self,'_captured_file_parents',None)
  path=paths[relative]if bound and paths is not None and relative in paths else self.root/relative
  chain=parents[relative]if bound and parents is not None and relative in parents else path.parents
  need(stamp(path.lstat())==self.files[relative])
  for p in chain:need(self.directory_stamp(p)==self.directories[p])
  return self.current_raw[relative]
 def parent(self,relative):
  self.current(relative);return self.reduced.get(relative)
 def exit(self):
  self.require()
  try:
   for p,identity in self.directories.items():need(self.directory_stamp(p)==identity)
   for rel,pin in self.entries.items():
    paths=getattr(self,'_captured_paths',None)
    cached_root=getattr(self,'_path_cache_root',None)
    path=paths[rel]if (cached_root is self.root or cached_root==self.root)and paths is not None and rel in paths else self.root/rel
    need(stamp(path.lstat())==self.files[rel])
    if pin['kind']=='file':raw,_=read(path);need(raw==self.current_raw[rel])
    else:need(os.readlink(path)==pin['target'])
  finally:self.closed=True

def capture_current(operation,roots):
 code=roots[0];revision,entries,raws=git_tree(code,operation._task_expected_checkpoint)
 # The known-root signer authenticates exact CURRENT code and data. Producer
 # copies are checked against the identical real tree; prepared files are
 # still solely accepted by the original strict PreparedLedger policy.
 result={root:TaskContext(operation,root,entries,raws)for root in dict.fromkeys(roots)}
 return result

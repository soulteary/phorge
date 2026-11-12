"""Pin native-directory static source closure; never certify authorization."""
import argparse
import hashlib
import json
from pathlib import Path
import re

EXPECTED_SOURCES = {'NATIVE.PhorgeGovernanceMediaTarget': ('src/infrastructure/governance/media/PhorgeGovernanceMediaTarget.php', 'PhorgeGovernanceMediaTarget', 'immutable-prepared-media-target'),
 'NATIVE.PhorgeGovernanceMediaIssuer': ('src/infrastructure/governance/media/PhorgeGovernanceMediaIssuer.php',
 'PhorgeGovernanceMediaIssuer','media-only-external-issuer'),
 'ORG.ROUTES': ('src/applications/people/application/PhabricatorPeopleApplication.php',
                'PhabricatorPeopleApplication',
                'route-registration'),
 'AUTH.CONDUIT.CONTROLLER': ('src/applications/conduit/controller/PhabricatorConduitAPIController.php',
                             'PhabricatorConduitAPIController',
                             'authentication-boundary'),
 'AUTH.CONDUIT.REQUEST': ('src/applications/conduit/protocol/ConduitAPIRequest.php',
                          'ConduitAPIRequest',
                          'sealed-context-transport'),
 'AUTH.LEGACY.ROLE': ('src/applications/people/editor/PhabricatorUserEditor.php',
                      'PhabricatorUserEditor',
                      'native-role-generation-writer'),
 'AUTH.USER.DAO': ('src/applications/people/storage/PhabricatorUser.php',
                   'PhabricatorUser',
                   'native-account-source'),
 'AUTH.SESSION.DAO': ('src/applications/auth/storage/PhabricatorAuthSession.php',
                      'PhabricatorAuthSession',
                      'native-session-source'),
 'AUTH.TOKEN.DAO': ('src/applications/conduit/storage/PhabricatorConduitToken.php',
                    'PhabricatorConduitToken',
                    'native-token-source'),
 'NATIVE.PhorgeGovernanceNativeApprovalAdapter': ('src/applications/people/governance/approval/PhorgeGovernanceNativeApprovalAdapter.php',
                                                  'PhorgeGovernanceNativeApprovalAdapter',
                                                  'candidate-runtime-source'),
 'NATIVE.PhorgeGovernanceIdentityEpoch': ('src/applications/people/governance/auth/PhorgeGovernanceIdentityEpoch.php',
                                          'PhorgeGovernanceIdentityEpoch',
                                          'candidate-runtime-source'),
 'NATIVE.PhorgeGovernanceNativeActorContext': ('src/applications/people/governance/auth/PhorgeGovernanceNativeActorContext.php',
                                               'PhorgeGovernanceNativeActorContext',
                                               'candidate-runtime-source'),
 'NATIVE.PhorgeGovernanceNativeIdentitySnapshot': ('src/applications/people/governance/auth/PhorgeGovernanceNativeIdentitySnapshot.php',
                                                   'PhorgeGovernanceNativeIdentitySnapshot',
                                                   'candidate-runtime-source'),
 'NATIVE.PhorgeGovernanceNativeIdentitySource': ('src/applications/people/governance/auth/PhorgeGovernanceNativeIdentitySource.php',
                                                 'PhorgeGovernanceNativeIdentitySource',
                                                 'candidate-runtime-source'),
 'NATIVE.PeopleOrganizationEditConduitAPIMethod': ('src/applications/people/governance/conduit/PeopleOrganizationEditConduitAPIMethod.php',
                                                   'PeopleOrganizationEditConduitAPIMethod',
                                                   'candidate-runtime-source'),
 'NATIVE.PeopleOrganizationQueryConduitAPIMethod': ('src/applications/people/governance/conduit/PeopleOrganizationQueryConduitAPIMethod.php',
                                                    'PeopleOrganizationQueryConduitAPIMethod',
                                                    'candidate-runtime-source'),
 'NATIVE.PhorgeOrganizationController': ('src/applications/people/governance/controller/PhorgeOrganizationController.php',
                                         'PhorgeOrganizationController',
                                         'candidate-runtime-source'),
 'NATIVE.PhorgeOrganizationQuery': ('src/applications/people/governance/query/PhorgeOrganizationQuery.php',
                                    'PhorgeOrganizationQuery',
                                    'candidate-runtime-source'),
 'NATIVE.PhorgeOrganizationConfigOptions': ('src/applications/people/governance/service/PhorgeOrganizationConfigOptions.php',
                                            'PhorgeOrganizationConfigOptions',
                                            'candidate-runtime-source'),
 'NATIVE.PhorgeOrganizationEndpoint': ('src/applications/people/governance/service/PhorgeOrganizationEndpoint.php',
                                       'PhorgeOrganizationEndpoint',
                                       'candidate-runtime-source'),
 'NATIVE.PhorgeOrganizationRequest': ('src/applications/people/governance/service/PhorgeOrganizationRequest.php',
                                      'PhorgeOrganizationRequest',
                                      'candidate-runtime-source'),
 'NATIVE.PhorgeOrganizationService': ('src/applications/people/governance/service/PhorgeOrganizationService.php',
                                      'PhorgeOrganizationService',
                                      'candidate-runtime-source'),
 'NATIVE.PhorgeOrganizationAppointment': ('src/applications/people/governance/storage/PhorgeOrganizationAppointment.php',
                                          'PhorgeOrganizationAppointment',
                                          'candidate-runtime-source'),
 'NATIVE.PhorgeOrganizationMember': ('src/applications/people/governance/storage/PhorgeOrganizationMember.php',
                                     'PhorgeOrganizationMember',
                                     'candidate-runtime-source'),
 'NATIVE.PhorgeOrganizationPrimaryDepartment': ('src/applications/people/governance/storage/PhorgeOrganizationPrimaryDepartment.php',
                                                'PhorgeOrganizationPrimaryDepartment',
                                                'candidate-runtime-source'),
 'NATIVE.PhorgeOrganizationSchemaSpec': ('src/applications/people/governance/storage/PhorgeOrganizationSchemaSpec.php',
                                         'PhorgeOrganizationSchemaSpec',
                                         'candidate-runtime-source'),
 'NATIVE.PhorgeOrganizationUnit': ('src/applications/people/governance/storage/PhorgeOrganizationUnit.php',
                                   'PhorgeOrganizationUnit',
                                   'candidate-runtime-source'),
 'NATIVE.PhorgeGovernanceMediaExchangeService': ('src/infrastructure/governance/media/PhorgeGovernanceMediaExchangeService.php',
                                                 'PhorgeGovernanceMediaExchangeService',
                                                 'candidate-runtime-source'),
 'NATIVE.PhorgeGovernanceMediaSchemaSpec': ('src/infrastructure/governance/media/PhorgeGovernanceMediaSchemaSpec.php',
                                            'PhorgeGovernanceMediaSchemaSpec',
                                            'candidate-runtime-source'),
 'NATIVE.PhorgeGovernanceMediaConfigOptions': ('src/infrastructure/governance/media/PhorgeGovernanceMediaConfigOptions.php',
                                               'PhorgeGovernanceMediaConfigOptions',
                                               'default-off-configuration'),
 'NATIVE.PhorgeOrganizationDatasource': ('src/applications/people/governance/query/PhorgeOrganizationDatasource.php',
                                         'PhorgeOrganizationDatasource',
                                         'sealed-context-native-datasource'),
 'NATIVE.PhorgeOrganizationDatasourceController': ('src/applications/people/governance/controller/PhorgeOrganizationDatasourceController.php',
                                                   'PhorgeOrganizationDatasourceController',
                                                   'authenticated-datasource-controller'),
 'NATIVE.PhorgeGovernanceMediaIssuer': ('src/infrastructure/governance/media/PhorgeGovernanceMediaIssuer.php',
                                        'PhorgeGovernanceMediaIssuer',
                                        'media-only-external-issuer'),
 'NATIVE.PhorgeGovernanceMediaTarget': ('src/infrastructure/governance/media/PhorgeGovernanceMediaTarget.php',
                                        'PhorgeGovernanceMediaTarget',
                                        'immutable-prepared-media-target')}

EXPECTED_SOURCES.update({
 'TYPEAHEAD.BASE': ('src/applications/typeahead/datasource/PhabricatorTypeaheadDatasource.php','PhabricatorTypeaheadDatasource','fixed-normalizer-default'),
 'TOKENIZER.CONTROL': ('src/view/form/control/AphrontFormTokenizerControl.php','AphrontFormTokenizerControl','fixed-normalizer-metadata'),
 'CLIENT.TOKENIZER.BEHAVIOR': ('webroot/rsrc/js/core/behavior-tokenizer.js','aphront-basic-tokenizer','fixed-client-metadata-forwarder'),
 'CLIENT.PREFAB': ('webroot/rsrc/js/core/Prefab.js','Prefab','fixed-client-normalizer')})

EXPECTED_SOURCES['NATIVE.PhorgeOrganizationHistoryController']=('src/applications/people/governance/controller/PhorgeOrganizationHistoryController.php','PhorgeOrganizationHistoryController','current-system-administrator-history-controller')

EXPECTED_RUNTIME_FILES = ['src/applications/people/governance/approval/PhorgeGovernanceNativeApprovalAdapter.php',
 'src/applications/people/governance/auth/PhorgeGovernanceIdentityEpoch.php',
 'src/applications/people/governance/auth/PhorgeGovernanceNativeActorContext.php',
 'src/applications/people/governance/auth/PhorgeGovernanceNativeIdentitySnapshot.php',
 'src/applications/people/governance/auth/PhorgeGovernanceNativeIdentitySource.php',
 'src/applications/people/governance/conduit/PeopleOrganizationEditConduitAPIMethod.php',
 'src/applications/people/governance/conduit/PeopleOrganizationQueryConduitAPIMethod.php',
 'src/applications/people/governance/controller/PhorgeOrganizationController.php',
 'src/applications/people/governance/controller/PhorgeOrganizationDatasourceController.php',
 'src/applications/people/governance/query/PhorgeOrganizationDatasource.php',
 'src/applications/people/governance/query/PhorgeOrganizationQuery.php',
 'src/applications/people/governance/service/PhorgeOrganizationConfigOptions.php',
 'src/applications/people/governance/service/PhorgeOrganizationEndpoint.php',
 'src/applications/people/governance/service/PhorgeOrganizationRequest.php',
 'src/applications/people/governance/service/PhorgeOrganizationService.php',
 'src/applications/people/governance/storage/PhorgeOrganizationAppointment.php',
 'src/applications/people/governance/storage/PhorgeOrganizationMember.php',
 'src/applications/people/governance/storage/PhorgeOrganizationPrimaryDepartment.php',
 'src/applications/people/governance/storage/PhorgeOrganizationSchemaSpec.php',
 'src/applications/people/governance/storage/PhorgeOrganizationUnit.php',
 'src/infrastructure/governance/consistency/PhorgeGovernanceNativeReadView.php',
 'src/infrastructure/governance/consistency/PhorgeGovernanceNativeRuntimeProfile.php',
 'src/infrastructure/governance/consistency/PhorgeGovernanceSnapshotConnection.php',
 'src/infrastructure/governance/content/PhorgeProjectDescriptionExecutor.php',
 'src/infrastructure/governance/content/PhorgeProjectDescriptionRequest.php',
 'src/infrastructure/governance/contracts/PhorgeGovernanceApprovalRequest.php',
 'src/infrastructure/governance/contracts/PhorgeGovernanceApprovalState.php',
 'src/infrastructure/governance/contracts/PhorgeGovernanceContentDecision.php',
 'src/infrastructure/governance/directory/PhorgeGovernanceDirectoryReader.php',
 'src/infrastructure/governance/directory/PhorgeGovernanceIsolatedDirectoryStore.php',
 'src/infrastructure/governance/execution/PhorgeProjectConfigEpoch.php',
 'src/infrastructure/governance/execution/PhorgeProjectConfigEpochScope.php',
 'src/infrastructure/governance/execution/PhorgeProjectExecutionCatalog.php',
 'src/infrastructure/governance/execution/PhorgeProjectExecutionConfigOptions.php',
 'src/infrastructure/governance/execution/PhorgeProjectExecutionConfigSource.php',
 'src/infrastructure/governance/execution/PhorgeProjectExecutionProducerFence.php',
 'src/infrastructure/governance/execution/PhorgeProjectExecutionValue.php',
 'src/infrastructure/governance/execution/PhorgeProjectExecutionSchemaSpec.php',
 'src/infrastructure/governance/execution/PhorgeProjectNativeExecutionScope.php',
 'src/infrastructure/governance/execution/PhorgeProjectPolicyApprovalController.php',
 'src/infrastructure/governance/execution/PhorgeProjectPolicyApprovalService.php',
 'src/infrastructure/governance/execution/PhorgeProjectPolicyApprovalStore.php',
 'src/infrastructure/governance/execution/ProjectPolicyApprovalConduitAPIMethod.php',
 'src/infrastructure/governance/mapping/PhorgeGovernanceCapabilityMap.php',
 'src/infrastructure/governance/media/PhorgeGovernanceMediaConfigOptions.php',
 'src/infrastructure/governance/media/PhorgeGovernanceMediaExchangeService.php',
 'src/infrastructure/governance/media/PhorgeGovernanceMediaIssuer.php',
 'src/infrastructure/governance/media/PhorgeGovernanceMediaSchemaSpec.php',
 'src/infrastructure/governance/media/PhorgeGovernanceMediaTarget.php',
 'src/infrastructure/governance/native/PhorgeGovernanceNativePolicyCompositionVerifier.php',
 'src/infrastructure/governance/native/PhorgeGovernanceNativeRuleHierarchyVerifier.php',
 'src/infrastructure/governance/native/PhorgeGovernanceNativeSourceCapture.php',
 'src/infrastructure/governance/native/PhorgeGovernanceNativeSourceReader.php',
 'src/infrastructure/governance/observation/PhorgeGovernanceNativeCapabilityContext.php',
 'src/infrastructure/governance/observation/PhorgeGovernanceNativeObservationVerifier.php',
 'src/infrastructure/governance/organization/PhorgeGovernanceOrganizationApprovalAdapter.php',
 'src/infrastructure/governance/organization/PhorgeGovernanceOrganizationReader.php',
 'src/infrastructure/governance/organization/PhorgeGovernanceOrganizationRequest.php',
 'src/infrastructure/governance/organization/PhorgeGovernanceOrganizationStore.php',
 'src/infrastructure/governance/readset/PhorgeGovernanceIsolatedReadSetStore.php',
 'src/infrastructure/governance/readset/PhorgeGovernanceReadSetSnapshot.php',
 'src/infrastructure/governance/readset/PhorgeGovernanceReadSetValidator.php',
 'src/infrastructure/governance/readset/PhorgeGovernanceSourceSnapshot.php']

EXPECTED_RUNTIME_FILES=sorted(EXPECTED_RUNTIME_FILES+['src/applications/people/governance/controller/PhorgeOrganizationHistoryController.php'])

EXPECTED_RUNTIME_FILES=sorted(EXPECTED_RUNTIME_FILES+['src/infrastructure/governance/execution/PhorgeProjectExecutionIntent.php'])

EXPECTED_LINKS = {'NATIVE-LINK-01': ('ORG.ROUTES', 'NATIVE.PhorgeOrganizationController'),
 'NATIVE-LINK-02': ('NATIVE.PhorgeOrganizationController', 'NATIVE.PhorgeOrganizationEndpoint'),
 'NATIVE-LINK-03': ('NATIVE.PeopleOrganizationQueryConduitAPIMethod',
                    'NATIVE.PhorgeOrganizationEndpoint'),
 'NATIVE-LINK-04': ('NATIVE.PeopleOrganizationEditConduitAPIMethod',
                    'NATIVE.PhorgeOrganizationEndpoint'),
 'NATIVE-LINK-05': ('NATIVE.PhorgeOrganizationEndpoint', 'NATIVE.PhorgeOrganizationService'),
 'NATIVE-LINK-06': ('NATIVE.PhorgeOrganizationQuery', 'NATIVE.PhorgeOrganizationService'),
 'NATIVE-LINK-07': ('NATIVE.PhorgeOrganizationService',
                    'NATIVE.PhorgeGovernanceNativeIdentitySource'),
 'NATIVE-LINK-08': ('NATIVE.PhorgeOrganizationService',
                    'NATIVE.PhorgeGovernanceNativeApprovalAdapter'),
 'NATIVE-LINK-09': ('AUTH.CONDUIT.CONTROLLER', 'AUTH.CONDUIT.REQUEST'),
 'NATIVE-LINK-10': ('AUTH.CONDUIT.REQUEST', 'NATIVE.PhorgeGovernanceNativeActorContext'),
 'NATIVE-LINK-11': ('AUTH.LEGACY.ROLE', 'NATIVE.PhorgeGovernanceIdentityEpoch'),
 'NATIVE-LINK-12': ('NATIVE.PhorgeGovernanceNativeIdentitySource', 'AUTH.USER.DAO'),
 'NATIVE-LINK-13': ('NATIVE.PhorgeGovernanceNativeIdentitySource', 'AUTH.SESSION.DAO'),
 'NATIVE-LINK-14': ('NATIVE.PhorgeGovernanceNativeIdentitySource', 'AUTH.TOKEN.DAO'),
 'NATIVE-LINK-15': ('NATIVE.PhorgeGovernanceMediaExchangeService',
                    'NATIVE.PhorgeGovernanceNativeIdentitySource'),
 'NATIVE-LINK-16': ('NATIVE.PhorgeGovernanceMediaConfigOptions',
                    'NATIVE.PhorgeGovernanceMediaExchangeService'),
 'NATIVE-LINK-17': ('ORG.ROUTES', 'NATIVE.PhorgeOrganizationDatasourceController'),
 'NATIVE-LINK-18': ('NATIVE.PhorgeOrganizationDatasourceController',
                    'NATIVE.PhorgeOrganizationDatasource'),
 'NATIVE-LINK-19': ('NATIVE.PhorgeOrganizationDatasource', 'NATIVE.PhorgeOrganizationQuery'),
 'NATIVE-LINK-20': ('NATIVE.PhorgeGovernanceMediaExchangeService',
                    'NATIVE.PhorgeGovernanceMediaIssuer'),
 'NATIVE-LINK-21': ('NATIVE.PhorgeGovernanceMediaConfigOptions',
                    'NATIVE.PhorgeGovernanceMediaIssuer'),
 'NATIVE-LINK-26': ('NATIVE.PhorgeGovernanceMediaExchangeService',
                    'NATIVE.PhorgeGovernanceMediaTarget')}

EXPECTED_LINKS.update({
 'NATIVE-LINK-22': ('NATIVE.PhorgeOrganizationDatasource','TYPEAHEAD.BASE'),
 'NATIVE-LINK-23': ('TOKENIZER.CONTROL','TYPEAHEAD.BASE'),
 'NATIVE-LINK-24': ('TOKENIZER.CONTROL','CLIENT.TOKENIZER.BEHAVIOR'),
 'NATIVE-LINK-25': ('CLIENT.TOKENIZER.BEHAVIOR','CLIENT.PREFAB')})

EXPECTED_LINKS.update({'NATIVE-LINK-27':('ORG.ROUTES','NATIVE.PhorgeOrganizationHistoryController'),'NATIVE-LINK-28':('NATIVE.PhorgeOrganizationHistoryController','NATIVE.PhorgeOrganizationQuery')})


EXPECTED_SOURCES.update({'NATIVE.PhorgeOrganizationCalendar': ('src/applications/people/governance/service/PhorgeOrganizationCalendar.php', 'PhorgeOrganizationCalendar', 'calendar-intent-preview'), 'NATIVE.PhorgeOrganizationTransferRequest': ('src/applications/people/governance/service/PhorgeOrganizationTransferRequest.php', 'PhorgeOrganizationTransferRequest', 'typed-transfer-intent-only'), 'NATIVE.PhorgeOrganizationTransfer': ('src/applications/people/governance/storage/PhorgeOrganizationTransfer.php', 'PhorgeOrganizationTransfer', 'protected-transfer-storage'), 'NATIVE.PhorgeOrganizationHandover': ('src/applications/people/governance/storage/PhorgeOrganizationHandover.php', 'PhorgeOrganizationHandover', 'protected-transfer-storage'), 'NATIVE.PhorgeOrganizationProjectDisposition': ('src/applications/people/governance/storage/PhorgeOrganizationProjectDisposition.php', 'PhorgeOrganizationProjectDisposition', 'protected-transfer-storage'), 'NATIVE.PhorgeOrganizationOutbox': ('src/applications/people/governance/storage/PhorgeOrganizationOutbox.php', 'PhorgeOrganizationOutbox', 'undispatched-reference-storage')})
EXPECTED_RUNTIME_FILES=sorted(EXPECTED_RUNTIME_FILES+['src/applications/people/governance/service/PhorgeOrganizationCalendar.php', 'src/applications/people/governance/service/PhorgeOrganizationTransferRequest.php', 'src/applications/people/governance/storage/PhorgeOrganizationTransfer.php', 'src/applications/people/governance/storage/PhorgeOrganizationHandover.php', 'src/applications/people/governance/storage/PhorgeOrganizationProjectDisposition.php', 'src/applications/people/governance/storage/PhorgeOrganizationOutbox.php'])
EXPECTED_LINKS.update({'NATIVE-LINK-41':('NATIVE.PhorgeOrganizationTransferRequest','NATIVE.PhorgeOrganizationService')})

# Explicit organization successor identities; all remain partial and non-authoritative.
EXPECTED_SOURCES.update({'NATIVE.PhorgeOrganizationTransferAdapter': ('src/applications/people/governance/service/PhorgeOrganizationTransferAdapter.php',
                                              'PhorgeOrganizationTransferAdapter',
                                              'sealed-native-approved-transfer-writer'),
 'NATIVE.PhorgeOrganizationHandoverAdapter': ('src/applications/people/governance/service/PhorgeOrganizationHandoverAdapter.php',
                                              'PhorgeOrganizationHandoverAdapter',
                                              'sealed-native-approved-handover-writer'),
 'NATIVE.PhorgeOrganizationTransferController': ('src/applications/people/governance/controller/PhorgeOrganizationTransferController.php',
                                                 'PhorgeOrganizationTransferController',
                                                 'current-admin-csrf-native-management-controller'),
 'NATIVE.PhorgeGovernanceNativeNoncredentialIdentitySource': ('src/applications/people/governance/auth/PhorgeGovernanceNativeNoncredentialIdentitySource.php',
                                                              'PhorgeGovernanceNativeNoncredentialIdentitySource',
                                                              'locked-versioned-noncredential-generation-facts'),
 'PROJECT.NATIVE.VIEWER.QUERY': ('src/applications/project/query/PhabricatorProjectQuery.php',
                                 'PhabricatorProjectQuery',
                                 'manual-receipt-current-viewer-filter-sampled-not-atomic')})
EXPECTED_RUNTIME_FILES=sorted(EXPECTED_RUNTIME_FILES+['src/applications/people/governance/service/PhorgeOrganizationTransferAdapter.php', 'src/applications/people/governance/service/PhorgeOrganizationHandoverAdapter.php', 'src/applications/people/governance/controller/PhorgeOrganizationTransferController.php', 'src/applications/people/governance/auth/PhorgeGovernanceNativeNoncredentialIdentitySource.php'])
EXPECTED_LINKS.update({'NATIVE-LINK-42': ('ORG.ROUTES',
                    'NATIVE.PhorgeOrganizationTransferController'),
 'NATIVE-LINK-43': ('NATIVE.PhorgeOrganizationTransferController',
                    'NATIVE.PhorgeOrganizationService'),
 'NATIVE-LINK-44': ('NATIVE.PhorgeOrganizationService',
                    'NATIVE.PhorgeOrganizationTransferAdapter'),
 'NATIVE-LINK-45': ('NATIVE.PhorgeOrganizationService',
                    'NATIVE.PhorgeOrganizationHandoverAdapter'),
 'NATIVE-LINK-46': ('NATIVE.PhorgeOrganizationTransferAdapter',
                    'NATIVE.PhorgeGovernanceNativeNoncredentialIdentitySource'),
 'NATIVE-LINK-47': ('NATIVE.PhorgeOrganizationHandoverAdapter',
                    'NATIVE.PhorgeGovernanceNativeNoncredentialIdentitySource'),
 'NATIVE-LINK-48': ('NATIVE.PhorgeGovernanceNativeNoncredentialIdentitySource',
                    'NATIVE.PhorgeGovernanceNativeIdentitySource'),
 'NATIVE-LINK-49': ('NATIVE.PhorgeOrganizationHandoverAdapter',
                    'PROJECT.NATIVE.VIEWER.QUERY')})
# Explicit reviewed HTTP candidate identities, not an open-ended source union.
EXPECTED_SOURCES.update({'NATIVE.PhorgeGovernanceMediaAuthorizeController': ('src/infrastructure/governance/media/http/PhorgeGovernanceMediaAuthorizeController.php',
                                                     'PhorgeGovernanceMediaAuthorizeController',
                                                     'native-app-origin-authorization-controller'),
 'NATIVE.PhorgeGovernanceMediaResourceController': ('src/infrastructure/governance/media/http/PhorgeGovernanceMediaResourceController.php',
                                                    'PhorgeGovernanceMediaResourceController',
                                                    'native-file-origin-exchange-controller'),
 'NATIVE.PhorgeGovernanceMediaStaticController': ('src/infrastructure/governance/media/http/PhorgeGovernanceMediaStaticController.php',
                                                  'PhorgeGovernanceMediaStaticController',
                                                  'fixed-source-static-only-controller'),
 'NATIVE.PhorgeGovernanceMediaHTTPProtocol': ('src/infrastructure/governance/media/http/PhorgeGovernanceMediaHTTPProtocol.php',
                                              'PhorgeGovernanceMediaHTTPProtocol',
                                              'fixed-origin-wire-cookie-protocol'),
 'NATIVE.PhorgeGovernanceMediaHTTPResponse': ('src/infrastructure/governance/media/http/PhorgeGovernanceMediaHTTPResponse.php',
                                              'PhorgeGovernanceMediaHTTPResponse',
                                              'native-header-preserving-response'),
 'NATIVE.PhorgeGovernanceMediaFileGuard': ('src/infrastructure/governance/media/http/PhorgeGovernanceMediaFileGuard.php',
                                           'PhorgeGovernanceMediaFileGuard',
                                           'current-native-viewer-file-guard'),
 'FILES.ROUTES': ('src/applications/files/application/PhabricatorFilesApplication.php',
                  'PhabricatorFilesApplication',
                  'feature-off-media-route-registration'),
 'FILES.DATA': ('src/applications/files/controller/PhabricatorFileDataController.php',
                'PhabricatorFileDataController',
                'pre-metadata-native-file-authorization-consumer'),
 'FILES.TRANSFORM': ('src/applications/files/controller/PhabricatorFileTransformController.php',
                     'PhabricatorFileTransformController',
                     'original-before-derived-file-authorization-consumer'),
 'FILES.FAVICON': ('src/applications/files/favicon/PhabricatorFaviconRefQuery.php',
                   'PhabricatorFaviconRefQuery',
                   'fixed-static-default-favicon-producer'),
 'AUTH.MEDIA.FIREWALL': ('src/aphront/configuration/AphrontApplicationConfiguration.php',
                         'AphrontApplicationConfiguration',
                         'pre-native-session-media-cookie-firewall'),
 'PAGE.MEDIA.BOOTSTRAP': ('src/view/page/PhabricatorStandardPageView.php',
                          'PhabricatorStandardPageView',
                          'native-session-media-bootstrap-config-consumer'),
 'CLIENT.MEDIA.HANDOFF': ('webroot/rsrc/js/core/behavior-private-media-session.js',
                          'private-media-session',
                          'fixed-origin-client-handoff')})
EXPECTED_RUNTIME_FILES=sorted(EXPECTED_RUNTIME_FILES+['src/infrastructure/governance/media/http/PhorgeGovernanceMediaAuthorizeController.php', 'src/infrastructure/governance/media/http/PhorgeGovernanceMediaResourceController.php', 'src/infrastructure/governance/media/http/PhorgeGovernanceMediaStaticController.php', 'src/infrastructure/governance/media/http/PhorgeGovernanceMediaHTTPProtocol.php', 'src/infrastructure/governance/media/http/PhorgeGovernanceMediaHTTPResponse.php', 'src/infrastructure/governance/media/http/PhorgeGovernanceMediaFileGuard.php'])
EXPECTED_LINKS.update({'NATIVE-LINK-29': ('FILES.ROUTES',
                    'NATIVE.PhorgeGovernanceMediaResourceController'),
 'NATIVE-LINK-30': ('FILES.ROUTES',
                    'NATIVE.PhorgeGovernanceMediaStaticController'),
 'NATIVE-LINK-31': ('FILES.DATA', 'NATIVE.PhorgeGovernanceMediaFileGuard'),
 'NATIVE-LINK-32': ('FILES.TRANSFORM', 'NATIVE.PhorgeGovernanceMediaFileGuard'),
 'NATIVE-LINK-33': ('NATIVE.PhorgeGovernanceMediaFileGuard',
                    'NATIVE.PhorgeGovernanceMediaExchangeService'),
 'NATIVE-LINK-34': ('NATIVE.PhorgeGovernanceMediaAuthorizeController',
                    'NATIVE.PhorgeGovernanceMediaExchangeService'),
 'NATIVE-LINK-35': ('NATIVE.PhorgeGovernanceMediaResourceController',
                    'NATIVE.PhorgeGovernanceMediaHTTPProtocol'),
 'NATIVE-LINK-36': ('PAGE.MEDIA.BOOTSTRAP',
                    'NATIVE.PhorgeGovernanceMediaHTTPProtocol'),
 'NATIVE-LINK-37': ('PAGE.MEDIA.BOOTSTRAP', 'CLIENT.MEDIA.HANDOFF'),
 'NATIVE-LINK-38': ('AUTH.MEDIA.FIREWALL',
                    'NATIVE.PhorgeGovernanceMediaHTTPProtocol'),
 'NATIVE-LINK-39': ('FILES.FAVICON',
                    'NATIVE.PhorgeGovernanceMediaStaticController'),
 'NATIVE-LINK-40': ('NATIVE.PhorgeGovernanceMediaResourceController',
                    'NATIVE.PhorgeGovernanceMediaHTTPResponse')})

EXPECTED_SOURCES.update({'MAIL.ACTOR.QUERY': ('src/applications/metamta/query/PhabricatorMetaMTAActorQuery.php',
                      'PhabricatorMetaMTAActorQuery',
                      'native-recipient-deliverability'),
 'MAIL.DELIVERY.API': ('src/applications/metamta/conduit/PhabricatorMailDeliveryConduitAPIMethod.php',
                       'PhabricatorMailDeliveryConduitAPIMethod',
                       'service-authenticated-email-attempt'),
 'MAIL.MESSAGE.ENGINE': ('src/applications/metamta/engine/PhabricatorMailEmailEngine.php',
                         'PhabricatorMailEmailEngine',
                         'native-email-message-construction'),
 'MAIL.MODULAR.EVENT.SOURCE': ('src/applications/transactions/storage/PhabricatorModularTransactionType.php',
                               'PhabricatorModularTransactionType',
                               'read-only-transaction-source-identity'),
 'MAIL.OUTBOUND': ('src/applications/metamta/storage/PhabricatorMetaMTAMail.php',
                   'PhabricatorMetaMTAMail',
                   'immutable-native-mail-and-attempt-gate'),
 'MAIL.PEOPLE.FLOW.ENGINE': ('src/applications/people/mail/PhabricatorPeopleMailEngine.php',
                             'PhabricatorPeopleMailEngine',
                             'native-security-producer-flow-binding'),
 'MAIL.TRANSACTION.PRODUCER': ('src/applications/transactions/editor/PhabricatorApplicationTransactionEditor.php',
                               'PhabricatorApplicationTransactionEditor',
                               'native-business-mail-producer'),
 'MAIL.USER.RENAME.SOURCE': ('src/applications/people/xaction/PhabricatorUserUsernameTransaction.php',
                             'PhabricatorUserUsernameTransaction',
                             'native-security-transaction-source'),
 'NATIVE.PhorgePrivateMailConfigOptions': ('src/infrastructure/governance/notification/PhorgePrivateMailConfigOptions.php',
                                           'PhorgePrivateMailConfigOptions',
                                           'default-off-private-email-configuration'),
 'NATIVE.PhorgePrivateMailPolicy': ('src/infrastructure/governance/notification/PhorgePrivateMailPolicy.php',
                                    'PhorgePrivateMailPolicy',
                                    'current-recipient-private-email-policy')})
EXPECTED_LINKS.update({'NATIVE-LINK-50': ('MAIL.TRANSACTION.PRODUCER', 'MAIL.OUTBOUND'),
 'NATIVE-LINK-51': ('MAIL.OUTBOUND', 'NATIVE.PhorgePrivateMailPolicy'),
 'NATIVE-LINK-52': ('NATIVE.PhorgePrivateMailConfigOptions',
                    'NATIVE.PhorgePrivateMailPolicy'),
 'NATIVE-LINK-53': ('MAIL.DELIVERY.API', 'MAIL.OUTBOUND'),
 'NATIVE-LINK-54': ('MAIL.OUTBOUND', 'MAIL.ACTOR.QUERY'),
 'NATIVE-LINK-55': ('MAIL.OUTBOUND', 'MAIL.MESSAGE.ENGINE'),
 'NATIVE-LINK-56': ('NATIVE.PhorgePrivateMailPolicy', 'AUTH.USER.DAO'),
 'NATIVE-LINK-57': ('MAIL.USER.RENAME.SOURCE', 'MAIL.MODULAR.EVENT.SOURCE'),
 'NATIVE-LINK-58': ('MAIL.USER.RENAME.SOURCE', 'MAIL.PEOPLE.FLOW.ENGINE'),
 'NATIVE-LINK-59': ('MAIL.PEOPLE.FLOW.ENGINE',
                    'NATIVE.PhorgePrivateMailPolicy'),
 'NATIVE-LINK-60': ('NATIVE.PhorgePrivateMailPolicy', 'AUTH.USER.DAO')})
EXPECTED_RUNTIME_FILES=sorted(EXPECTED_RUNTIME_FILES+['src/infrastructure/governance/notification/PhorgePrivateMailConfigOptions.php', 'src/infrastructure/governance/notification/PhorgePrivateMailPolicy.php', 'src/infrastructure/governance/notification/PhorgePrivateMailSourcePendingException.php'])

EXPECTED_SOURCES.update({'LEGALPAD.SIGN.CONTROLLER': ('src/applications/legalpad/controller/LegalpadDocumentSignController.php',
                              'LegalpadDocumentSignController',
                              'native-signature-and-verification-mail-entry'),
 'LEGALPAD.VERIFY.CONTROLLER': ('src/applications/legalpad/controller/LegalpadDocumentSignatureVerificationController.php',
                                'LegalpadDocumentSignatureVerificationController',
                                'current-signer-verification-entry'),
 'NATIVE.PhorgeLegalpadVerification': ('src/applications/legalpad/governance/PhorgeLegalpadVerification.php',
                                       'PhorgeLegalpadVerification',
                                       'current-session-signer-and-document-policy-check')})
EXPECTED_LINKS.update({'NATIVE-LINK-61': ('LEGALPAD.VERIFY.CONTROLLER',
                    'NATIVE.PhorgeLegalpadVerification'),
 'NATIVE-LINK-62': ('NATIVE.PhorgeLegalpadVerification', 'AUTH.USER.DAO'),
 'NATIVE-LINK-63': ('NATIVE.PhorgeLegalpadVerification', 'AUTH.SESSION.DAO'),
 'NATIVE-LINK-64': ('LEGALPAD.SIGN.CONTROLLER',
                    'NATIVE.PhorgePrivateMailPolicy'),
 'NATIVE-LINK-65': ('NATIVE.PhorgePrivateMailPolicy',
                    'NATIVE.PhorgeLegalpadVerification')})
EXPECTED_RUNTIME_FILES=sorted(EXPECTED_RUNTIME_FILES+['src/applications/legalpad/governance/PhorgeLegalpadVerification.php', 'src/applications/legalpad/governance/PhorgeLegalpadVerificationException.php'])

ROOTS = ('src/applications/people/governance', 'src/infrastructure/governance', 'src/applications/legalpad/governance')

# Explicitly reviewed form provenance and final-effect references; never authority proof.
VIEW_FORM_SOURCES = {'VIEW.FORM.PROJECT.CONTROLLER': ('src/applications/project/controller/PhabricatorProjectEditController.php', 'PhabricatorProjectEditController', 'native-edit-request-controller'), 'VIEW.FORM.PROJECT.ENGINE': ('src/applications/project/engine/PhabricatorProjectEditEngine.php', 'PhabricatorProjectEditEngine', 'native-project-form-field-producer'), 'VIEW.FORM.ENGINE': ('src/applications/transactions/editengine/PhabricatorEditEngine.php', 'PhabricatorEditEngine', 'native-form-provenance-registrar'), 'VIEW.FORM.CATALOG': ('src/infrastructure/governance/execution/PhorgeProjectExecutionCatalog.php', 'PhorgeProjectExecutionCatalog', 'scoped-native-form-envelope-classifier'), 'VIEW.FORM.SCOPE': ('src/infrastructure/governance/execution/PhorgeProjectNativeExecutionScope.php', 'PhorgeProjectNativeExecutionScope', 'native-final-effect-and-current-source-fence'), 'VIEW.FORM.BASE.EDITOR': ('src/applications/transactions/editor/PhabricatorApplicationTransactionEditor.php', 'PhabricatorApplicationTransactionEditor', 'native-filter-before-effect-hook'), 'VIEW.FORM.PROPOSAL.CONTROLLER': ('src/infrastructure/governance/execution/PhorgeProjectPolicyApprovalController.php', 'PhorgeProjectPolicyApprovalController', 'current-policy-preview-controller'), 'VIEW.FORM.POLICY.CONTROL': ('src/view/form/control/AphrontFormPolicyControl.php', 'AphrontFormPolicyControl', 'native-policy-presentation-control')}
EXPECTED_SOURCES.update(VIEW_FORM_SOURCES)
VIEW_FORM_LINKS = {'VIEW-FORM-LINK-01': ('VIEW.FORM.PROJECT.CONTROLLER', 'VIEW.FORM.PROJECT.ENGINE'), 'VIEW-FORM-LINK-02': ('VIEW.FORM.PROJECT.ENGINE', 'VIEW.FORM.ENGINE'), 'VIEW-FORM-LINK-03': ('VIEW.FORM.ENGINE', 'VIEW.FORM.CATALOG'), 'VIEW-FORM-LINK-04': ('VIEW.FORM.ENGINE', 'VIEW.FORM.BASE.EDITOR'), 'VIEW-FORM-LINK-05': ('VIEW.FORM.BASE.EDITOR', 'VIEW.FORM.SCOPE'), 'VIEW-FORM-LINK-06': ('VIEW.FORM.SCOPE', 'VIEW.FORM.CATALOG'), 'VIEW-FORM-LINK-07': ('VIEW.FORM.PROPOSAL.CONTROLLER', 'VIEW.FORM.SCOPE'), 'VIEW-FORM-LINK-08': ('VIEW.FORM.PROPOSAL.CONTROLLER', 'VIEW.FORM.POLICY.CONTROL')}
EXPECTED_LINKS.update(VIEW_FORM_LINKS)
EXPECTED_SOURCES.update({'RECOVERY.PhorgeRecoveryNativeAPIFacts': ('src/infrastructure/governance/recovery/PhorgeRecoveryNativeAPIFacts.php', 'PhorgeRecoveryNativeAPIFacts', 'untrusted-api-storage-decoder-no-recovery-authority')})
EXPECTED_RUNTIME_FILES=sorted(EXPECTED_RUNTIME_FILES+['src/infrastructure/governance/recovery/PhorgeRecoveryNativeAPIFacts.php'])

EXPECTED_SOURCES.update({'NOTICE.NATIVE.ADMIN.EDITOR': ('src/applications/people/editor/PhabricatorUserTransactionEditor.php','PhabricatorUserTransactionEditor','native-admin-grant-only-outbox-no-role-authority')})
EXPECTED_LINKS.update({'NOTICE-LINK-16': ('NOTICE.NATIVE.ADMIN.EDITOR','NOTICE.OUTBOX'),'NOTICE-LINK-17': ('NOTICE.SOURCE','RECOVERY.NATIVE.USER.TRANSACTION')})

# Exact selected Conduit producer addition; static source only.
EXPECTED_RUNTIME_FILES=sorted(EXPECTED_RUNTIME_FILES+['src/infrastructure/governance/recovery/PhorgeRecoveryConduitProducerFence.php'])
EXPECTED_SOURCES['RECOVERY.CONDUIT.PRODUCER.FENCE']=('src/infrastructure/governance/recovery/PhorgeRecoveryConduitProducerFence.php','PhorgeRecoveryConduitProducerFence','selected-conduit-insert-participant')

def require(ok, message):
    if not ok:
        raise ValueError(message)

def unique_object(pairs):
    value = {}
    for key, item in pairs:
        require(key not in value, 'duplicate JSON key')
        value[key] = item
    return value

def load(path):
    require(Path(path).stat().st_size <= 262144, 'manifest too large')
    return json.loads(Path(path).read_text(), object_pairs_hook=unique_object)

def file(root, raw):
    require(isinstance(raw, str) and raw and not Path(raw).is_absolute() and
            '..' not in Path(raw).parts and Path(raw).as_posix() == raw,
            'source path refused')
    path = root / raw
    require(path.is_file() and not path.is_symlink() and
            path.resolve().is_relative_to(root.resolve()), 'source file refused')
    return path

def fields(value, expected, message):
    require(isinstance(value, dict) and set(value) == set(expected), message)

def keyed(rows, message):
    require(isinstance(rows, list), message)
    result = {}
    for row in rows:
        require(isinstance(row, dict) and isinstance(row.get('id'), str) and
                row['id'] not in result, message)
        result[row['id']] = row
    return result

EXPECTED_SOURCES.update({'RECOVERY.PhorgeGovernanceRecoverySourceInventory': ('src/infrastructure/governance/recovery/PhorgeGovernanceRecoverySourceInventory.php', 'PhorgeGovernanceRecoverySourceInventory', 'isolated-account-capture-not-recovery-authority'), 'RECOVERY.PhorgeRecoveryNativeAccountFacts': ('src/infrastructure/governance/recovery/PhorgeRecoveryNativeAccountFacts.php', 'PhorgeRecoveryNativeAccountFacts', 'isolated-account-capture-not-recovery-authority'), 'RECOVERY.PhorgeRecoveryNativeCaptureScope': ('src/infrastructure/governance/recovery/PhorgeRecoveryNativeCaptureScope.php', 'PhorgeRecoveryNativeCaptureScope', 'isolated-account-capture-not-recovery-authority')})
EXPECTED_RUNTIME_FILES=sorted(EXPECTED_RUNTIME_FILES+['src/infrastructure/governance/recovery/PhorgeGovernanceRecoverySourceInventory.php', 'src/infrastructure/governance/recovery/PhorgeRecoveryNativeAccountFacts.php', 'src/infrastructure/governance/recovery/PhorgeRecoveryNativeCaptureScope.php'])

EXPECTED_SOURCES.update({'RECOVERY.PhorgeRecoveryNativeWebFacts': ('src/infrastructure/governance/recovery/PhorgeRecoveryNativeWebFacts.php', 'PhorgeRecoveryNativeWebFacts', 'untrusted-web-storage-decoder-no-recovery-authority')})
EXPECTED_RUNTIME_FILES=sorted(EXPECTED_RUNTIME_FILES+['src/infrastructure/governance/recovery/PhorgeRecoveryNativeWebFacts.php'])

EXPECTED_SOURCES.update({'RECOVERY.PhorgeRecoveryStop': ('src/infrastructure/governance/recovery/PhorgeRecoveryStop.php',
                                 'PhorgeRecoveryStop',
                                 'stop-classification-no-recovery-authority'),
 'RECOVERY.PhorgeRecoveryStopConfigOptions': ('src/infrastructure/governance/recovery/PhorgeRecoveryStopConfigOptions.php',
                                              'PhorgeRecoveryStopConfigOptions',
                                              'stop-classification-no-recovery-authority'),
 'RECOVERY.PhorgeRecoveryStopRequest': ('src/infrastructure/governance/recovery/PhorgeRecoveryStopRequest.php',
                                        'PhorgeRecoveryStopRequest',
                                        'stop-classification-no-recovery-authority'),
 'RECOVERY.PhorgeRecoveryStopSchemaSpec': ('src/infrastructure/governance/recovery/PhorgeRecoveryStopSchemaSpec.php',
                                           'PhorgeRecoveryStopSchemaSpec',
                                           'stop-classification-no-recovery-authority'),
 'RECOVERY.PhorgeRecoveryStopService': ('src/infrastructure/governance/recovery/PhorgeRecoveryStopService.php',
                                        'PhorgeRecoveryStopService',
                                        'stop-classification-no-recovery-authority'),
 'RECOVERY.NATIVE.DISABLE.TYPE': ('src/applications/people/xaction/PhabricatorUserDisableTransaction.php',
                                  'PhabricatorUserDisableTransaction',
                                  'native-disable-event-semantics'),
 'RECOVERY.NATIVE.USER.TRANSACTION': ('src/applications/people/storage/PhabricatorUserTransaction.php',
                                      'PhabricatorUserTransaction',
                                      'native-committed-stop-source')})
EXPECTED_RUNTIME_FILES=sorted(EXPECTED_RUNTIME_FILES+['src/infrastructure/governance/recovery/PhorgeRecoveryStop.php', 'src/infrastructure/governance/recovery/PhorgeRecoveryStopConfigOptions.php', 'src/infrastructure/governance/recovery/PhorgeRecoveryStopRequest.php', 'src/infrastructure/governance/recovery/PhorgeRecoveryStopSchemaSpec.php', 'src/infrastructure/governance/recovery/PhorgeRecoveryStopService.php'])
EXPECTED_LINKS.update({'NATIVE-LINK-66': ('RECOVERY.PhorgeRecoveryStopService', 'NATIVE.PhorgeGovernanceNativeIdentitySource'), 'NATIVE-LINK-67': ('RECOVERY.PhorgeRecoveryStopService', 'AUTH.USER.DAO'), 'NATIVE-LINK-68': ('RECOVERY.PhorgeRecoveryStopService', 'RECOVERY.NATIVE.USER.TRANSACTION'), 'NATIVE-LINK-69': ('RECOVERY.PhorgeRecoveryStopService', 'RECOVERY.NATIVE.DISABLE.TYPE'), 'NATIVE-LINK-70': ('RECOVERY.PhorgeRecoveryStopService', 'RECOVERY.PhorgeRecoveryStopRequest'), 'NATIVE-LINK-71': ('RECOVERY.PhorgeRecoveryStopService', 'RECOVERY.PhorgeRecoveryStop')})

# Exact error-boundary references are not authorization evidence.
EXPECTED_SOURCES.update({'POLICY.ERROR.STORE': ('src/infrastructure/governance/execution/PhorgeProjectPolicyApprovalStore.php', 'PhorgeProjectPolicyApprovalStore', 'current-native-ticket-store-error-boundary'), 'POLICY.ERROR.STATE': ('src/infrastructure/governance/execution/PhorgeProjectPolicyTicketState.php', 'PhorgeProjectPolicyTicketState', 'stored-ticket-consistency-not-authority'), 'POLICY.ERROR.REFUSAL': ('src/infrastructure/governance/execution/PhorgeProjectPolicyRefusalException.php', 'PhorgeProjectPolicyRefusalException', 'explicit-business-refusal-no-permit'), 'POLICY.ERROR.PROJECTION': ('src/infrastructure/governance/execution/PhorgeProjectPolicyFailure.php', 'PhorgeProjectPolicyFailure', 'sanitized-error-projection-no-permit')})
EXPECTED_LINKS.update({'POLICY-ERROR-LINK-01': ('POLICY.ERROR.STORE', 'POLICY.ERROR.STATE'), 'POLICY-ERROR-LINK-02': ('POLICY.ERROR.STATE', 'POLICY.ERROR.REFUSAL'), 'POLICY-ERROR-LINK-03': ('POLICY.ERROR.STORE', 'POLICY.ERROR.REFUSAL'), 'POLICY-ERROR-LINK-04': ('POLICY.ERROR.PROJECTION', 'POLICY.ERROR.REFUSAL')})
EXPECTED_LINKS.update({'POLICY-ERROR-LINK-05': ('VIEW.FORM.PROPOSAL.CONTROLLER', 'POLICY.ERROR.PROJECTION'), 'POLICY-ERROR-LINK-06': ('VIEW.FORM.ENGINE', 'POLICY.ERROR.PROJECTION'), 'POLICY-ERROR-LINK-07': ('VIEW.FORM.SCOPE', 'POLICY.ERROR.STATE')})
EXPECTED_LINKS.update({'POLICY-ERROR-LINK-08': ('VIEW.FORM.SCOPE', 'VIEW.FORM.CATALOG')})
EXPECTED_RUNTIME_FILES=sorted(EXPECTED_RUNTIME_FILES+['src/infrastructure/governance/execution/PhorgeProjectPolicyFailure.php', 'src/infrastructure/governance/execution/PhorgeProjectPolicyRefusalException.php', 'src/infrastructure/governance/execution/PhorgeProjectPolicyTicketState.php'])

# Explicit new notice nodes: static review is never activation or behavior proof.
EXPECTED_SOURCES.update({'NOTICE.VALUE': ('src/infrastructure/governance/notification/PhorgeGovernanceNoticeEventValue.php', 'PhorgeGovernanceNoticeEventValue', 'metadata-only-value-no-authority'), 'NOTICE.OUTBOX': ('src/infrastructure/governance/notification/PhorgeGovernanceNoticeOutbox.php', 'PhorgeGovernanceNoticeOutbox', 'same-source-transaction-qualified-outbox'), 'NOTICE.SOURCE': ('src/infrastructure/governance/notification/PhorgeGovernanceNoticeSource.php', 'PhorgeGovernanceNoticeSource', 'committed-audit-reference-not-authority'), 'NOTICE.POLICY': ('src/infrastructure/governance/notification/PhorgeGovernanceNoticeMailPolicy.php', 'PhorgeGovernanceNoticeMailPolicy', 'current-recipient-and-source-egress-filter'), 'NOTICE.WORKER': ('src/infrastructure/governance/notification/PhorgeGovernanceNoticeWorker.php', 'PhorgeGovernanceNoticeWorker', 'committed-native-notice-fanout'), 'NOTICE.CONFIG': ('src/infrastructure/governance/notification/PhorgeGovernanceNoticeConfigOptions.php', 'PhorgeGovernanceNoticeConfigOptions', 'default-off-notice-control'), 'NOTICE.SCHEMA': ('src/infrastructure/governance/notification/PhorgeGovernanceNoticeSchemaSpec.php', 'PhorgeGovernanceNoticeSchemaSpec', 'native-notice-storage-contract')})
EXPECTED_LINKS.update({'NOTICE-LINK-01': ('NATIVE.PhorgeOrganizationService', 'NOTICE.OUTBOX'), 'NOTICE-LINK-02': ('POLICY.ERROR.STORE', 'NOTICE.OUTBOX'), 'NOTICE-LINK-03': ('RECOVERY.PhorgeRecoveryStopService', 'NOTICE.OUTBOX'), 'NOTICE-LINK-04': ('NOTICE.OUTBOX', 'NOTICE.VALUE'), 'NOTICE-LINK-05': ('NOTICE.OUTBOX', 'NATIVE.PhorgeGovernanceNativeIdentitySource'), 'NOTICE-LINK-06': ('NOTICE.WORKER', 'NOTICE.POLICY'), 'NOTICE-LINK-07': ('NOTICE.WORKER', 'MAIL.OUTBOUND'), 'NOTICE-LINK-08': ('NOTICE.WORKER', 'NOTICE.SOURCE'), 'NOTICE-LINK-09': ('NOTICE.POLICY', 'NOTICE.SOURCE'), 'NOTICE-LINK-10': ('NOTICE.POLICY', 'NATIVE.PhorgeGovernanceNativeNoncredentialIdentitySource'), 'NOTICE-LINK-11': ('NATIVE.PhorgePrivateMailPolicy', 'NOTICE.POLICY'), 'NOTICE-LINK-12': ('NOTICE.SOURCE', 'NOTICE.VALUE')})
EXPECTED_RUNTIME_FILES=sorted(EXPECTED_RUNTIME_FILES+['src/infrastructure/governance/notification/PhorgeGovernanceNoticeEventValue.php', 'src/infrastructure/governance/notification/PhorgeGovernanceNoticeOutbox.php', 'src/infrastructure/governance/notification/PhorgeGovernanceNoticeSource.php', 'src/infrastructure/governance/notification/PhorgeGovernanceNoticeMailPolicy.php', 'src/infrastructure/governance/notification/PhorgeGovernanceNoticeWorker.php', 'src/infrastructure/governance/notification/PhorgeGovernanceNoticeConfigOptions.php', 'src/infrastructure/governance/notification/PhorgeGovernanceNoticeSchemaSpec.php'])
# Static isolated producer references do not certify any recovery family.
EXPECTED_SOURCES.update({'RECOVERY.PRODUCER.PhorgeGovernanceRecoveryConfigOptions': ('src/infrastructure/governance/recovery/PhorgeGovernanceRecoveryConfigOptions.php', 'PhorgeGovernanceRecoveryConfigOptions', 'isolated-recovery-foundation-static-no-permit'), 'RECOVERY.PRODUCER.PhorgeGovernanceRecoveryGuard': ('src/infrastructure/governance/recovery/PhorgeGovernanceRecoveryGuard.php', 'PhorgeGovernanceRecoveryGuard', 'isolated-recovery-foundation-static-no-permit'), 'RECOVERY.PRODUCER.PhorgeGovernanceRecoveryTransition': ('src/infrastructure/governance/recovery/PhorgeGovernanceRecoveryTransition.php', 'PhorgeGovernanceRecoveryTransition', 'isolated-recovery-foundation-static-no-permit'), 'RECOVERY.PRODUCER.PhorgeRecoveryRestoreAssessment': ('src/infrastructure/governance/recovery/PhorgeRecoveryRestoreAssessment.php', 'PhorgeRecoveryRestoreAssessment', 'isolated-recovery-foundation-static-no-permit'), 'RECOVERY.PRODUCER.PhorgeRecoveryRestoreMediaSource': ('src/infrastructure/governance/recovery/PhorgeRecoveryRestoreMediaSource.php', 'PhorgeRecoveryRestoreMediaSource', 'isolated-recovery-foundation-static-no-permit'), 'RECOVERY.PRODUCER.PhorgeRecoveryRestoreOrganizationSource': ('src/infrastructure/governance/recovery/PhorgeRecoveryRestoreOrganizationSource.php', 'PhorgeRecoveryRestoreOrganizationSource', 'isolated-recovery-foundation-static-no-permit'), 'RECOVERY.PRODUCER.PhorgeRecoveryRestoreProfile': ('src/infrastructure/governance/recovery/PhorgeRecoveryRestoreProfile.php', 'PhorgeRecoveryRestoreProfile', 'isolated-recovery-foundation-static-no-permit'), 'RECOVERY.PRODUCER.PhorgeRecoveryRestoreRequest': ('src/infrastructure/governance/recovery/PhorgeRecoveryRestoreRequest.php', 'PhorgeRecoveryRestoreRequest', 'isolated-recovery-foundation-static-no-permit'), 'RECOVERY.PRODUCER.PhorgeRecoveryRestoreSchemaSpec': ('src/infrastructure/governance/recovery/PhorgeRecoveryRestoreSchemaSpec.php', 'PhorgeRecoveryRestoreSchemaSpec', 'isolated-recovery-foundation-static-no-permit'), 'RECOVERY.PRODUCER.PhorgeRecoveryRestoreService': ('src/infrastructure/governance/recovery/PhorgeRecoveryRestoreService.php', 'PhorgeRecoveryRestoreService', 'isolated-recovery-foundation-static-no-permit'), 'RECOVERY.PRODUCER.PhorgeRecoverySessionProducerFence': ('src/infrastructure/governance/recovery/PhorgeRecoverySessionProducerFence.php', 'PhorgeRecoverySessionProducerFence', 'isolated-recovery-foundation-static-no-permit')})
EXPECTED_LINKS.update({'RECOVERY-PRODUCER-LINK-01': ('AUTH.USER.DAO', 'RECOVERY.PRODUCER.PhorgeGovernanceRecoveryGuard'), 'RECOVERY-PRODUCER-LINK-02': ('AUTH.SESSION.DAO', 'RECOVERY.PRODUCER.PhorgeRecoverySessionProducerFence'), 'RECOVERY-PRODUCER-LINK-03': ('RECOVERY.PRODUCER.PhorgeGovernanceRecoveryGuard', 'RECOVERY.PRODUCER.PhorgeGovernanceRecoveryTransition'), 'RECOVERY-PRODUCER-LINK-04': ('RECOVERY.PRODUCER.PhorgeGovernanceRecoveryGuard', 'RECOVERY.PRODUCER.PhorgeRecoveryRestoreProfile'), 'RECOVERY-PRODUCER-LINK-05': ('RECOVERY.PRODUCER.PhorgeRecoverySessionProducerFence', 'RECOVERY.PRODUCER.PhorgeRecoveryRestoreProfile'), 'RECOVERY-PRODUCER-LINK-06': ('RECOVERY.PRODUCER.PhorgeRecoveryRestoreProfile', 'RECOVERY.PhorgeRecoveryNativeCaptureScope')})
EXPECTED_RUNTIME_FILES=sorted(EXPECTED_RUNTIME_FILES+['src/infrastructure/governance/recovery/PhorgeGovernanceRecoveryConfigOptions.php', 'src/infrastructure/governance/recovery/PhorgeGovernanceRecoveryGuard.php', 'src/infrastructure/governance/recovery/PhorgeGovernanceRecoveryTransition.php', 'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreAssessment.php', 'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreMediaSource.php', 'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreOrganizationSource.php', 'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreProfile.php', 'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreRequest.php', 'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreSchemaSpec.php', 'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreService.php', 'src/infrastructure/governance/recovery/PhorgeRecoverySessionProducerFence.php'])

# Explicit notice-only DAO invalidation node; no credentials or activation authority.
EXPECTED_SOURCES.update({'NOTICE.IDENTITY': ('src/infrastructure/governance/notification/PhorgeGovernanceNoticeIdentityScope.php','PhorgeGovernanceNoticeIdentityScope','notice-only-native-identity-generation-no-credential-authority')})
EXPECTED_LINKS.update({'NOTICE-LINK-13': ('AUTH.USER.DAO','NOTICE.IDENTITY'),'NOTICE-LINK-14': ('NOTICE.IDENTITY','NOTICE.OUTBOX'),'NOTICE-LINK-15': ('NOTICE.POLICY','NOTICE.IDENTITY')})
EXPECTED_RUNTIME_FILES=sorted(EXPECTED_RUNTIME_FILES+['src/infrastructure/governance/notification/PhorgeGovernanceNoticeIdentityScope.php'])
# Reviewed bounded org/policy primitives; static references never certify source closure.
EXPECTED_SOURCES.update({'RECOVERY.ORG.PhorgeRecoveryMetadataGrantDescription': ('src/infrastructure/governance/recovery/PhorgeRecoveryMetadataGrantDescription.php', 'PhorgeRecoveryMetadataGrantDescription', 'isolated-bounded-organization-source-not-restore-authority'), 'RECOVERY.ORG.PhorgeRecoveryOrganizationMetadataObservation': ('src/infrastructure/governance/recovery/PhorgeRecoveryOrganizationMetadataObservation.php', 'PhorgeRecoveryOrganizationMetadataObservation', 'isolated-bounded-organization-source-not-restore-authority'), 'RECOVERY.ORG.PhorgeRecoveryOrganizationProducerDAO': ('src/infrastructure/governance/recovery/PhorgeRecoveryOrganizationProducerDAO.php', 'PhorgeRecoveryOrganizationProducerDAO', 'isolated-bounded-organization-source-not-restore-authority'), 'RECOVERY.ORG.PhorgeRecoveryOrganizationProducerFence': ('src/infrastructure/governance/recovery/PhorgeRecoveryOrganizationProducerFence.php', 'PhorgeRecoveryOrganizationProducerFence', 'isolated-bounded-organization-source-not-restore-authority'), 'RECOVERY.ORG.PhorgeRecoveryRestorePolicySource': ('src/infrastructure/governance/recovery/PhorgeRecoveryRestorePolicySource.php', 'PhorgeRecoveryRestorePolicySource', 'isolated-bounded-organization-source-not-restore-authority')})
EXPECTED_LINKS.update({'RECOVERY-ORG-LINK-01': ('RECOVERY.ORG.PhorgeRecoveryOrganizationProducerDAO', 'RECOVERY.ORG.PhorgeRecoveryOrganizationProducerFence'), 'RECOVERY-ORG-LINK-02': ('RECOVERY.ORG.PhorgeRecoveryOrganizationProducerFence', 'RECOVERY.PRODUCER.PhorgeRecoveryRestoreProfile'), 'RECOVERY-ORG-LINK-03': ('RECOVERY.ORG.PhorgeRecoveryOrganizationMetadataObservation', 'RECOVERY.ORG.PhorgeRecoveryMetadataGrantDescription'), 'RECOVERY-ORG-LINK-04': ('RECOVERY.ORG.PhorgeRecoveryRestorePolicySource', 'RECOVERY.PRODUCER.PhorgeRecoveryRestoreProfile'), 'RECOVERY-ORG-LINK-05': ('NATIVE.PhorgeOrganizationService', 'RECOVERY.ORG.PhorgeRecoveryOrganizationProducerFence')})
EXPECTED_RUNTIME_FILES=sorted(EXPECTED_RUNTIME_FILES+['src/infrastructure/governance/recovery/PhorgeRecoveryMetadataGrantDescription.php', 'src/infrastructure/governance/recovery/PhorgeRecoveryOrganizationMetadataObservation.php', 'src/infrastructure/governance/recovery/PhorgeRecoveryOrganizationProducerDAO.php', 'src/infrastructure/governance/recovery/PhorgeRecoveryOrganizationProducerFence.php', 'src/infrastructure/governance/recovery/PhorgeRecoveryRestorePolicySource.php'])

# Isolated owned-RR mechanics candidate: static reference only, never a permit.
EXPECTED_SOURCES['RECOVERY.OWNED.RR.SCOPE']=('src/infrastructure/governance/recovery/PhorgeRecoveryOwnedRRScope.php','PhorgeRecoveryOwnedRRScope','candidate-runtime-source')
EXPECTED_RUNTIME_FILES=sorted(EXPECTED_RUNTIME_FILES+['src/infrastructure/governance/recovery/PhorgeRecoveryOwnedRRScope.php'])
EXPECTED_LINKS['RECOVERY-RR-LINK-01']=('RECOVERY.OWNED.RR.SCOPE','AUTH.USER.DAO')

# Explicit selected config dictionary dependencies; no runtime authority.
EXPECTED_SOURCES.update({'RECOVERY.CONFIG.FACTS': ('src/infrastructure/governance/recovery/PhorgeRecoveryApplicationConfigFacts.php', 'PhorgeRecoveryApplicationConfigFacts', 'selected-process-source-dictionary-facts-not-authority'), 'RECOVERY.CONFIG.PhabricatorEnv': ('src/infrastructure/env/PhabricatorEnv.php', 'PhabricatorEnv', 'native-config-source-dependency-static-only'), 'RECOVERY.CONFIG.PhabricatorConfigSource': ('src/infrastructure/env/PhabricatorConfigSource.php', 'PhabricatorConfigSource', 'native-config-source-dependency-static-only'), 'RECOVERY.CONFIG.PhabricatorConfigProxySource': ('src/infrastructure/env/PhabricatorConfigProxySource.php', 'PhabricatorConfigProxySource', 'native-config-source-dependency-static-only'), 'RECOVERY.CONFIG.PhabricatorConfigDictionarySource': ('src/infrastructure/env/PhabricatorConfigDictionarySource.php', 'PhabricatorConfigDictionarySource', 'native-config-source-dependency-static-only'), 'RECOVERY.CONFIG.PhabricatorConfigStackSource': ('src/infrastructure/env/PhabricatorConfigStackSource.php', 'PhabricatorConfigStackSource', 'native-config-source-dependency-static-only'), 'RECOVERY.CONFIG.PhabricatorConfigDefaultSource': ('src/infrastructure/env/PhabricatorConfigDefaultSource.php', 'PhabricatorConfigDefaultSource', 'native-config-source-dependency-static-only'), 'RECOVERY.CONFIG.PhabricatorConfigLocalSource': ('src/infrastructure/env/PhabricatorConfigLocalSource.php', 'PhabricatorConfigLocalSource', 'native-config-source-dependency-static-only'), 'RECOVERY.CONFIG.PhabricatorConfigDatabaseSource': ('src/infrastructure/env/PhabricatorConfigDatabaseSource.php', 'PhabricatorConfigDatabaseSource', 'native-config-source-dependency-static-only'), 'RECOVERY.CONFIG.PhabricatorConfigFileSource': ('src/infrastructure/env/PhabricatorConfigFileSource.php', 'PhabricatorConfigFileSource', 'native-config-source-dependency-static-only'), 'RECOVERY.CONFIG.PhabricatorDeploymentConfigSource': ('src/infrastructure/env/PhabricatorDeploymentConfigSource.php', 'PhabricatorDeploymentConfigSource', 'native-config-source-dependency-static-only')})
EXPECTED_LINKS.update({'RECOVERY-CONFIG-LINK-01': ('RECOVERY.CONFIG.FACTS', 'RECOVERY.CONFIG.PhabricatorEnv'), 'RECOVERY-CONFIG-LINK-02': ('RECOVERY.CONFIG.FACTS', 'RECOVERY.CONFIG.PhabricatorConfigSource'), 'RECOVERY-CONFIG-LINK-03': ('RECOVERY.CONFIG.FACTS', 'RECOVERY.CONFIG.PhabricatorConfigProxySource'), 'RECOVERY-CONFIG-LINK-04': ('RECOVERY.CONFIG.FACTS', 'RECOVERY.CONFIG.PhabricatorConfigDictionarySource'), 'RECOVERY-CONFIG-LINK-05': ('RECOVERY.CONFIG.FACTS', 'RECOVERY.CONFIG.PhabricatorConfigStackSource'), 'RECOVERY-CONFIG-LINK-06': ('RECOVERY.CONFIG.FACTS', 'RECOVERY.CONFIG.PhabricatorConfigDefaultSource'), 'RECOVERY-CONFIG-LINK-07': ('RECOVERY.CONFIG.FACTS', 'RECOVERY.CONFIG.PhabricatorConfigLocalSource'), 'RECOVERY-CONFIG-LINK-08': ('RECOVERY.CONFIG.FACTS', 'RECOVERY.CONFIG.PhabricatorConfigDatabaseSource'), 'RECOVERY-CONFIG-LINK-09': ('RECOVERY.CONFIG.PhabricatorEnv', 'RECOVERY.CONFIG.PhabricatorConfigFileSource'), 'RECOVERY-CONFIG-LINK-10': ('RECOVERY.CONFIG.PhabricatorEnv', 'RECOVERY.CONFIG.PhabricatorDeploymentConfigSource')})
EXPECTED_RUNTIME_FILES=sorted(EXPECTED_RUNTIME_FILES+['src/infrastructure/governance/recovery/PhorgeRecoveryApplicationConfigFacts.php'])

# Reviewed fixed Project row semantics, not permission or source-completion proof.
EXPECTED_SOURCES.update({'RECOVERY.PROJECT.FACTS': ('src/infrastructure/governance/recovery/PhorgeRecoveryProjectSourceFacts.php', 'PhorgeRecoveryProjectSourceFacts', 'subject-current-project-source-facts-not-policy-or-lease'), 'RECOVERY.PROJECT.PhabricatorProject': ('src/applications/project/storage/PhabricatorProject.php', 'PhabricatorProject', 'fixed-native-project-storage-producer-or-consumer-reference'), 'RECOVERY.PROJECT.PhabricatorProjectTransactionEditor': ('src/applications/project/editor/PhabricatorProjectTransactionEditor.php', 'PhabricatorProjectTransactionEditor', 'fixed-native-project-storage-producer-or-consumer-reference'), 'RECOVERY.PROJECT.PhabricatorEdgeQuery': ('src/infrastructure/edges/query/PhabricatorEdgeQuery.php', 'PhabricatorEdgeQuery', 'fixed-native-project-storage-producer-or-consumer-reference'), 'RECOVERY.PROJECT.PhabricatorEdgeEditor': ('src/infrastructure/edges/editor/PhabricatorEdgeEditor.php', 'PhabricatorEdgeEditor', 'fixed-native-project-storage-producer-or-consumer-reference'), 'RECOVERY.PROJECT.PhabricatorProjectsMembershipIndexEngineExtension': ('src/applications/project/engineextension/PhabricatorProjectsMembershipIndexEngineExtension.php', 'PhabricatorProjectsMembershipIndexEngineExtension', 'fixed-native-project-storage-producer-or-consumer-reference'), 'RECOVERY.PROJECT.PhabricatorProjectMembersPolicyRule': ('src/applications/project/policyrule/PhabricatorProjectMembersPolicyRule.php', 'PhabricatorProjectMembersPolicyRule', 'fixed-native-project-storage-producer-or-consumer-reference')})
EXPECTED_LINKS.update({'RECOVERY-PROJECT-LINK-01': ('RECOVERY.PROJECT.FACTS', 'RECOVERY.PRODUCER.PhorgeRecoveryRestoreProfile'), 'RECOVERY-PROJECT-LINK-02': ('RECOVERY.PROJECT.FACTS', 'RECOVERY.PROJECT.PhabricatorProject'), 'RECOVERY-PROJECT-LINK-03': ('RECOVERY.PROJECT.FACTS', 'PROJECT.NATIVE.VIEWER.QUERY'), 'RECOVERY-PROJECT-LINK-04': ('RECOVERY.PROJECT.FACTS', 'RECOVERY.PROJECT.PhabricatorProjectMembersPolicyRule'), 'RECOVERY-PROJECT-LINK-05': ('RECOVERY.PROJECT.PhabricatorProjectTransactionEditor', 'RECOVERY.PROJECT.PhabricatorProjectsMembershipIndexEngineExtension'), 'RECOVERY-PROJECT-LINK-06': ('RECOVERY.PROJECT.PhabricatorProjectTransactionEditor', 'RECOVERY.PROJECT.PhabricatorEdgeEditor'), 'RECOVERY-PROJECT-LINK-07': ('RECOVERY.PROJECT.PhabricatorProjectsMembershipIndexEngineExtension', 'RECOVERY.PROJECT.PhabricatorEdgeQuery'), 'RECOVERY-PROJECT-LINK-08': ('RECOVERY.PROJECT.PhabricatorProjectMembersPolicyRule', 'RECOVERY.PROJECT.PhabricatorEdgeQuery')})
EXPECTED_RUNTIME_FILES=sorted(EXPECTED_RUNTIME_FILES+['src/infrastructure/governance/recovery/PhorgeRecoveryProjectSourceFacts.php'])

# Fixed Space row/Query cache references; all coverage remains partial.
EXPECTED_SOURCES.update({'RECOVERY.SPACES.FACTS': ('src/infrastructure/governance/recovery/PhorgeRecoverySpacesSourceFacts.php', 'PhorgeRecoverySpacesSourceFacts', 'selected-native-Space-and-Query-cache-facts-not-policy-or-lease'), 'RECOVERY.SPACES.NAMESPACE': ('src/applications/spaces/storage/PhabricatorSpacesNamespace.php', 'PhabricatorSpacesNamespace', 'fixed-native-Space-storage-reference'), 'RECOVERY.SPACES.QUERY': ('src/applications/spaces/query/PhabricatorSpacesNamespaceQuery.php', 'PhabricatorSpacesNamespaceQuery', 'fixed-native-Space-Query-cache-reference'), 'RECOVERY.SPACES.EDITOR': ('src/applications/spaces/editor/PhabricatorSpacesNamespaceEditor.php', 'PhabricatorSpacesNamespaceEditor', 'fixed-native-Space-transaction-producer-reference'), 'RECOVERY.SPACES.CACHE': ('src/infrastructure/cache/PhutilInRequestKeyValueCache.php', 'PhutilInRequestKeyValueCache', 'fixed-native-request-cache-storage-reference'), 'RECOVERY.SPACES.POLICYFILTER': ('src/applications/policy/filter/PhabricatorPolicyFilter.php', 'PhabricatorPolicyFilter', 'fixed-native-Space-selection-and-policy-reference')})
EXPECTED_LINKS.update({'RECOVERY-SPACES-LINK-01': ('RECOVERY.SPACES.FACTS', 'RECOVERY.PRODUCER.PhorgeRecoveryRestoreProfile'), 'RECOVERY-SPACES-LINK-02': ('RECOVERY.SPACES.FACTS', 'RECOVERY.SPACES.NAMESPACE'), 'RECOVERY-SPACES-LINK-03': ('RECOVERY.SPACES.FACTS', 'RECOVERY.SPACES.QUERY'), 'RECOVERY-SPACES-LINK-04': ('RECOVERY.SPACES.FACTS', 'RECOVERY.SPACES.CACHE'), 'RECOVERY-SPACES-LINK-05': ('RECOVERY.SPACES.FACTS', 'RECOVERY.SPACES.POLICYFILTER'), 'RECOVERY-SPACES-LINK-06': ('RECOVERY.SPACES.EDITOR', 'RECOVERY.SPACES.NAMESPACE'), 'RECOVERY-SPACES-LINK-07': ('RECOVERY.SPACES.QUERY', 'RECOVERY.SPACES.CACHE')})
EXPECTED_RUNTIME_FILES=sorted(EXPECTED_RUNTIME_FILES+['src/infrastructure/governance/recovery/PhorgeRecoverySpacesSourceFacts.php'])

# Fixed Task owner subset; all references remain partial and non-authoritative.
EXPECTED_SOURCES.update({'RECOVERY.TASKOWNER.FACTS': ('src/infrastructure/governance/recovery/PhorgeRecoveryTaskOwnerSource.php', 'PhorgeRecoveryTaskOwnerSource', 'fixed-native-Task-owner-partial-source'), 'RECOVERY.TASKOWNER.TASK': ('src/applications/maniphest/storage/ManiphestTask.php', 'ManiphestTask', 'native-owner-automatic-function-not-full-policy'), 'RECOVERY.TASKOWNER.OWNERTRANSACTION': ('src/applications/maniphest/xaction/ManiphestTaskOwnerTransaction.php', 'ManiphestTaskOwnerTransaction', 'native-reassign-storage-producer'), 'RECOVERY.TASKOWNER.EDITOR': ('src/applications/maniphest/editor/ManiphestTransactionEditor.php', 'ManiphestTransactionEditor', 'native-Task-transaction-producer'), 'RECOVERY.TASKOWNER.STATUS': ('src/applications/maniphest/constants/ManiphestTaskStatus.php', 'ManiphestTaskStatus', 'native-comment-edit-lock-policy-reference'), 'RECOVERY.TASKOWNER.PEOPLEQUERY': ('src/applications/people/query/PhabricatorPeopleQuery.php', 'PhabricatorPeopleQuery', 'native-assignee-validation-reference'), 'RECOVERY.TASKOWNER.POLICYFILTER': ('src/applications/policy/filter/PhabricatorPolicyFilter.php', 'PhabricatorPolicyFilter', 'native-Space-gate-before-automatic-capability-reference')})
EXPECTED_LINKS.update({'RECOVERY-TASKOWNER-LINK-01': ('RECOVERY.TASKOWNER.FACTS', 'RECOVERY.OWNED.RR.SCOPE'), 'RECOVERY-TASKOWNER-LINK-02': ('RECOVERY.TASKOWNER.FACTS', 'RECOVERY.PRODUCER.PhorgeRecoveryRestoreProfile'), 'RECOVERY-TASKOWNER-LINK-03': ('RECOVERY.TASKOWNER.FACTS', 'RECOVERY.TASKOWNER.TASK'), 'RECOVERY-TASKOWNER-LINK-04': ('RECOVERY.TASKOWNER.FACTS', 'RECOVERY.TASKOWNER.POLICYFILTER'), 'RECOVERY-TASKOWNER-LINK-05': ('RECOVERY.TASKOWNER.TASK', 'RECOVERY.TASKOWNER.STATUS'), 'RECOVERY-TASKOWNER-LINK-06': ('RECOVERY.TASKOWNER.EDITOR', 'RECOVERY.TASKOWNER.OWNERTRANSACTION'), 'RECOVERY-TASKOWNER-LINK-07': ('RECOVERY.TASKOWNER.OWNERTRANSACTION', 'RECOVERY.TASKOWNER.TASK'), 'RECOVERY-TASKOWNER-LINK-08': ('RECOVERY.TASKOWNER.OWNERTRANSACTION', 'RECOVERY.TASKOWNER.PEOPLEQUERY'), 'RECOVERY-TASKOWNER-LINK-09': ('RECOVERY.TASKOWNER.EDITOR', 'RECOVERY.TASKOWNER.TASK')})
EXPECTED_RUNTIME_FILES=sorted(EXPECTED_RUNTIME_FILES+['src/infrastructure/governance/recovery/PhorgeRecoveryTaskOwnerSource.php'])

# Native author-rooted File references, never whole-family completion.
EXPECTED_SOURCES.update({'RECOVERY.FILE.FACTS': ('src/infrastructure/governance/recovery/PhorgeRecoveryFileSourceFacts.php', 'PhorgeRecoveryFileSourceFacts', 'author-rooted-File-topology-not-full-policy'), 'RECOVERY.FILE.FILE': ('src/applications/files/storage/PhabricatorFile.php', 'PhabricatorFile', 'native-File-author-policy-storage-consumer'), 'RECOVERY.FILE.QUERY': ('src/applications/files/query/PhabricatorFileQuery.php', 'PhabricatorFileQuery', 'native-viewer-filtered-File-parent-transform-consumer'), 'RECOVERY.FILE.ATTACHMENT': ('src/applications/files/storage/PhabricatorFileAttachment.php', 'PhabricatorFileAttachment', 'native-attachment-mode-storage-reference'), 'RECOVERY.FILE.ATTACHMENTQUERY': ('src/applications/files/query/PhabricatorFileAttachmentQuery.php', 'PhabricatorFileAttachmentQuery', 'native-parent-policy-attachment-query-reference'), 'RECOVERY.FILE.TRANSFORMROW': ('src/applications/files/storage/PhabricatorTransformedFile.php', 'PhabricatorTransformedFile', 'native-transform-relation-storage-reference'), 'RECOVERY.FILE.TRANSFORM': ('src/applications/files/transform/PhabricatorFileTransform.php', 'PhabricatorFileTransform', 'native-transform-generator-reference-not-exercised'), 'RECOVERY.FILE.EDITOR': ('src/applications/files/editor/PhabricatorFileEditor.php', 'PhabricatorFileEditor', 'native-File-policy-edit-reference'), 'RECOVERY.FILE.GENERICEDITOR': ('src/applications/transactions/editor/PhabricatorApplicationTransactionEditor.php', 'PhabricatorApplicationTransactionEditor', 'native-attachment-reference-detach-producer'), 'RECOVERY.FILE.DATA': ('src/applications/files/controller/PhabricatorFileDataController.php', 'PhabricatorFileDataController', 'native-secret-URI-consumer-not-covered')})
EXPECTED_LINKS.update({'RECOVERY-FILE-LINK-01': ('RECOVERY.FILE.FACTS', 'RECOVERY.OWNED.RR.SCOPE'), 'RECOVERY-FILE-LINK-02': ('RECOVERY.FILE.FACTS', 'RECOVERY.PRODUCER.PhorgeRecoveryRestoreProfile'), 'RECOVERY-FILE-LINK-03': ('RECOVERY.FILE.FACTS', 'RECOVERY.FILE.FILE'), 'RECOVERY-FILE-LINK-04': ('RECOVERY.FILE.FACTS', 'RECOVERY.FILE.ATTACHMENT'), 'RECOVERY-FILE-LINK-05': ('RECOVERY.FILE.FACTS', 'RECOVERY.FILE.TRANSFORMROW'), 'RECOVERY-FILE-LINK-06': ('RECOVERY.FILE.QUERY', 'RECOVERY.FILE.FILE'), 'RECOVERY-FILE-LINK-07': ('RECOVERY.FILE.QUERY', 'RECOVERY.FILE.ATTACHMENT'), 'RECOVERY-FILE-LINK-08': ('RECOVERY.FILE.QUERY', 'RECOVERY.FILE.TRANSFORMROW'), 'RECOVERY-FILE-LINK-09': ('RECOVERY.FILE.EDITOR', 'RECOVERY.FILE.FILE'), 'RECOVERY-FILE-LINK-10': ('RECOVERY.FILE.GENERICEDITOR', 'RECOVERY.FILE.ATTACHMENT'), 'RECOVERY-FILE-LINK-11': ('RECOVERY.FILE.TRANSFORM', 'RECOVERY.FILE.TRANSFORMROW'), 'RECOVERY-FILE-LINK-12': ('RECOVERY.FILE.DATA', 'RECOVERY.FILE.FILE')})
EXPECTED_RUNTIME_FILES=sorted(EXPECTED_RUNTIME_FILES+['src/infrastructure/governance/recovery/PhorgeRecoveryFileSourceFacts.php'])

# Explicit optional administrator approval workbench; static partial evidence only.
EXPECTED_SOURCES['ORG.APPROVAL.WORKBENCH']=('src/applications/people/governance/controller/PhorgeOrganizationApprovalController.php','PhorgeOrganizationApprovalController','candidate-runtime-source')
EXPECTED_LINKS.update({'ORG-APPROVAL-WORKBENCH-ROUTE':('ORG.ROUTES','ORG.APPROVAL.WORKBENCH'),'ORG-APPROVAL-WORKBENCH-SERVICE':('ORG.APPROVAL.WORKBENCH','NATIVE.PhorgeOrganizationService')})
EXPECTED_RUNTIME_FILES=sorted(EXPECTED_RUNTIME_FILES+['src/applications/people/governance/controller/PhorgeOrganizationApprovalController.php'])

# Existing default-OFF Project content grant classes: static scan admission only.
EXPECTED_RUNTIME_FILES=sorted(EXPECTED_RUNTIME_FILES+['src/infrastructure/governance/content/PhorgeProjectContentGrantConfigOptions.php', 'src/infrastructure/governance/content/PhorgeProjectContentGrantPlan.php', 'src/infrastructure/governance/content/PhorgeProjectContentGrantRequest.php', 'src/infrastructure/governance/content/PhorgeProjectContentGrantSchemaSpec.php', 'src/infrastructure/governance/content/PhorgeProjectContentGrantService.php', 'src/infrastructure/governance/content/PhorgeProjectContentGrantStore.php', 'src/infrastructure/governance/content/PhorgeProjectContentSource.php'])

def validate(data, root):
    root = Path(root)
    fields(data, ('schema_version','scope','coverage','activation_blocked',
                  'runtime_certified','authority_proof','sources','links','scan'), 'manifest fields refused')
    require(type(data['schema_version']) is int and data['schema_version'] == 1 and
            data['scope'] == 'native-directory-source-inventory', 'manifest scope refused')
    require(data['coverage'] == 'partial' and data['activation_blocked'] is True and
            data['runtime_certified'] is False and data['authority_proof'] is False,
            'static evidence cannot authorize activation')
    sources = keyed(data['sources'], 'source IDs refused')
    require(set(sources) == set(EXPECTED_SOURCES), 'source inventory drift')
    for key, (raw, symbol, kind) in EXPECTED_SOURCES.items():
        row = sources[key]
        fields(row, ('id','path','symbol','kind','sha256','coverage','evidence_status',
                     'activation_blocked','authority_proof'), 'source fields refused')
        require((row['path'],row['symbol'],row['kind']) == (raw,symbol,kind), 'source identity drift')
        require(row['coverage'] == 'partial' and row['evidence_status'] == 'static-reference-only' and
                row['activation_blocked'] is True and row['authority_proof'] is False,
                'source must remain non-authoritative')
        path = file(root, raw)
        require(row['sha256'] == hashlib.sha256(path.read_bytes()).hexdigest(), 'source hash drift')
        if key=='CLIENT.MEDIA.HANDOFF':
            require("JX.behavior('private-media-session'," in path.read_text(),'fixed media behavior missing')
        elif key=='CLIENT.TOKENIZER.BEHAVIOR':
            require("JX.behavior('aphront-basic-tokenizer'," in path.read_text(),'fixed tokenizer behavior missing')
        elif key=='CLIENT.PREFAB':
            require("JX.install('Prefab'," in path.read_text(),'fixed client symbol reference missing')
        else:
            require(re.search(r'\bclass\s+'+re.escape(symbol)+r'\b', path.read_text()) is not None,
                    'declared class reference missing')
    links = keyed(data['links'], 'link IDs refused')
    require(set(links) == set(EXPECTED_LINKS), 'link inventory drift')
    for key, pair in EXPECTED_LINKS.items():
        row = links[key]
        fields(row, ('id','from_id','to_id','kind','runtime_verified','basis'), 'link fields refused')
        require((row['from_id'],row['to_id']) == pair and
                all(node in sources for node in pair), 'link topology drift')
        require(row['kind'] == 'static-source-reference' and row['runtime_verified'] is False and
                isinstance(row['basis'],str) and bool(row['basis']), 'link is not runtime proof')
    fields(data['scan'], ('files',), 'scan fields refused')
    actual = sorted(str(path.relative_to(root)) for prefix in ROOTS
                    for path in (root / prefix).rglob('*.php') if '__tests__' not in path.parts)
    require(actual == EXPECTED_RUNTIME_FILES, 'new or removed runtime source needs review')
    rows = data['scan']['files']
    require(isinstance(rows,list) and len(rows) == len(actual), 'runtime scan drift')
    for row, raw in zip(rows, actual):
        fields(row, ('path','sha256'), 'runtime scan row refused')
        require(row['path'] == raw and row['sha256'] ==
                hashlib.sha256(file(root,raw).read_bytes()).hexdigest(), 'runtime source drift')
    return dict(passed=True, sources=len(sources), links=len(links),
                runtime_files=len(actual), coverage='partial', activation_blocked=True,
                runtime_certified=False, authority_proof=False)

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.parse_args()
    root = Path(__file__).resolve().parents[2]
    try:
        result = validate(load(root / 'docs/governance/native-directory-callers.json'), root)
    except (ValueError, OSError, UnicodeError, TypeError, KeyError):
        print(json.dumps(dict(passed=False,reason='native_source_inventory_refused')))
        return 1
    print(json.dumps(result,sort_keys=True))
    return 0

if __name__ == '__main__':
    raise SystemExit(main())

"""Pin native-directory static source closure; never certify authorization."""
import argparse
import hashlib
import json
from pathlib import Path
import re

EXPECTED_SOURCES = {'ORG.ROUTES': ('src/applications/people/application/PhabricatorPeopleApplication.php',
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

EXPECTED_SOURCES.update({'MAIL.ACTOR.QUERY': ('src/applications/metamta/query/PhabricatorMetaMTAActorQuery.php',
                      'PhabricatorMetaMTAActorQuery',
                      'native-recipient-deliverability'),
 'MAIL.DELIVERY.API': ('src/applications/metamta/conduit/PhabricatorMailDeliveryConduitAPIMethod.php',
                       'PhabricatorMailDeliveryConduitAPIMethod',
                       'service-authenticated-email-attempt'),
 'MAIL.MESSAGE.ENGINE': ('src/applications/metamta/engine/PhabricatorMailEmailEngine.php',
                         'PhabricatorMailEmailEngine',
                         'native-email-message-construction'),
 'MAIL.OUTBOUND': ('src/applications/metamta/storage/PhabricatorMetaMTAMail.php',
                   'PhabricatorMetaMTAMail',
                   'immutable-native-mail-and-attempt-gate'),
 'MAIL.TRANSACTION.PRODUCER': ('src/applications/transactions/editor/PhabricatorApplicationTransactionEditor.php',
                               'PhabricatorApplicationTransactionEditor',
                               'native-business-mail-producer'),
 'NATIVE.PhorgePrivateMailConfigOptions': ('src/infrastructure/governance/notification/PhorgePrivateMailConfigOptions.php',
                                           'PhorgePrivateMailConfigOptions',
                                           'default-off-private-email-configuration'),
 'NATIVE.PhorgePrivateMailPolicy': ('src/infrastructure/governance/notification/PhorgePrivateMailPolicy.php',
                                    'PhorgePrivateMailPolicy',
                                    'current-recipient-private-email-policy')})

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
 'src/infrastructure/governance/contracts/PhorgeGovernanceApprovalRequest.php',
 'src/infrastructure/governance/contracts/PhorgeGovernanceApprovalState.php',
 'src/infrastructure/governance/contracts/PhorgeGovernanceContentDecision.php',
 'src/infrastructure/governance/directory/PhorgeGovernanceDirectoryReader.php',
 'src/infrastructure/governance/directory/PhorgeGovernanceIsolatedDirectoryStore.php',
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

EXPECTED_RUNTIME_FILES = sorted(EXPECTED_RUNTIME_FILES + ['src/infrastructure/governance/notification/PhorgePrivateMailConfigOptions.php', 'src/infrastructure/governance/notification/PhorgePrivateMailPolicy.php'])

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

EXPECTED_LINKS.update({'NATIVE-LINK-27': ('MAIL.TRANSACTION.PRODUCER', 'MAIL.OUTBOUND'),
 'NATIVE-LINK-28': ('MAIL.OUTBOUND', 'NATIVE.PhorgePrivateMailPolicy'),
 'NATIVE-LINK-29': ('NATIVE.PhorgePrivateMailConfigOptions',
                    'NATIVE.PhorgePrivateMailPolicy'),
 'NATIVE-LINK-30': ('MAIL.DELIVERY.API', 'MAIL.OUTBOUND'),
 'NATIVE-LINK-31': ('MAIL.OUTBOUND', 'MAIL.ACTOR.QUERY'),
 'NATIVE-LINK-32': ('MAIL.OUTBOUND', 'MAIL.MESSAGE.ENGINE'),
 'NATIVE-LINK-33': ('NATIVE.PhorgePrivateMailPolicy', 'AUTH.USER.DAO')})

ROOTS = ('src/applications/people/governance', 'src/infrastructure/governance')

EXPECTED_SOURCES.update({'MAIL.MODULAR.EVENT.SOURCE': ('src/applications/transactions/storage/PhabricatorModularTransactionType.php',
                               'PhabricatorModularTransactionType',
                               'read-only-transaction-source-identity'),
 'MAIL.PEOPLE.FLOW.ENGINE': ('src/applications/people/mail/PhabricatorPeopleMailEngine.php',
                             'PhabricatorPeopleMailEngine',
                             'native-security-producer-flow-binding'),
 'MAIL.USER.RENAME.SOURCE': ('src/applications/people/xaction/PhabricatorUserUsernameTransaction.php',
                             'PhabricatorUserUsernameTransaction',
                             'native-security-transaction-source')})
EXPECTED_LINKS.update({'NATIVE-LINK-34': ('MAIL.USER.RENAME.SOURCE', 'MAIL.MODULAR.EVENT.SOURCE'),
 'NATIVE-LINK-35': ('MAIL.USER.RENAME.SOURCE', 'MAIL.PEOPLE.FLOW.ENGINE'),
 'NATIVE-LINK-36': ('MAIL.PEOPLE.FLOW.ENGINE',
                    'NATIVE.PhorgePrivateMailPolicy'),
 'NATIVE-LINK-37': ('NATIVE.PhorgePrivateMailPolicy', 'AUTH.USER.DAO')})

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
        if key=='CLIENT.TOKENIZER.BEHAVIOR':
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

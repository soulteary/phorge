#!/usr/bin/env python3
"""Validate reviewed notification sources and bounded native evidence, not SMTP acceptance."""
import argparse,hashlib,importlib.util,json,re
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
PRODUCERS={'src/applications/auth/controller/PhabricatorAuthRegisterController.php': ('PhabricatorAuthRegisterController',
                                                                            'per-flow-native-security-source-binding-required'),
 'src/applications/auth/controller/contact/PhabricatorAuthContactNumberTestController.php': ('PhabricatorAuthContactNumberTestController',
                                                                                             'per-flow-native-security-source-binding-required'),
 'src/applications/auth/editor/PhabricatorAuthSSHKeyEditor.php': ('PhabricatorAuthSSHKeyEditor',
                                                                  'per-flow-native-security-source-binding-required'),
 'src/applications/auth/factor/PhabricatorSMSAuthFactor.php': ('PhabricatorSMSAuthFactor',
                                                               'per-flow-native-security-source-binding-required'),
 'src/applications/auth/worker/PhabricatorAuthInviteWorker.php': ('PhabricatorAuthInviteWorker',
                                                                  'per-flow-native-security-source-binding-required'),
 'src/applications/badges/editor/PhabricatorBadgesEditor.php': ('PhabricatorBadgesEditor',
                                                                'business-producer'),
 'src/applications/calendar/editor/PhabricatorCalendarEventEditor.php': ('PhabricatorCalendarEventEditor',
                                                                         'business-producer'),
 'src/applications/calendar/notifications/PhabricatorCalendarNotificationEngine.php': ('PhabricatorCalendarNotificationEngine',
                                                                                       'business-producer'),
 'src/applications/conpherence/editor/ConpherenceEditor.php': ('ConpherenceEditor',
                                                               'business-producer'),
 'src/applications/countdown/editor/PhabricatorCountdownEditor.php': ('PhabricatorCountdownEditor',
                                                                      'business-producer'),
 'src/applications/files/editor/PhabricatorFileEditor.php': ('PhabricatorFileEditor',
                                                             'business-producer'),
 'src/applications/files/mail/FileCreateMailReceiver.php': ('FileCreateMailReceiver',
                                                            'business-producer'),
 'src/applications/herald/editor/HeraldRuleEditor.php': ('HeraldRuleEditor',
                                                         'business-producer'),
 'src/applications/legalpad/controller/LegalpadDocumentSignController.php': ('LegalpadDocumentSignController',
                                                                             'per-flow-native-security-source-binding-required'),
 'src/applications/legalpad/editor/LegalpadDocumentEditor.php': ('LegalpadDocumentEditor',
                                                                 'business-producer'),
 'src/applications/macro/editor/PhabricatorMacroEditor.php': ('PhabricatorMacroEditor',
                                                              'business-producer'),
 'src/applications/maniphest/editor/ManiphestTransactionEditor.php': ('ManiphestTransactionEditor',
                                                                      'business-producer'),
 'src/applications/metamta/management/PhabricatorMailManagementSendTestWorkflow.php': ('PhabricatorMailManagementSendTestWorkflow',
                                                                                       'operator-explicit-test-producer'),
 'src/applications/metamta/storage/PhabricatorMetaMTAReceivedMail.php': ('PhabricatorMetaMTAReceivedMail',
                                                                         'inbound-error-producer-private-original-body-and-headers'),
 'src/applications/people/mail/PhabricatorPeopleEmailLoginMailEngine.php': ('PhabricatorPeopleEmailLoginMailEngine',
                                                                            'per-flow-native-security-source-binding-required'),
 'src/applications/people/mail/PhabricatorPeopleUsernameMailEngine.php': ('PhabricatorPeopleUsernameMailEngine',
                                                                          'per-flow-native-security-source-binding-required'),
 'src/applications/people/mail/PhabricatorPeopleWelcomeMailEngine.php': ('PhabricatorPeopleWelcomeMailEngine',
                                                                         'per-flow-native-security-source-binding-required'),
 'src/applications/people/storage/PhabricatorUserEmail.php': ('PhabricatorUserEmail',
                                                              'per-flow-native-security-source-binding-required'),
 'src/applications/people/xaction/PhabricatorUserApproveTransaction.php': ('PhabricatorUserApproveTransaction',
                                                                           'per-flow-native-security-source-binding-required'),
 'src/applications/phame/editor/PhameBlogEditor.php': ('PhameBlogEditor',
                                                       'business-producer'),
 'src/applications/phame/editor/PhamePostEditor.php': ('PhamePostEditor',
                                                       'business-producer'),
 'src/applications/pholio/editor/PholioMockEditor.php': ('PholioMockEditor',
                                                         'business-producer'),
 'src/applications/phriction/editor/PhrictionTransactionEditor.php': ('PhrictionTransactionEditor',
                                                                      'business-producer'),
 'src/applications/phurl/editor/PhabricatorPhurlURLEditor.php': ('PhabricatorPhurlURLEditor',
                                                                 'business-producer'),
 'src/applications/policy/editor/PhorgePolicyNamedPolicyTransactionEditor.php': ('PhorgePolicyNamedPolicyTransactionEditor',
                                                                                 'business-producer'),
 'src/applications/ponder/editor/PonderAnswerEditor.php': ('PonderAnswerEditor',
                                                           'business-producer'),
 'src/applications/ponder/editor/PonderQuestionEditor.php': ('PonderQuestionEditor',
                                                             'business-producer'),
 'src/applications/project/editor/PhabricatorProjectTransactionEditor.php': ('PhabricatorProjectTransactionEditor',
                                                                             'business-producer'),
 'src/applications/slowvote/editor/PhabricatorSlowvoteEditor.php': ('PhabricatorSlowvoteEditor',
                                                                    'business-producer')}
PRODUCERS['src/applications/legalpad/controller/LegalpadDocumentSignController.php'] = ('LegalpadDocumentSignController','unsupported-signature-hard-refusal')
for _sms_path in ('src/applications/auth/controller/contact/PhabricatorAuthContactNumberTestController.php','src/applications/auth/factor/PhabricatorSMSAuthFactor.php'):
    PRODUCERS[_sms_path]=(PRODUCERS[_sms_path][0],'sms-outside-email-policy')
CONSUMERS=['src/applications/metamta/storage/PhabricatorMetaMTAMail.php', 'src/applications/metamta/conduit/PhabricatorMailDeliveryConduitAPIMethod.php', 'src/applications/metamta/query/PhabricatorMetaMTAActorQuery.php', 'src/applications/metamta/engine/PhabricatorMailEmailEngine.php', 'src/applications/transactions/editor/PhabricatorApplicationTransactionEditor.php', 'src/infrastructure/governance/notification/PhorgePrivateMailPolicy.php', 'src/infrastructure/governance/notification/PhorgePrivateMailConfigOptions.php', 'src/applications/people/mail/PhabricatorPeopleMailEngine.php', 'src/applications/people/xaction/PhabricatorUserUsernameTransaction.php', 'src/applications/transactions/storage/PhabricatorModularTransactionType.php']
BLOCKERS=['physical-callback-and-smtp-owlmail-runtime', 'all-producer-and-security-purpose-matrix', 'direct-multi-recipient-business-splitting', 'paired-release-and-activation-readiness', 'trusted-browser-and-global-governance-completion']

FLOW_STATUS={'account.login': 'native-producer-and-source-tested', 'account.welcome': 'native-producer-and-source-tested', 'account.username': 'native-transaction-and-source-tested', 'account.email.verify': 'native-producer-and-source-tested', 'account.email.old-primary': 'source-reviewed-runtime-pending', 'account.email.new-primary': 'source-reviewed-runtime-pending', 'account.approval.queue': 'native-producer-method-not-registration-http-tested', 'account.approved.owner': 'native-transaction-and-source-tested', 'account.approved.admin': 'native-transaction-and-source-tested', 'account.invite': 'native-worker-and-source-tested', 'account.ssh': 'source-reviewed-full-key-mutation-runtime-pending'}

def require(value,reason):
    if not value:raise ValueError(reason)
def strict_object(pairs):
    value={}
    for key,item in pairs:
        require(key not in value,'duplicate JSON key');value[key]=item
    return value
def load(path):
    return json.loads(Path(path).read_text(),object_pairs_hook=strict_object,
        parse_constant=lambda unused: (_ for _ in ()).throw(ValueError('JSON constant')))
def validate(data,root=ROOT):
    root=Path(root)
    require(type(data)is dict and set(data)=={'schema_version','scope','production_default_off','activation_blocked','authority_proof','all_producers_runtime_complete','producers','consumers','remaining_gates','security_flows','unsupported_security'},'fields')
    require(type(data['schema_version'])is int and data['schema_version']==1 and data['scope']=='private-email-producer-and-attempt-inventory','scope')
    require(data['production_default_off']is True and data['activation_blocked']is True and data['authority_proof']is False and data['all_producers_runtime_complete']is False,'partial scope')
    require(data['remaining_gates']==BLOCKERS,'remaining gates')
    require(type(data['producers'])is list and len(data['producers'])==len(PRODUCERS),'producer count')
    seen=set()
    for row in data['producers']:
        require(type(row)is dict and set(row)=={'path','symbol','purpose_review','sha256','runtime_matrix_complete'},'producer shape')
        path=row['path'];require(path in PRODUCERS and path not in seen,'producer identity');seen.add(path)
        require((row['symbol'],row['purpose_review'])==PRODUCERS[path] and row['runtime_matrix_complete']is False,'producer claim')
        source=root/path;require(not source.is_symlink() and source.resolve().is_relative_to(root.resolve()) and source.is_file(),'source path')
        raw=source.read_bytes();require(hashlib.sha256(raw).hexdigest()==row['sha256'],'producer source drift')
        require('->setPrivateMailProducer(__CLASS__)'in raw.decode(),'fixed producer provenance')
    require(type(data['consumers'])is list and len(data['consumers'])==len(CONSUMERS),'consumer count')
    seen=set()
    for row in data['consumers']:
        require(type(row)is dict and set(row)=={'path','sha256'},'consumer shape')
        path=row['path'];require(path in CONSUMERS and path not in seen,'consumer identity');seen.add(path)
        source=root/path;require(not source.is_symlink() and source.resolve().is_relative_to(root.resolve()) and source.is_file(),'consumer path')
        require(hashlib.sha256(source.read_bytes()).hexdigest()==row['sha256'],'consumer source drift')
    require(data['security_flows']==FLOW_STATUS,'security flow scope')
    require(data['unsupported_security']=={'LegalpadDocumentSignController':'unbound-native-signature-refused-full-supported-flow-pending','SMS':'outside-email-policy-scope-no-new-runtime-proof'},'unsupported flow scope')
    annotated={str(p.relative_to(root)) for p in(root/'src').rglob('*.php') if '__tests__'not in p.parts and '->setPrivateMailProducer('in p.read_text()}
    require(annotated==set(PRODUCERS)|{'src/applications/transactions/editor/PhabricatorApplicationTransactionEditor.php'},'unreviewed producer entry')
    return {'passed':True,'scope':'reviewed-private-email-sources-only','producers':len(PRODUCERS),'consumers':len(CONSUMERS),'smtp_proved':False,'all_producers_proved':False,'activation_blocked':True}
def main():
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--receipt',type=Path);parser.add_argument('--file-binary',type=Path);args=parser.parse_args()
    try:
        result=validate(load(ROOT/'docs/governance/private-notification-producers.json'))
        if args.receipt:
            require(args.file_binary is not None and args.file_binary.is_file(),'paired file binary')
            spec=importlib.util.spec_from_file_location('native_notification_gate',ROOT/'scripts/governance/check_native_organization.py');gate=importlib.util.module_from_spec(spec);spec.loader.exec_module(gate)
            accepted=gate.validate(load(args.receipt),file_digest=hashlib.sha256(args.file_binary.read_bytes()).hexdigest())
            result.update(native_envelope_evidence_accepted=True,native_checks=accepted['check_count'])
    except(ValueError,OSError,KeyError,TypeError):
        parser.exit(1,'Private notification evidence rejected.\n')
    print(json.dumps(result,sort_keys=True))
if __name__=='__main__':main()

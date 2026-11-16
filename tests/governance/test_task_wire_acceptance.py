"""Offline actual MIME parsing and native binding collaborators; no SMTP/SQL."""
import importlib.util
from pathlib import Path
import unittest
import subprocess
import json
from email.message import EmailMessage

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('task_wire_acceptance', ROOT / 'support/governance/notification/task-wire-acceptance.py')
wire = importlib.util.module_from_spec(spec)
spec.loader.exec_module(wire)

class TaskWireAcceptance(unittest.TestCase):
    def setUp(self):
        self.args = dict(recipient='synthetic@example.invalid', origin='http://127.0.0.1:12345',
                         task_phid='PHID-TASK-' + 'a' * 20, task_id=42,
                         forbidden=(b'SYNTHETIC_TASK_PRIVATE_TITLE', b'SYNTHETIC_TASK_ATTACHMENT_BYTES', b'SYNTHETIC_TASK_ATTACHMENT_NAME'))
        self.message = EmailMessage()
        self.message['To'] = self.args['recipient']
        self.message['Subject'] = 'Private notification'
        self.message.set_content('Sign in to view this notification.\n\n' + self.args['origin'] + '/object/' + self.args['task_phid'] + '/')

    def validate(self):
        return wire.validate_mime(self.message.as_bytes(), **self.args)

    def test_actual_php_binding_function_with_explicit_storage_collaborators(self):
        code = r"""
require $argv[1];
class WireRecord {
  public $values;
  function __construct($values){$this->values=$values;}
  function __call($name,$args){return $this->values[$name];}
}
$task=new WireRecord(array('getID'=>'42','getPHID'=>'PHID-TASK-'.str_repeat('a',20),'getURI'=>'/T42'));
$user=new WireRecord(array('getPHID'=>'PHID-USER-'.str_repeat('b',20)));
$values=array('getID'=>'8','getRelatedPHID'=>$task->getPHID(),
 'getPrivateMailPolicyValue'=>'ManiphestTransactionEditor','getToPHIDs'=>array($user->getPHID()),
 'getCcPHIDs'=>array(),'getRawToAddresses'=>array(),'getPHID'=>'PHID-MTAM-'.str_repeat('c',20));
$binding=governance_task_wire_binding(new WireRecord($values),$task,$user);
if($binding['mail_id']!==8||$binding['task_path']!=='/T42'){throw new Exception('positive');}
$cc=$values;$cc['getToPHIDs']=array();$cc['getCcPHIDs']=array($user->getPHID());
governance_task_wire_binding(new WireRecord($cc),$task,$user);
$n=2;
foreach(array('getID'=>true,'getRelatedPHID'=>'PHID-TASK-'.str_repeat('d',20),
 'getPrivateMailPolicyValue'=>'PhabricatorFileEditor','getToPHIDs'=>array(),
 'getCcPHIDs'=>array('PHID-USER-'.str_repeat('d',20)),'getRawToAddresses'=>array('other@example.invalid'),
 'getPHID'=>'invalid')as$key=>$value){
 $bad=$values;$bad[$key]=$value;
 try{governance_task_wire_binding(new WireRecord($bad),$task,$user);throw new Exception('accepted');}
 catch(RuntimeException $expected){$n++;}
}
$task->values['getURI']='/T43';
try{governance_task_wire_binding(new WireRecord($values),$task,$user);throw new Exception('accepted');}
catch(RuntimeException $expected){$n++;}
echo json_encode(array('checks'=>$n,'SQL'=>false,'SMTP'=>false));
"""
        result = subprocess.run(['php','-d','disable_functions=mysqli_connect,mysqli_init,stream_socket_client,fsockopen',
                                 '-r',code,str(ROOT/'support/governance/notification/task-wire-producer.php')],
                                check=True,capture_output=True,text=True,timeout=10)
        self.assertEqual('',result.stderr)
        self.assertEqual({'checks':10,'SQL':False,'SMTP':False},json.loads(result.stdout))

    def test_actual_mime_plain_positive_is_not_runtime_attestation(self):
        result = self.validate()
        self.assertFalse(result['SMTP_proved'])
        self.assertFalse(result['browser_proved'])
        self.assertEqual(0, result['attachments'])
        self.assertNotIn(self.args['recipient'], str(result))
        self.assertNotIn(self.args['task_phid'], str(result))

    def test_roster_header_rejected(self):
        for key in ('Cc', 'Bcc'):
            with self.subTest(key=key):
                self.message[key] = 'other@example.invalid'
                with self.assertRaises(ValueError): self.validate()
                del self.message[key]

    def test_native_subscriber_cc_requires_exact_synthetic_void_to(self):
        self.message.replace_header('To','void@example.invalid')
        self.message['Cc']='synthetic@example.invalid'
        result=wire.validate_mime(self.message.as_bytes(), **self.args, void_address='void@example.invalid')
        self.assertEqual(1,result['recipient_count'])
        for address in (None,'other@example.invalid','synthetic@example.invalid'):
            with self.subTest(address=address), self.assertRaises(ValueError):
                wire.validate_mime(self.message.as_bytes(), **self.args, void_address=address)

    def test_multiple_to_and_other_recipient_rejected(self):
        self.message.replace_header('To', 'other@example.invalid')
        with self.assertRaisesRegex(ValueError, 'recipient_split'): self.validate()
        self.message.replace_header('To', 'synthetic@example.invalid, other@example.invalid')
        with self.assertRaisesRegex(ValueError, 'recipient_split'): self.validate()

    def test_attachment_and_html_rejected(self):
        self.message.add_alternative('<p>private</p>', subtype='html')
        with self.assertRaisesRegex(ValueError, 'attachment_or_html'): self.validate()

    def test_exact_object_route_only(self):
        for path in ('/T42', '/object/PHID-TASK-'+'b'*20+'/', '/file/data/capability/private.txt'):
            self.message.set_content('Sign in to view this notification.\n\n'+self.args['origin']+path)
            with self.subTest(path=path), self.assertRaisesRegex(ValueError, 'exact_task_login_route'): self.validate()

    def test_header_canary_rejected(self):
        self.message['X-Synthetic-Leak'] = self.args['forbidden'][0].decode()
        with self.assertRaisesRegex(ValueError, 'private_content_leak'): self.validate()

    def test_identity_headers_are_not_accepted_as_mime_metadata(self):
        for name in ('Authorization','Cookie','Set-Cookie','X-Phabricator-Recipients'):
            self.message[name]='synthetic-opaque-value'
            with self.subTest(name=name), self.assertRaisesRegex(ValueError,'private_header_leak'): self.validate()
            del self.message[name]

    def test_origin_must_be_owned_loopback(self):
        for origin in ('https://example.invalid', 'http://127.0.0.1:123/a', 'http://u:p@127.0.0.1:123'):
            with self.subTest(origin=origin), self.assertRaises(ValueError):
                wire.validate_mime(self.message.as_bytes(), **(self.args | {'origin':origin}))

    def test_allow_binds_exact_delivery_and_one_sink_row(self):
        result = wire.validate_case_counts('allow',before=5,after=6,expected_delivery_id='mail/1',observed_delivery_ids=('mail/1',))
        self.assertFalse(result['SMTP_proved'])
        for ids in (('mail/2',), (), ('mail/1','mail/1')):
            with self.subTest(ids=ids), self.assertRaises(ValueError):
                wire.validate_case_counts('allow',before=5,after=6,expected_delivery_id='mail/1',observed_delivery_ids=ids)

    def test_revoke_rollback_nonrecipient_retry_require_zero_new_mime(self):
        for case in ('retry-after-ack','revoke-before-prepare','revoke-after-prepare','nonrecipient','rollback'):
            with self.subTest(case=case):
                wire.validate_case_counts(case,before=1,after=1,expected_delivery_id='mail/1',observed_delivery_ids=())
                with self.assertRaises(ValueError):
                    wire.validate_case_counts(case,before=1,after=2,expected_delivery_id='mail/1',observed_delivery_ids=('mail/1',))

    def test_counter_coercion_and_unknown_case_refused(self):
        for kwargs in ({'case':'unknown'},{'before':True},{'after':'1'}):
            args=dict(case='allow',before=0,after=1,expected_delivery_id='mail/1',observed_delivery_ids=('mail/1',)) | kwargs
            with self.subTest(kwargs=kwargs), self.assertRaises(ValueError): wire.validate_case_counts(**args)

if __name__ == '__main__': unittest.main()

"""Mutation tests for reviewed sources; never runtime/mail authorization proof."""
import copy,importlib.util,json,shutil,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('private_mail_gate',ROOT/'scripts/governance/check_private_notifications.py')
gate=importlib.util.module_from_spec(spec);spec.loader.exec_module(gate)
class PrivateNotificationEvidenceTests(unittest.TestCase):
    def setUp(self):self.data=gate.load(ROOT/'docs/governance/private-notification-producers.json')
    def test_reviewed_inventory_remains_partial(self):
        result=gate.validate(self.data)
        self.assertTrue(result['passed']);self.assertFalse(result['smtp_proved']);self.assertTrue(result['activation_blocked'])
    def test_scope_and_producer_rows_cannot_be_promoted(self):
        for key in ('production_default_off','activation_blocked','authority_proof','all_producers_runtime_complete'):
            data=copy.deepcopy(self.data);data[key]=not data[key]
            with self.subTest(key=key),self.assertRaises(ValueError):gate.validate(data)
        for mutate in (lambda d:d['producers'].append(d['producers'][0]),
                       lambda d:d['producers'][0].update(runtime_matrix_complete=True),
                       lambda d:d['producers'][0].update(symbol='UnreviewedSecurityException')):
            data=copy.deepcopy(self.data);mutate(data)
            with self.assertRaises(ValueError):gate.validate(data)
    def test_actual_source_changes_and_unreviewed_producers_block(self):
        with tempfile.TemporaryDirectory()as directory:
            root=Path(directory)
            for row in self.data['producers']+self.data['consumers']:
                dest=root/row['path'];dest.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(ROOT/row['path'],dest)
            self.assertTrue(gate.validate(self.data,root)['passed'])
            extra=root/'src/unreviewed.php';extra.write_text("<?php $mail->setPrivateMailProducer('Security');")
            with self.assertRaises(ValueError):gate.validate(self.data,root)
            extra.unlink();source=root/self.data['consumers'][0]['path'];source.write_text(source.read_text()+'\n// native changed\n')
            with self.assertRaises(ValueError):gate.validate(self.data,root)
    def test_partial_security_flow_or_unsupported_signature_cannot_be_promoted(self):
        for flow in gate.FLOW_STATUS:
            data=copy.deepcopy(self.data);data['security_flows'][flow]='smtp-and-browser-complete'
            with self.subTest(flow=flow),self.assertRaises(ValueError):gate.validate(data)
        data=copy.deepcopy(self.data);data['unsupported_security']['LegalpadDocumentSignController']='supported'
        with self.assertRaises(ValueError):gate.validate(data)
        data=copy.deepcopy(self.data);del data['security_flows']['account.login']
        with self.assertRaises(ValueError):gate.validate(data)
    def test_duplicate_json_fields_are_not_last_value_wins(self):
        with tempfile.TemporaryDirectory()as directory:
            path=Path(directory)/'receipt.json';path.write_text('{"passed":false,"passed":true}')
            with self.assertRaises(ValueError):gate.load(path)
if __name__=='__main__':unittest.main()

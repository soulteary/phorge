"""Mutation tests for reviewed sources; never runtime/mail authorization proof."""
import copy,hashlib,importlib.util,json,shutil,subprocess,sys,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('private_mail_gate',ROOT/'scripts/governance/check_private_notifications.py')
gate=importlib.util.module_from_spec(spec);spec.loader.exec_module(gate)
class PrivateNotificationEvidenceTests(unittest.TestCase):
    def setUp(self):self.data=gate.load(ROOT/'docs/governance/private-notification-producers.json')
    def test_notice_schema_uses_variable_binary_not_native_fixed_binary(self):
        spec=(ROOT/'src/infrastructure/governance/notification/PhorgeGovernanceNoticeSchemaSpec.php').read_text()
        ddl=(ROOT/'resources/sql/autopatches/20261104.metamta.governancenotice.sql').read_text()
        def matches(source):
            return source.count("=>'varbytes64'")==6 and "=>'bytes64'" not in source
        self.assertTrue(matches(spec))
        self.assertEqual(5,ddl.count('VARBINARY(64) NOT NULL')-1)  # Exclude native recipient PHID.
        self.assertIn("'mailPHID'=>'phid?'",spec)
        self.assertIn('mailPHID VARBINARY(64) DEFAULT NULL',ddl)
        for field in ('eventID','operationDigest','actorDigest','metadataDigest','userPHID'):
            old=spec.replace("'"+field+"'=>'varbytes64'","'"+field+"'=>'bytes64'",1)
            self.assertFalse(matches(old))
    def test_notice_failure_projection_never_exports_raw_query_or_reason(self):
        source=ROOT/'support/governance/notification/governance-notice-cases.php'
        code=r"""require $argv[1];$secret='SYNTHETIC_PRIVATE_DIAGNOSTIC_SENTINEL';
        $pattern=governance_notice_insert_wait_pattern();
        $queries=array('INSERT INTO owned.governance_notice_event VALUES (1)',
          'SELECT id INTO gate_id FROM owned.notice_fixture_gate WHERE id=1 FOR UPDATE',
          'SELECT id FROM owned.other_gate FOR UPDATE','INSERT INTO owned.other_event VALUES (1)',
          'SELECT id FROM owned.notice_fixture_gate_extra FOR UPDATE','SELECT * FROM governance_notice_event');
        $matches=array_map(static function($q)use($pattern){return preg_match($pattern,$q)===1;},$queries);
        if($matches!==array(true,true,false,false,false,false)){exit(2);}
        $rows=array(governance_notice_wait_projection('SELECT id INTO gate_id FROM notice_fixture_gate WHERE '.$secret,null),
          governance_notice_wait_projection(false,array('status'=>'rejected','reason'=>$secret)),
          governance_notice_wait_projection(null,array('status'=>'failed','error_type'=>'RuntimeException','source_file'=>'fixture.php','source_line'=>44,'message'=>$secret)),
          governance_notice_wait_projection('INSERT INTO qualified.governance_notice_event VALUES ('.$secret.')',array('status'=>'committed')));
        echo json_encode($rows,JSON_THROW_ON_ERROR);"""
        result=subprocess.run(['php','-r',code,str(source)],capture_output=True,text=True)
        self.assertEqual(0,result.returncode);self.assertEqual('',result.stderr)
        self.assertNotIn('SYNTHETIC_PRIVATE_DIAGNOSTIC_SENTINEL',result.stdout)
        rows=json.loads(result.stdout)
        self.assertEqual(['gate_select','missing','idle','notice_insert'],[r['query_kind']for r in rows])
        self.assertEqual('other_fixed_refusal',rows[1]['rejection_kind'])
        self.assertEqual(44,rows[2]['source_line']);self.assertNotIn('message',rows[2])
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
    def test_native_envelope_requires_external_measurement_revision(self):
        import os
        def source_window():
            rows={}
            for parent,dirs,names in os.walk(ROOT,followlinks=False):
                dirs[:]=[name for name in dirs if name!='.git']
                for name in list(dirs):
                    path=Path(parent)/name
                    if path.is_symlink():dirs.remove(name);names.append(name)
                for name in names:
                    path=Path(parent)/name;relative=path.relative_to(ROOT).as_posix()
                    if relative=='.git':continue
                    rows[relative]=(path.lstat().st_mode,hashlib.sha256(os.readlink(path).encode()if path.is_symlink()else path.read_bytes()).hexdigest())
            return rows
        before=source_window()
        spec=importlib.util.spec_from_file_location('native_notification_template',ROOT/'tests/governance/test_native_organization.py')
        module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
        with tempfile.TemporaryDirectory()as directory:
            private=Path(directory);binary=private/'file';binary.write_bytes(b'synthetic gate binary')
            render=private/'render';render.write_bytes(b'synthetic render gate input')
            data=module.template();data['native']['private_mail_tests']['wiki_content_publication_tests']['render_cleanup']['binary_sha256']=hashlib.sha256(render.read_bytes()).hexdigest()
            data['file_service_cleanup']['binary_sha256']=hashlib.sha256(binary.read_bytes()).hexdigest()
            receipt=private/'receipt.json';receipt.write_text(json.dumps(data))
            base=[sys.executable,'-B',str(ROOT/'scripts/governance/check_private_notifications.py'),
                  '--receipt',str(receipt),'--file-binary',str(binary),'--render-binary',str(render)]
            # Probe the same interpreter/flag prefix used by the real CLI below.
            prefix=base[:base.index(str(ROOT/'scripts/governance/check_private_notifications.py'))]
            flag_probe=subprocess.run(prefix+['-c','import sys; raise SystemExit(0 if sys.dont_write_bytecode else 1)'],capture_output=True,text=True)
            self.assertEqual(flag_probe.returncode,0,'native evidence CLI must not write source bytecode')
            for expected,extra in [(0,['--measurement-source-revision','a'*40]),
                                   (1,[]),(1,['--measurement-source-revision','b'*40])]:
                result=subprocess.run(base+extra,capture_output=True,text=True)
                self.assertEqual(expected,result.returncode)
                self.assertEqual(source_window(),before,'CLI must preserve all source names, modes and hashes')
                if expected==0:
                    value=json.loads(result.stdout);self.assertEqual(934,value['native_checks'])
                    self.assertIs(value['smtp_proved'],False)
            index=base.index('--render-binary');without=base[:index]+base[index+2:]
            missing=subprocess.run(without+['--measurement-source-revision','a'*40],capture_output=True,text=True)
            self.assertNotEqual(0,missing.returncode);self.assertEqual('',missing.stdout)

    def test_duplicate_json_fields_are_not_last_value_wins(self):
        with tempfile.TemporaryDirectory()as directory:
            path=Path(directory)/'receipt.json';path.write_text('{"passed":false,"passed":true}')
            with self.assertRaises(ValueError):gate.load(path)
if __name__=='__main__':unittest.main()

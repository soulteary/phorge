"""Actual PHP decoder tests only; not native authentication or SQL evidence."""
import json
import importlib.util
import copy
import tempfile
import hashlib
import sys
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]
WORKERS = ROOT / 'support/governance/native-organization/workers.php'

class CredentialConcurrencyDecoderTests(unittest.TestCase):
    def validate(self, action, value):
        script = '''require $argv[1]; $x=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
try { $r=($argv[2]==='api')?governance_native_api_concurrency_result($x):governance_native_oauth_concurrency_result($x);
echo json_encode(array('accepted'=>true,'value'=>$r),JSON_THROW_ON_ERROR); }
catch(RuntimeException $e) { echo json_encode(array('accepted'=>false),JSON_THROW_ON_ERROR); }'''
        result = subprocess.run(['php', '-r', script, str(WORKERS), action], input=json.dumps(value),
                                text=True, capture_output=True, check=True)
        self.assertEqual(result.stderr, '')
        return json.loads(result.stdout)['accepted']

    def test_exact_current_source_allowed_and_denied(self):
        self.assertTrue(self.validate('api', {'status':'committed','current_token_verified':True,'restoration_authorized':False}))
        self.assertTrue(self.validate('api', {'status':'rejected','reason':'governance_native_credential_ineligible'}))
        for value in [False, 1, '1', None]:
            self.assertFalse(self.validate('api', {'status':'committed','current_token_verified':value,'restoration_authorized':False}))

    def test_failed_unknown_and_backend_are_never_denial(self):
        for action in ['api', 'oauth']:
            for value in [{'status':'failed','error_type':'AphrontDeadlockQueryException'},
                          {'status':'rejected','reason':'governance_unknown_backend'},
                          {'status':'committed','outcome':'unknown','authentication_code':'unknown','restoration_authorized':False}]:
                self.assertFalse(self.validate(action, value))

    def test_oauth_correlated_native_outcome_and_no_authority(self):
        for outcome, code in [('allowed','native-success'),('denied','ERR-INVALID-AUTH')]:
            good={'status':'committed','outcome':outcome,'authentication_code':code,'restoration_authorized':False}
            self.assertTrue(self.validate('oauth', good))
            for bad in [dict(good,restoration_authorized=True), dict(good,extra='private'),
                        dict(good,authentication_code='ERR-OAUTH-ACCESS')]:
                self.assertFalse(self.validate('oauth', bad))
        self.assertFalse(self.validate('oauth', {'status':'committed','outcome':'allowed','authentication_code':'ERR-INVALID-AUTH','restoration_authorized':False}))

class CredentialConcurrencyGateTests(unittest.TestCase):
    def setUp(self):
        def load(name, path):
            spec=importlib.util.spec_from_file_location(name,path)
            module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
            return module
        self.gate=load('api_concurrency_gate',ROOT/'scripts/governance/check_api_credential_concurrency.py')
        source=load('synthetic_native_template',ROOT/'tests/governance/test_native_organization.py')
        self.data=source.template()  # Synthetic gate input, never SQL evidence.
        self.data.update(batch='native-api-credential-concurrency',scope='owned-native-credential-writer-reader-observation',
                         checks=sorted(self.gate.required_checks()),check_count=46)
        self.data['worker_cleanup'].update(workers_registered=12,private_directories_removed=12)
        rows=[]
        for p in self.gate.PROFILES:
            for op in self.gate.OPERATIONS:
                for b in self.gate.BOUNDARIES:
                    allowed=not(op=='revoke' and b=='commit')
                    rows.append(dict(profile=p,operation=op,boundary=b,
                      observation=dict(state='completed-before-release',exact_requester_and_blocker=False),
                      reader_allowed=allowed,fresh_auth_allowed=allowed))
        self.data['native']=dict(profile_version=1,source_revision='a'*40,observations=rows,attempts=12,
          actual_current_reader_profiles=['api','cli'],oauth_native_authentication_only=True,
          api_token_scan_deadlock_proven=False,production_query_changed=False,all_sources_complete=False,can_execute=False)

    def validate(self, data):
        return self.gate.validate(data,'a'*40,self.data['file_service_cleanup']['binary_sha256'])

    def test_positive_contract_before_mutations(self):
        self.assertTrue(self.validate(self.data)['accepted'])
        self.assertEqual(len(self.gate.required_checks()),46)

    def test_actual_cli_duplicate_fields_rejected_after_positive(self):
        with tempfile.TemporaryDirectory() as directory:
            binary=Path(directory)/'synthetic-binary'
            binary.write_bytes(b'contract-only, not native binary evidence')
            self.data['file_service_cleanup']['binary_sha256']=hashlib.sha256(binary.read_bytes()).hexdigest()
            receipt=Path(directory)/'synthetic.json'
            raw=json.dumps(self.data)
            command=[sys.executable,'-B',str(ROOT/'scripts/governance/check_api_credential_concurrency.py'),
                     '--receipt',str(receipt),'--source-revision','a'*40,'--file-binary',str(binary)]
            receipt.write_text(raw)
            positive=subprocess.run(command,capture_output=True,text=True)
            self.assertEqual(positive.returncode,0,positive.stderr)
            for before, after in [
                ('"source_revision": "'+'a'*40+'"','"source_revision": "'+'b'*40+'", "source_revision": "'+'a'*40+'"'),
                ('"can_execute": false','"can_execute": true, "can_execute": false')]:
                self.assertEqual(raw.count(before),1)
                receipt.write_text(raw.replace(before,after))
                refused=subprocess.run(command,capture_output=True,text=True)
                self.assertNotEqual(refused.returncode,0)
                self.assertIn('duplicate credential evidence field',refused.stderr)

    def test_missing_duplicate_wrong_source_and_authority_refused(self):
        self.validate(self.data)
        cases=[]
        d=copy.deepcopy(self.data);d['checks'].pop();cases.append(d)
        d=copy.deepcopy(self.data);d['native']['observations'][-1]=copy.deepcopy(d['native']['observations'][0]);cases.append(d)
        d=copy.deepcopy(self.data);d['native']['source_revision']='b'*40;cases.append(d)
        d=copy.deepcopy(self.data);d['native']['can_execute']=True;cases.append(d)
        d=copy.deepcopy(self.data);d['native']['observations'][0]['observation']['exact_requester_and_blocker']=0;cases.append(d)
        d=copy.deepcopy(self.data);d['cleanup']['readerRemoved']=1;cases.append(d)
        d=copy.deepcopy(self.data);d['native']['observations'][0]['observation']['state']='unknown';cases.append(d)
        for d in cases:
            with self.assertRaises(ValueError):self.validate(d)

if __name__ == '__main__':
    unittest.main()

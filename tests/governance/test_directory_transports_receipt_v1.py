"""Synthetic consumer mutation tests, never SQL/runtime measurement."""
import copy,hashlib,importlib.util,json,os,subprocess,sys,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('directory_v1',ROOT/'scripts/governance/check_directory_transports_v1.py');gate=importlib.util.module_from_spec(spec);spec.loader.exec_module(gate)
spec=importlib.util.spec_from_file_location('directory_restore_v1',ROOT/'scripts/governance/check_native_organization.py');old=importlib.util.module_from_spec(spec);spec.loader.exec_module(old)
def template():
    return dict(batch='native-directory-transports-v1',passed=True,scope='owned-native-directory-transport-matrix',
        checks=sorted(gate.expected_checks()),check_count=len(gate.expected_checks()),php_version='8.5.12',
        source_restore=dict(quickstart_sha256=hashlib.sha256((ROOT/'resources/sql/quickstart.sql').read_bytes()).hexdigest(),
            app_schema_count=54,owned_schema_count=55,quickstart_source_table_count=500,current_table_count=546,
            restore_phase_workers_started=0,restore_phase_network_endpoints_configured=0,
            patch_audit=dict(source_patch_count=1574,applied_patch_count=1574,pending_patch_keys=[],current_patch_ledger_complete=True,
                migration_source_hashes={'phabricator:'+p:hashlib.sha256((ROOT/'resources/sql/autopatches'/p).read_bytes()).hexdigest() for p in old.completion.PATCHES},migrations_executed=len(old.completion.PATCHES))),
        native=dict(schema='native-directory-transports-v1',read_samples=gate.expected_samples(),
            negative_actions=[dict(action=a,web_status=403,conduit_generic_error=True,business_unchanged=True) for a in gate.ACTIONS],
            ordinary_adds=[dict(transport=t,member_present=True,appointment_version=0) for t in ['web','conduit']],
            actual_native_ConduitCall=True,actual_native_token_authentication=True,actual_native_Web_controller=True,
            fake_query_or_actor=False,HTTP_server_or_browser=False,production_authority=False,native_permission_axes_changed=False),
        optional_source_reader_adapter_exercised=True,isolated_optional_features_enabled=True,worker_cleanup=None,file_service_cleanup=None,
        successful_owned_file_service_startups=0,cleanup=dict(ownedSchemasRemoved=55,ownedRuntimeUsersRemoved=2,readerRemoved=True),
        failure=None,cleanup_failures=[],terminal_failure_cleanup_rollbacks=0,captured_native_output_bytes=0,
        captured_native_output_sha256=hashlib.sha256(b'').hexdigest(),production_deployment=False,production_executor=False,
        production_optional_features_enabled=False,native_permission_axes_changed=False,
        remaining_blocked=['media-http-and-cookie-integration','file-object-guards','notification-producers','global-governance-completion'])
class ReceiptTest(unittest.TestCase):
    def test_synthetic_schema_is_not_bound_SQL_or_global_authority(self):
        d=gate.validate(template());self.assertFalse(d['source_bound_by_CLI']);self.assertFalse(d['runtime_authority']);self.assertFalse(d['full_N01_N04_complete']);self.assertEqual(d['read_samples'],45)
    def test_every_actual_check_is_mandatory(self):
        for check in gate.expected_checks():
            d=template();d['checks'].remove(check);d['check_count']-=1
            with self.subTest(check=check),self.assertRaises(ValueError):gate.validate(d)
    def test_each_row_negative_and_ordinary_add_cannot_be_omitted(self):
        for key in ['read_samples','negative_actions','ordinary_adds']:
            for n in range(len(template()['native'][key])):
                d=template();d['native'][key].pop(n)
                with self.subTest(key=key,index=n),self.assertRaises(ValueError):gate.validate(d)
    def test_hidden_reference_status_count_and_identity_changes_refuse(self):
        for change in [dict(status=200),dict(row_count=1),dict(unit_ids=['dt-child']),dict(actor='admin'),dict(same_projection=1)]:
            d=template();sample=next(x for x in d['native']['read_samples'] if x['status']==403);sample.update(change)
            with self.subTest(change=change),self.assertRaises(ValueError):gate.validate(d)
        d=template();next(x for x in d['native']['read_samples'] if x['actor']=='child' and x['mode']=='list')['parent_ids']=['dt-parent']
        with self.assertRaises(ValueError):gate.validate(d)
    def test_cleanup_schema_source_or_version_failure_never_passes(self):
        for key,value in [('passed',1),('scope','owned-full-native-source-synthetic-accounts'),('php_version','8.5.8'),('cleanup_failures',[{}]),('production_executor',True),('worker_cleanup',{}),('captured_native_output_bytes',1)]:
            d=template();d[key]=value
            with self.subTest(key=key),self.assertRaises(ValueError):gate.validate(d)
        for key,value in [('ownedSchemasRemoved',54),('readerRemoved',1),('ownedRuntimeUsersRemoved',1)]:
            d=template();d['cleanup'][key]=value
            with self.subTest(key=key),self.assertRaises(ValueError):gate.validate(d)
        d=template();d['source_restore']['patch_audit']['migration_source_hashes'].pop(next(iter(d['source_restore']['patch_audit']['migration_source_hashes'])))
        with self.assertRaises(ValueError):gate.validate(d)
    def test_bounded_json_rejects_duplicate_raw_and_unknown_fields(self):
        with tempfile.TemporaryDirectory()as directory:
            p=Path(directory)/'r.json';p.write_text('{"passed":true,"passed":false}')
            with self.assertRaises(ValueError):gate.read_json(p)
            p.write_text(json.dumps(dict(template(),secret='PRIVATE_CANARY')))
            with self.assertRaises(ValueError):gate.validate(gate.read_json(p))
            p.write_bytes(b'{}TRAILING')
            with self.assertRaises(ValueError):gate.read_json(p)
    def test_actual_special_alias_and_oversize_receipts_refuse(self):
        with tempfile.TemporaryDirectory()as directory:
            root=Path(directory);p=root/'fifo';os.mkfifo(p)
            with self.assertRaises(ValueError):gate.read_json(p)
            p.unlink();p.write_text('{}');alias=root/'alias';alias.symlink_to(p)
            with self.assertRaises(OSError):gate.read_json(alias)
            alias.unlink();os.link(p,alias)
            with self.assertRaises(ValueError):gate.read_json(p)
            alias.unlink()
            with p.open('wb')as f:f.truncate(8*1024*1024+1)
            with self.assertRaises(ValueError):gate.read_json(p)
    def test_real_source_inventory_pins_match_and_tampered_payload_refuses(self):
        manifest=gate.source(ROOT)
        with tempfile.TemporaryDirectory()as directory:
            temp=Path(directory);(temp/gate.MANIFEST).parent.mkdir(parents=True);(temp/gate.MANIFEST).write_text(json.dumps(manifest))
            first=next(iter(manifest['pins']));f=temp/first;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(b'TAMPER')
            with self.assertRaises(ValueError):gate.source(temp)
    def test_invalid_CLI_input_cannot_claim_source_binding_or_echo_secret(self):
        with tempfile.TemporaryDirectory()as directory:
            p=Path(directory)/'r.json';p.write_text(json.dumps(dict(template(),secret='PRIVATE_CANARY')))
            revision=subprocess.check_output(['git','-C',str(ROOT),'rev-parse','HEAD']).decode().strip()
            # Current local candidate is intentionally not committed. In CI this
            # uses committed Git objects; invalid input always remains refused.
            r=subprocess.run([sys.executable,'-B',str(ROOT/'scripts/governance/check_directory_transports_v1.py'),'--receipt',str(p),'--source-revision',revision],capture_output=True,text=True,timeout=10)
            self.assertEqual(r.returncode,1);self.assertNotIn('PRIVATE_CANARY',r.stdout+r.stderr);self.assertFalse(json.loads(r.stdout)['accepted'])
if __name__=='__main__':unittest.main()

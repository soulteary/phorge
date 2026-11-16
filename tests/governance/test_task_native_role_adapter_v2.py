"""Actual original producer adaptation controls; not signed native execution."""
import hashlib,importlib.util,json,os,subprocess,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
REFERENCE=Path(os.environ.get('TASK_ADOPTER_TEST_REFERENCE_ROOT',str(ROOT))).resolve()
FOLDER=ROOT/'support/governance/task-additive-schema-v2'
spec=importlib.util.spec_from_file_location('task_role_adapter_controls',FOLDER/'producer_adapter.py');adapter=importlib.util.module_from_spec(spec);spec.loader.exec_module(adapter)
class NativeRoleAdapterControls(unittest.TestCase):
 def test_three_actual_producers_single_before_after_and_unbound_no_bootstrap(self):
  manifest=(FOLDER/'producer-roles-v2.json').read_bytes();prefix=(FOLDER/'producer-prefix.php').read_bytes()
  with tempfile.TemporaryDirectory()as tmp:
   for role in ('directory','oauth-authorization','oauth-client-lifecycle','conduit','oauth-access','oauth-code','assessment-credential','api-credential-concurrency','application-config-facts','project-source-facts','spaces-source-facts','recovery-partial-composition','task-owner-source-facts','file-source-facts','file-parent-policy','session-producer-lease','session-borrowed-owner','organization-approval-workbench','full-native'):
    selected=adapter.role_manifest(manifest,role);original=(REFERENCE/selected['source']).read_bytes();adapted=adapter.adapt(original,prefix,selected)
    self.assertEqual(adapted.count(adapter.MARKER),1);self.assertEqual(adapted.count(b"::capture($fixture,$task_binding['profile'],'before')"),1);self.assertEqual(adapted.count(b"::capture($fixture,$task_binding['profile'],'after')"),1)
    self.assertEqual(original.count(selected['native_call_anchor'].encode()),1);self.assertEqual(adapted.count(selected['native_call_anchor'].encode()),1)
    self.assertLess(adapted.index(b'Task_native_role_argument_refused'),adapted.index(b"require_once dirname($native_directory,2)"))
    self.assertLess(adapted.index(b"require_once $root.'/support/runtime/bootstrap-cli.php';"),adapted.index(b'$task_outer_level=ob_get_level();ob_start();'))
    self.assertLess(adapted.index(b'$task_outer_level=ob_get_level();ob_start();'),adapted.index(b'  ob_start();\n  $capture_active = true;'))
    file=Path(tmp)/(role+'.php');file.write_bytes(adapted)
    lint=subprocess.run(['php','-l',str(file)],capture_output=True,text=True);self.assertEqual(lint.returncode,0,lint.stderr)
    run=subprocess.run(['php',str(file),*([]if role=='full-native'else[selected['argument']])],capture_output=True,text=True);self.assertEqual(run.returncode,1);self.assertEqual(run.stdout,'');self.assertEqual(run.stderr,'Task_directory_v2_unbound_before_SQL\n')
 def test_original_nine_rows_are_byte_semantically_unchanged(self):
  value=json.loads((FOLDER/'producer-roles-v2.json').read_bytes())
  for role in ('application-config-facts','project-source-facts','spaces-source-facts','recovery-partial-composition','task-owner-source-facts','file-source-facts','file-parent-policy','session-producer-lease','session-borrowed-owner','organization-approval-workbench'):del value['roles'][role]
  raw=(json.dumps(value,sort_keys=True,separators=(',',':'))+'\n').encode()
  self.assertEqual(hashlib.sha256(raw).hexdigest(),'2dac1dca2cc47b35f2a3cce792f1977253c047dd814fb4ecd6fcfb005aa8f129')
 def test_original_twelve_rows_are_byte_semantically_unchanged(self):
  value=json.loads((FOLDER/'producer-roles-v2.json').read_bytes())
  for role in ('recovery-partial-composition','task-owner-source-facts','file-source-facts','file-parent-policy','session-producer-lease','session-borrowed-owner','organization-approval-workbench'):del value['roles'][role]
  raw=(json.dumps(value,sort_keys=True,separators=(',',':'))+'\n').encode()
  self.assertEqual(hashlib.sha256(raw).hexdigest(),'5a2e8905ee3ddd1d288428d53d28dd2623bb7512ecf4b176b1b2177b18ca3f18')
 def test_original_fifteen_rows_are_byte_semantically_unchanged(self):
  value=json.loads((FOLDER/'producer-roles-v2.json').read_bytes())
  for role in ('file-parent-policy', 'session-producer-lease', 'session-borrowed-owner','organization-approval-workbench'):del value['roles'][role]
  raw=(json.dumps(value,sort_keys=True,separators=(',',':'))+'\n').encode()
  self.assertEqual(hashlib.sha256(raw).hexdigest(),'420cb6eb99c42f94c2b57ebb20f1a99617e3ab73bc3c404d112ef84f8ddf301f')
 def test_original_eighteen_rows_are_byte_semantically_unchanged(self):
  value=json.loads((FOLDER/'producer-roles-v2.json').read_bytes())
  del value['roles']['organization-approval-workbench']
  raw=(json.dumps(value,sort_keys=True,separators=(',',':'))+'\n').encode()
  self.assertEqual(hashlib.sha256(raw).hexdigest(),'9bf0ef7d7bbe981b1a72a35a33d7ebf4d789fc0f6823dcf9e6b0f9649723f826')
 def test_unknown_role_or_changed_original_is_rejected(self):
  manifest=(FOLDER/'producer-roles-v2.json').read_bytes();prefix=(FOLDER/'producer-prefix.php').read_bytes()
  with self.assertRaises(ValueError):adapter.role_manifest(manifest,'unreviewed')
  selected=adapter.role_manifest(manifest,'directory');original=(REFERENCE/selected['source']).read_bytes()
  for raw in (original+b'\n',original.replace(selected['native_call_anchor'].encode(),b'',1)):
   with self.assertRaises(ValueError):adapter.adapt(raw,prefix,selected)
 def test_prefix_marker_and_role_set_changes_rejected(self):
  value=json.loads((FOLDER/'producer-roles-v2.json').read_bytes());selected=value['roles']['directory'];original=(REFERENCE/selected['source']).read_bytes();prefix=(FOLDER/'producer-prefix.php').read_bytes()
  for modified in (prefix+adapter.MARKER,prefix.replace(adapter.MARKER,b'')):
   with self.assertRaises(ValueError):adapter.adapt(original,modified,selected)
  value['roles']['unreviewed']=selected
  with self.assertRaises(ValueError):adapter.role_manifest(json.dumps(value).encode(),'directory')
if __name__=='__main__':unittest.main()

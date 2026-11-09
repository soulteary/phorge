"""Own-memory C output and separate strict public-caller hints, not actual CI."""
import copy,importlib.util,json,subprocess,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
HELPER='support/governance/native-organization/directory-output-origin.php';FIXTURE='support/governance/native-organization/directory-output-origin-fixture.php'
s=importlib.util.spec_from_file_location('hint_projector',ROOT/'scripts/governance/project_directory_transport_failure_v1.py');p=importlib.util.module_from_spec(s);s.loader.exec_module(p)
def hint():return dict(schema='native-directory-output-hints-v1',evidence_kind='public-stack-hints-not-emitting-origin',first_frame_scope='no-file',first_chunk_kind='unclassified',hints=[])
def wire(d):return p.HINT_PREFIX+json.dumps(d).encode()+b'\n'
class OutputHintsTest(unittest.TestCase):
 def php(self,body,extra=None):
  setup="require$argv[1].'/"+HELPER+"';require$argv[1].'/"+FIXTURE+"';ob_start();$state=governance_directory_output_origin_start($argv[1]);"
  end="governance_directory_output_origin_finish($state);$bytes=ob_get_clean();echo json_encode(array('bytes_b64'=>base64_encode($bytes),'origin'=>$state->origin,'depth'=>ob_get_level()));governance_directory_output_origin_emit($state);"
  c=subprocess.run(['php','-d','display_errors=1','-d','html_errors=0','-d','log_errors=0','-r',setup+body+end,str(ROOT),*([]if extra is None else[extra])],capture_output=True,timeout=10);self.assertEqual(c.returncode,0,c.stderr);return json.loads(c.stdout),[json.loads(x.split(b' ',1)[1])for x in c.stderr.splitlines()],c.stderr
 def test_actual_C_warning_missing_frame0_keeps_unknown_origin_and_public_caller_hint(self):
  data,rows,raw=self.php("governance_directory_output_origin_test_C_warning('PRIVATE_ERROR_VALUE_CANARY');")
  self.assertEqual(data['origin'],dict(source='unknown-source',line=0));self.assertEqual(rows[0]['source'],'unknown-source');h=rows[1]
  self.assertEqual(h['first_frame_scope'],'no-file');self.assertEqual(h['first_chunk_kind'],'Warning');self.assertEqual(h['evidence_kind'],'public-stack-hints-not-emitting-origin');self.assertLessEqual(len(h['hints']),8);self.assertGreater(len(h['hints']),0)
  actual_line=next(i for i,x in enumerate((ROOT/FIXTURE).read_text().splitlines(),1)if 'trigger_error($private_argument,'in x)
  self.assertIn(dict(frame=1,source=FIXTURE,line=actual_line),h['hints']);self.assertNotIn(b'PRIVATE_',raw);self.assertEqual(data['depth'],0)
 def test_actual_private_C_warning_does_not_export_private_path_args_or_outer_caller_as_origin(self):
  with tempfile.TemporaryDirectory(prefix='PRIVATE_PATH_CANARY-')as td:
   f=Path(td)/'private.php';f.write_text("<?php function private_warning($secret){trigger_error($secret,E_USER_WARNING);}")
   data,rows,raw=self.php("require$argv[2];private_warning('PRIVATE_ARGUMENT_CANARY');",str(f));self.assertEqual(data['origin'],dict(source='unknown-source',line=0));self.assertEqual(rows[1]['hints'],[]);self.assertNotIn(b'PRIVATE_',raw);self.assertNotIn(td.encode(),raw)
 def test_actual_C_PNG_marker_is_only_content_classification(self):
  import base64
  data,rows,raw=self.php("governance_directory_output_origin_test_C_png();")
  self.assertTrue(base64.b64decode(data['bytes_b64']).startswith(b'\x89PNG\r\n\x1a\n'));self.assertEqual(rows[1]['first_chunk_kind'],'PNGsignature');self.assertEqual(rows[1]['evidence_kind'],'public-stack-hints-not-emitting-origin')
 def test_fixed_marker_only_classes_do_not_export_partial_text(self):
  cases=[(' \n\t','whitespace'),('Deprecated: PRIVATE_DEPRECATION','Deprecated'),('Notice: PRIVATE_NOTICE','Notice'),('Warning: PRIVATE_WARNING','Warning'),('PRIVATE_UNCLASSIFIED','unclassified')]
  for raw,kind in cases:
   body='governance_directory_output_origin_test_write(base64_decode('+json.dumps(__import__('base64').b64encode(raw.encode()).decode())+'));'
   _,rows,stderr=self.php(body);self.assertEqual(rows[1]['first_chunk_kind'],kind);self.assertNotIn(b'PRIVATE_',stderr)
 def test_strict_hints_reject_extra_malformed_duplicate_too_many_bool_positions_and_private_sources(self):
  good=hint();self.assertEqual(p.output_hints(wire(good)),good)
  invalid=[{**good,'message':'PRIVATE_MESSAGE'},{**good,'first_chunk_kind':'PRIVATE_KIND'},{**good,'first_frame_scope':'PRIVATE_SCOPE'},{**good,'hints':[dict(frame=True,source=HELPER,line=1)]},{**good,'hints':[dict(frame=1,source='/PRIVATE/path.php',line=1)]},{**good,'hints':[dict(frame=1,source=HELPER,line=True)]},{**good,'hints':[dict(frame=i,source=HELPER,line=1)for i in range(9)]},{**good,'hints':[dict(frame=1,source=HELPER,line=1),dict(frame=1,source=HELPER,line=1)]}]
  for d in invalid:
   with self.subTest(d=d),self.assertRaises(ValueError):p.output_hints(wire(d))
  for raw in [p.HINT_PREFIX+b'not-json',wire(good)+wire(good),p.HINT_PREFIX+b'x'*8193]:
   with self.assertRaises(ValueError):p.output_hints(raw)
 def test_hints_never_upgrade_unknown_origin_or_accept_without_original_marker(self):
  with tempfile.TemporaryDirectory()as td:
   missing=Path(td)/'none';h=hint();raw=wire(h)
   with self.assertRaises(ValueError):p.project_terminal(raw,'native-stderr',missing,missing)
   origin=dict(schema='native-directory-failure-marker-v1',kind='primary',**{'class':'CapturedNativeOutput'},source='unknown-source',line=0,stage='captured-native-output-write')
   data=p.project_terminal(p.PREFIX+json.dumps(origin).encode()+b'\n'+raw,'native-stderr',missing,missing)
   self.assertEqual(data['failures'][0]['source'],'unknown-source');self.assertFalse(data['selected_matrix_accepted']);self.assertEqual(data['output_hints']['hints'],[])
if __name__=='__main__':unittest.main()

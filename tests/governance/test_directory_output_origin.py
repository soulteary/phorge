"""Actual own-memory PHP output/inner GD buffer; no SQL/File/network service."""
import importlib.util,json,subprocess,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
HELPER='support/governance/native-organization/directory-output-origin.php'
FIXTURE='support/governance/native-organization/directory-output-origin-fixture.php'
s=importlib.util.spec_from_file_location('origin_projector',ROOT/'scripts/governance/project_directory_transport_failure_v1.py');projector=importlib.util.module_from_spec(s);s.loader.exec_module(projector)
class OutputOriginTest(unittest.TestCase):
 def run_php(self,body,extra=None):
  setup="require$argv[1].'/"+HELPER+"';require$argv[1].'/"+FIXTURE+"';$initial=ob_get_level();ob_start();$state=governance_directory_output_origin_start($argv[1]);"
  end="governance_directory_output_origin_finish($state);$bytes=ob_get_clean();echo json_encode(array('bytes_b64'=>base64_encode($bytes),'sha256'=>hash('sha256',$bytes),'origin'=>$state->origin,'observer_ended'=>$state->ended,'depth_restored'=>ob_get_level()===$initial));if(strlen($bytes)>0){governance_directory_output_origin_emit($state);}"
  p=subprocess.run(['php','-r',setup+body+end,str(ROOT),*([]if extra is None else[extra])],capture_output=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);return json.loads(p.stdout),p.stderr
 def test_actual_write_has_real_public_source_line_and_no_args_in_marker(self):
  d,stderr=self.run_php("governance_directory_output_origin_test_write('SECRET_ARGUMENT_CANARY');")
  line=next(i for i,x in enumerate((ROOT/FIXTURE).read_text().splitlines(),1)if 'echo $private_argument;'in x)
  self.assertEqual(d['origin'],dict(source=FIXTURE,line=line));self.assertTrue(d['observer_ended']);self.assertTrue(d['depth_restored']);self.assertNotIn(b'SECRET_ARGUMENT_CANARY',stderr)
  marker=json.loads(stderr.splitlines()[0].split(b' ',1)[1]);self.assertEqual(set(marker),{'schema','kind','class','source','line','stage'});self.assertEqual(marker['class'],'CapturedNativeOutput')
 def test_exact_original_bytes_and_hash_keep_binary_NUL_newline_and_all_writes(self):
  import base64,hashlib
  expected=b'A\0B\nC'
  d,_=self.run_php("governance_directory_output_origin_test_write(\"A\\0B\\n\");governance_directory_output_origin_test_write('C');")
  self.assertEqual(base64.b64decode(d['bytes_b64']),expected);self.assertEqual(d['sha256'],hashlib.sha256(expected).hexdigest());self.assertTrue(d['depth_restored'])
 def test_actual_inner_GD_PNG_buffer_is_returned_not_misreported_as_output(self):
  d,stderr=self.run_php("$png=governance_directory_output_origin_test_inner_image();if(substr($png,0,8)!==\"\\x89PNG\\r\\n\\x1a\\n\"){throw new RuntimeException('bad test PNG');}")
  self.assertEqual(d['bytes_b64'],'');self.assertIsNone(d['origin']);self.assertEqual(stderr,b'');self.assertTrue(d['observer_ended']);self.assertTrue(d['depth_restored'])
 def test_private_path_and_argument_do_not_become_public_origin(self):
  with tempfile.TemporaryDirectory(prefix='PRIVATE_PATH_CANARY-')as td:
   private=Path(td)/'PRIVATE_SOURCE_CANARY.php';private.write_text("<?php function private_origin_control($secret){echo$secret;}")
   d,stderr=self.run_php("require$argv[2];private_origin_control('PRIVATE_VALUE_CANARY');",str(private))
   self.assertEqual(d['origin'],dict(source='unknown-source',line=0));self.assertNotIn(b'PRIVATE_',stderr);self.assertNotIn(td.encode(),stderr)
 def test_only_first_origin_is_kept_even_for_many_chunks(self):
  d,stderr=self.run_php("for($i=0;$i<100;$i++){governance_directory_output_origin_test_write('X');}")
  self.assertEqual(len(stderr.splitlines()),2);self.assertEqual(set(d['origin']),{'source','line'});self.assertTrue(d['depth_restored'])
 def test_inert_missing_outer_buffer_never_changes_output_stack(self):
  body="require$argv[1].'/"+HELPER+"';$before=ob_get_level();$state=governance_directory_output_origin_start($argv[1]);governance_directory_output_origin_finish($state);echo json_encode(array(ob_get_level()===$before,$state->observer_depth===null));"
  p=subprocess.run(['php','-r',body,str(ROOT)],capture_output=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(json.loads(p.stdout),[True,True]);self.assertEqual(p.stderr,b'')
 def test_diagnostic_failure_preserves_same_original_capture_bytes_and_hash(self):
  body="require$argv[1].'/"+HELPER+"';require$argv[1].'/"+FIXTURE+"';ob_start();governance_directory_output_origin_test_write(\"A\\0B\\n\");$before=ob_get_clean();ob_start();$state=governance_directory_output_origin_start(array('INVALID_DIAGNOSTIC_ROOT'));governance_directory_output_origin_test_write(\"A\\0B\\n\");governance_directory_output_origin_finish($state);$after=ob_get_clean();echo json_encode(array($before===$after,hash('sha256',$before)===hash('sha256',$after),ob_get_level()===0,$state->origin===null));"
  p=subprocess.run(['php','-r',body,str(ROOT)],capture_output=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(json.loads(p.stdout),[True]*4);self.assertEqual(p.stderr,b'')
 def test_actual_empty_CLEAN_FINAL_blocks_same_depth_foreign_replacement(self):
  body="require$argv[1].'/"+HELPER+"';$initial=ob_get_level();ob_start();$state=governance_directory_output_origin_start($argv[1]);ob_end_clean();$finalized=$state->owned_finalized;ob_start();echo 'FOREIGN_PRIVATE_CANARY';$before=ob_get_level();governance_directory_output_origin_finish($state);$still=ob_get_level()===$before;$foreign=ob_get_clean();$outer=ob_get_clean();echo json_encode(array($finalized,$state->origin===null,$still,$state->ended===false,$foreign==='FOREIGN_PRIVATE_CANARY',$outer==='',ob_get_level()===$initial));"
  p=subprocess.run(['php','-r',body,str(ROOT)],capture_output=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(json.loads(p.stdout),[True]*7);self.assertEqual(p.stderr,b'')
 def test_actual_deeper_foreign_buffer_is_not_popped_and_own_can_finish_after_test_removes_it(self):
  body="require$argv[1].'/"+HELPER+"';$initial=ob_get_level();ob_start();$state=governance_directory_output_origin_start($argv[1]);echo 'OWN';ob_start();echo 'FOREIGN';$before=ob_get_level();governance_directory_output_origin_finish($state);$still=ob_get_level()===$before;$notdone=!$state->ended&&!$state->owned_finalized;$foreign=ob_get_clean();governance_directory_output_origin_finish($state);$own=ob_get_clean();echo json_encode(array($still,$notdone,$foreign==='FOREIGN',$own==='OWN',$state->ended,$state->owned_finalized,ob_get_level()===$initial));"
  p=subprocess.run(['php','-r',body,str(ROOT)],capture_output=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(json.loads(p.stdout),[True]*7);self.assertEqual(p.stderr,b'')
if __name__=='__main__':unittest.main()

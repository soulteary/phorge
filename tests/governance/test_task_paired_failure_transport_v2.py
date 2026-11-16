"""Actual PHP shutdown callback DATA transport; no source or SQL qualification."""
import os,subprocess,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
class FailureTransport(unittest.TestCase):
 def run_callback(self,raw,status):
  source=(ROOT/'support/governance/task-additive-schema-v2/producer-prefix.php').read_text()
  marker='$task_outer_level=ob_get_level();ob_start();'
  self.assertEqual(source.count(marker),1)
  callback=source[source.index(marker):]
  self.assertLess(callback.index("put($task_run['output'].'-native-body.private.json',$raw)"),callback.index("if($native['passed']===true"))
  with tempfile.TemporaryDirectory()as temp:
   dest=Path(temp)/'capture';script=Path(temp)/'transport.php'
   pre="""<?php
final class PhorgeTaskDirectoryRunV2 {
 static function need($x){if($x!==true)throw new RuntimeException('refused');}
 static function put($p,$v){$f=fopen($p,'x');if(!$f)throw new RuntimeException('exists');fwrite($f,$v);fclose($f);chmod($p,0600);}
}
$task_run=['output'=>$argv[1]];$task_binding=null;$task_before=null;$task_after=null;
"""
   script.write_text(pre+callback+"\necho base64_decode($argv[2],true);exit((int)$argv[3]);\n")
   import base64,json
   p=subprocess.run(['php',str(script),str(dest),base64.b64encode(raw).decode(),str(status)],capture_output=True,timeout=5)
   body=Path(str(dest)+'-native-body.private.json')
   if raw.startswith((b'{"passed":false',b'{"passed":true')):
    self.assertEqual(body.read_bytes(),raw);self.assertEqual(body.stat().st_mode&0o777,0o600)
    result=json.loads(p.stdout);self.assertEqual(result,{'schema':'Task-native-failure-v2','passed':False,'native_receipt':json.loads(raw)})
    self.assertEqual(p.stderr,b'')
   else:
    self.assertFalse(body.exists());self.assertEqual(p.stdout,b'');self.assertEqual(p.stderr,b'Task_native_envelope_refused\n')
   self.assertEqual(p.returncode,status)
 def test_original_failure_raw_bytes_and_native42_survive_shutdown(self):
  self.run_callback(b'{"passed":false,"diagnostic":{"message":"synthetic-private-marker"}}\n',42)
 def test_malformed_output_keeps42_without_fabricated_body(self):
  self.run_callback(b'not-json',42)
 def test_success_body_without_capture_binding_never_fabricates_envelope(self):
  self.run_callback(b'{"passed":true}',0)
 def test_wrong_passed_type_keeps42_without_fabricated_body(self):
  self.run_callback(b'{"passed":"false"}',42)
if __name__=='__main__':unittest.main()

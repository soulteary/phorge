"""Pre-bootstrap guards only; these tests do not mint an operation or open SQL."""
import ast,hashlib,json,os,subprocess,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
class TaskDirectoryBindingControls(unittest.TestCase):
 def test_unbound_native_producer_refuses_before_bootstrap(self):
  p=ROOT/'support/governance/task-additive-schema-v2/producer-prefix.php'
  result=subprocess.run(['php',str(p),'--directory-transports-task-v2'],capture_output=True,text=True,env={'PATH':os.environ['PATH']})
  self.assertEqual(result.returncode,1);self.assertEqual(result.stdout,'');self.assertEqual(result.stderr,'Task_directory_v2_unbound_before_SQL\n')
 def test_binding_marker_is_single_and_native_self_inverse_is_required(self):
  raw=(ROOT/'support/governance/task-additive-schema-v2/producer-prefix.php').read_bytes()
  self.assertEqual(raw.count(b'const N05_TASK_DIRECTORY_RUN_PROFILE_SHA256=null;'),1)
  self.assertLess(raw.index(b'$task_original='),raw.index(b"$task_outer_level="))
 def test_builder_does_not_execute_engine_or_adopt_HEAD(self):
  p=ROOT/'scripts/governance/prepare_task_directory_run_v2.py';tree=ast.parse(p.read_text())
  calls=[n for n in ast.walk(tree)if isinstance(n,ast.Call)]
  self.assertFalse(any(isinstance(n.func,ast.Attribute)and n.func.attr in ('run','Popen','check_output','system')for n in calls))
  self.assertIn('expected_checkpoint=expected_checkpoint',p.read_text());self.assertIn('context.current(p)',p.read_text())
 def test_private_run_profile_rejects_authorization_and_dependency_shape_before_library(self):
  support=ROOT/'support/governance/task-additive-schema-v2/run.php'
  with tempfile.TemporaryDirectory()as tmp:
   directory=Path(tmp).resolve();path=directory/'profile.json'
   baseline={'schema':'Task-directory-run-binding-v2','runtime_authorized':True,'source_root':str(ROOT.resolve()),'source_revision':'a'*40,'run_id':'b'*32,'hmac_key':'c'*64,'source_pins':{},'output':str(directory/'new-output'),'native_role':'directory','producer_template_sha256':'e'*64}
   cases=[{**baseline,'runtime_authorized':False},{**baseline,'source_pins':{'unexpected':'d'*64}},{**baseline,'source_revision':'HEAD'},{**baseline,'extra':True}]
   for case in cases:
    raw=json.dumps(case).encode();path.write_bytes(raw);path.chmod(0o600);pin=hashlib.sha256(raw).hexdigest()
    code='require $argv[1];try{PhorgeTaskDirectoryRunV2::runProfile($argv[2],$argv[3]);exit(7);}catch(RuntimeException $e){echo $e->getMessage();}'
    result=subprocess.run(['php','-r',code,str(support),str(path),pin],capture_output=True,text=True)
    self.assertEqual(result.returncode,0,result.stderr);self.assertEqual(result.stdout,'Task_directory_run_binding_refused');self.assertEqual(result.stderr,'')
 def test_producer_and_run_support_lint(self):
  for relative in ('support/governance/task-additive-schema-v2/run.php','support/governance/task-additive-schema-v2/producer-prefix.php'):
   result=subprocess.run(['php','-l',str(ROOT/relative)],capture_output=True,text=True);self.assertEqual(result.returncode,0,result.stderr)
if __name__=='__main__':unittest.main()

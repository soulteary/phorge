"""Exact terminal PHP/Bash blocks; synthetic exits, actual projector/owned bytes."""
import importlib.util,json,os,subprocess,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
sp=importlib.util.spec_from_file_location('terminal_projector',ROOT/'scripts/governance/project_directory_transport_failure_v1.py');p=importlib.util.module_from_spec(sp);sp.loader.exec_module(p)
class TerminalTest(unittest.TestCase):
 def test_exact_warning_terminal_branch_emits_fixed_classification_without_raw(self):
  source=(ROOT/'support/governance/native-organization/test-directory-transports-v1-mysql.php').read_text();a=source.index('$native_output = $capture_active ? ob_get_clean()');b=source.index('$batch=$native_rr',a)
  code="require$argv[1].'/support/governance/native-organization/directory-transport-diagnostic.php';$phase='directory-transports';$failure=null;$cleanup_failures=array();$capture_active=true;ob_start();echo 'Warning: SECRET_CANARY';"+source[a:b]+"echo json_encode(array('passed'=>$passed));"
  child=subprocess.run(['php','-r',code,str(ROOT)],capture_output=True,timeout=10);self.assertEqual(child.returncode,0);self.assertFalse(json.loads(child.stdout)['passed']);self.assertNotIn(b'SECRET_CANARY',child.stdout+child.stderr)
  item=p.project(child.stderr)['failures'][0];self.assertEqual(item,dict(schema='native-directory-failure-marker-v1',kind='primary',**{'class':'RuntimeWarning'},source='unknown-source',line=0,stage='unexpected-native-runtime-output'))
 def test_missing_marker_only_hashes_bounded_private_material_and_never_accepts(self):
  with tempfile.TemporaryDirectory()as td:
   receipt=Path(td)/'receipt';receipt.write_bytes(b'{"raw":"SECRET_RECEIPT"}');consumer=Path(td)/'consumer';consumer.write_bytes(b'SECRET_CONSUMER')
   for phase in p.TERMINALS:
    d=p.project_terminal(b'SECRET_STDERR',phase,receipt,consumer);self.assertEqual(d['failures'],[]);self.assertEqual(d['terminal_classification'],phase);self.assertFalse(d['selected_matrix_accepted']);self.assertNotIn('SECRET_',json.dumps(d));self.assertEqual(d['private_receipt']['bytes'],24)
 def test_malformed_marker_never_falls_back_to_hash_only(self):
  with tempfile.TemporaryDirectory()as td:
   for raw in [p.PREFIX+b'not-json',p.PREFIX+b'{"secret":"SECRET"}']:
    with self.subTest(raw=raw),self.assertRaises((ValueError,json.JSONDecodeError)):p.project_terminal(raw,'native-exit',Path(td)/'missing',Path(td)/'missing')
   with self.assertRaises(ValueError):p.project_terminal(b'','arbitrary-phase',Path(td)/'a',Path(td)/'b')
 def test_exact_shell_all_terminal_paths_preserve_exit_and_publish_only_safe_JSON(self):
  workflow=(ROOT/'.github/workflows/native-directory-transports-v1.yml').read_text();start=workflow.index('          php support/governance/native-organization/test-directory-transports-v1-mysql.php --directory-transports >');end=workflow.index('\n          mv "$RUNNER_TEMP/directory-transports-v1/accepted.pending.json"',start);block=workflow[start:end]
  # Native matrix and consumer status are explicit command collaborators. The
  # exact three shell handlers, own child exits, files and real safe projector run.
  block=block.replace('php support/governance/native-organization/test-directory-transports-v1-mysql.php --directory-transports','native')
  line=next(x for x in block.splitlines()if 'python3 -B scripts/governance/check_directory_transports_v1.py --receipt' in x)
  block=block.replace(line,line[:line.index('python3')]+"consumer > \"$RUNNER_TEMP/directory-transports-v1/accepted.pending.json\" || {")
  for phase,code in [('native-exit',37),('native-stderr',1),('strict-consumer',19)]:
   with self.subTest(phase=phase),tempfile.TemporaryDirectory()as td:
    root=Path(td);(root/'directory-transports-v1').mkdir();wrapper=root/'project.py'
    # Only current Git source admission is omitted in this local shell control;
    # production CLI still performs it. Projection and bounded readers are real.
    wrapper.write_text("import runpy,json,sys\nm=runpy.run_path("+repr(str(ROOT/'scripts/governance/project_directory_transport_failure_v1.py'))+",run_name='test_projector')\na=sys.argv\nget=lambda k:a[a.index(k)+1]\nd=m['project_terminal'](m['raw_stderr'](get('--stderr')),get('--terminal'),get('--receipt'),get('--consumer-output'))\nprint(json.dumps(d))\n")
    setup='set -euo pipefail\numask 077\npython3(){ shift 2; command '+repr(os.sys.executable)+' '+repr(str(wrapper))+' "$@"; }\n'
    setup+="native(){ printf '%s' 'SECRET_RECEIPT'; "+("printf '%s' 'SECRET_STDERR' >&2; " if phase!='strict-consumer' else '')+("/bin/bash -c 'exit 37';" if phase=='native-exit' else 'return 0;')+" }\nconsumer(){ printf '%s' 'SECRET_CONSUMER'; /bin/bash -c 'exit 19'; }\n"
    env=dict(PATH='/usr/bin:/bin',RUNNER_TEMP=str(root),GITHUB_SHA='a'*40,GITHUB_OUTPUT=str(root/'output'))
    result=subprocess.run(['/bin/bash'],input=setup+block,text=True,env=env,capture_output=True,timeout=10);self.assertEqual(result.returncode,code,result.stderr)
    safe=(root/'directory-transports-safe-failure-v1/diagnostic.json').read_text();self.assertNotIn('SECRET_',safe);d=json.loads(safe);self.assertEqual(d['terminal_classification'],phase);self.assertFalse(d['selected_matrix_accepted']);self.assertEqual((root/'output').read_text(),'safe_diagnostic_owned=true\n')
if __name__=='__main__':unittest.main()

"""Execute actual phase selection, result validation and CI failure boundaries offline."""
import json, os, re, stat, subprocess, tempfile, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
class PartialWiring(unittest.TestCase):
 def test_actual_native_phase_selection_is_exact(self):
  s=(ROOT/'support/governance/native-organization/test-native-organization-mysql.php').read_text()
  select=s[s.index('$arguments='):s.index('$workbench_http=')]
  for args,expected in [([], 'all'),(['--recovery-partial-composition'],'recovery-partial-composition'),(['--recovery-partial-composition','--project-source-facts'],'invalid'),(['--recovery-partial-composition','--recovery-partial-composition'],'invalid'),(['--recovery-partial-composition=1'],'invalid'),(['--unknown'],'invalid')]:
   code='$argv=array_merge(["driver"],json_decode($argv[1],true));'+select+'echo json_encode($phase);'
   r=subprocess.run(['php','-r',code,json.dumps(args)],capture_output=True,text=True,timeout=10);self.assertEqual(r.returncode,0,r.stderr);self.assertEqual(json.loads(r.stdout),expected)
  for text in ["$native=governance_recovery_partial_composition_focused($fixture,$check)","?'native-recovery-partial-composition':$batch", "?'owned-native-partial-composition-no-permit':$receipt_scope"]:self.assertIn(text,s)
 def test_actual_result_validator_refuses_incomplete_duplicate_or_authorizing_result(self):
  labels=set()
  for name in ('restore-review-cases.php','partial-composition-cases.php'):
   labels.update(re.findall(r"'(recovery-(?:restore|partial)-[a-z0-9-]+)'",(ROOT/'support/governance/recovery'/name).read_text()))
  self.assertEqual(len(labels),21)
  code=r'''require$argv[1].'/support/governance/recovery/partial-composition-focused.php';$labels=json_decode($argv[2],true);$good=['review_ledger_candidate'=>true,'source_family_complete'=>false,'restoration_executed'=>false,'all_sources_complete'=>false,'production_activation'=>false];$out=[];foreach(['good','null','falseflag','trueflag','extra','missing','duplicate','foreign','booltype']as$case){$v=$good;$checks=$labels;switch($case){case'null':$v=null;break;case'falseflag':$v['review_ledger_candidate']=false;break;case'trueflag':$v['restoration_executed']=true;break;case'extra':$v['permit']=false;break;case'missing':array_pop($checks);break;case'duplicate':$checks[]=$checks[0];break;case'foreign':$checks[0]='foreign';break;case'booltype':$v['all_sources_complete']=0;break;}try{governance_recovery_partial_composition_validate_result($v,$checks);$out[]=true;}catch(RuntimeException$e){if($e->getMessage()!=='recovery_partial_case_result_refused'){throw$e;}$out[]=false;}}echo json_encode($out);'''
  r=subprocess.run(['php','-r',code,str(ROOT),json.dumps(sorted(labels))],capture_output=True,text=True,timeout=10);self.assertEqual(r.returncode,0,r.stderr);self.assertEqual(json.loads(r.stdout),[True]+[False]*8)
 def test_actual_CI_step_refuses_foreign_paths_native_failure_and_checker_failure(self):
  workflow=(ROOT/'.github/workflows/atomic-feature-regressions.yml').read_text();name='      - name: Verify fresh native partial restoration facts after Space source facts\n';step=workflow.split(name,1)[1].split('      - uses:',1)[0];script='\n'.join(line[10:] for line in step.splitlines() if line.startswith('          '))
  self.assertEqual(subprocess.run(['bash','-n'],input=script,text=True,capture_output=True).returncode,0)
  head=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
  with tempfile.TemporaryDirectory() as temp:
   tmp=Path(temp);target=tmp/'governance-recovery-partial-composition';foreign=tmp/'foreign';foreign.mkdir();sentinel=foreign/'sentinel';sentinel.write_bytes(b'private-foreign');env=dict(os.environ,RUNNER_TEMP=temp,GITHUB_SHA=head,GORGE_TEST_MYSQL_PASSWORD='synthetic-only')
   def run():return subprocess.run(['bash','-e','-o','pipefail','-c',script],cwd=ROOT,env=env,capture_output=True,text=True,timeout=10)
   for kind in ('file','dir','symlink'):
    if kind=='file':target.write_bytes(b'foreign')
    elif kind=='dir':target.mkdir();(target/'sentinel').write_bytes(b'foreign')
    else:target.symlink_to(foreign,target_is_directory=True)
    before=target.lstat();self.assertNotEqual(run().returncode,0);after=target.lstat();self.assertEqual((before.st_ino,before.st_mode),(after.st_ino,after.st_mode));self.assertEqual(sentinel.read_bytes(),b'private-foreign')
    if kind=='dir':(target/'sentinel').unlink();target.rmdir()
    else:target.unlink()
   for name in ('governance-batch-1','governance-native-organization'):(tmp/name).mkdir()
   (tmp/'governance-batch-1/native.json').write_text('synthetic-reference');(tmp/'governance-native-organization/accepted.json').write_text('synthetic-reference')
   commands=tmp/'commands';commands.mkdir();env['PATH']=str(commands)+os.pathsep+env['PATH'];php=commands/'php';php.write_text('#!/bin/sh\nexit 42\n');php.chmod(0o700)
   self.assertEqual(run().returncode,42);self.assertFalse((target/'accepted.json').exists());self.assertEqual(stat.S_IMODE(target.stat().st_mode),0o700)
   import shutil
   shutil.rmtree(target);php.write_text('#!/bin/sh\nprintf "synthetic-native-result"\n');checker=commands/'python3';checker.write_text('#!/bin/sh\nexit 43\n');checker.chmod(0o700)
   self.assertEqual(run().returncode,43);self.assertFalse((target/'accepted.json').exists());self.assertEqual((target/'integration.json').read_text(),'synthetic-native-result')
 def test_actual_cache_expectation_preserves_state_and_requires_precise_error(self):
  code=r'''class PhabricatorSpacesNamespace{function __construct(private$p){}function getPHID(){return$this->p;}}class PhabricatorSpacesNamespaceQuery{const KEY_DEFAULT='spaces.default';}class PhabricatorCaches{private static$requestCache;static function put($v){self::$requestCache=$v;}static function read(){return self::$requestCache;}}class PhutilInRequestKeyValueCache{private$cache;function __construct($v){$this->cache=$v;}}require$argv[1].'/support/governance/recovery/partial-composition-cases.php';$space=new PhabricatorSpacesNamespace('synthetic');$out=[];foreach([null,new PhutilInRequestKeyValueCache([]),new PhutilInRequestKeyValueCache(['spaces.all'=>['synthetic'=>$space]]),new PhutilInRequestKeyValueCache(['spaces.default'=>$space]),new PhutilInRequestKeyValueCache(['spaces.all'=>['other'=>new PhabricatorSpacesNamespace('other')]])]as$value){PhabricatorCaches::put($value);$out[]=[governance_recovery_partial_space_mutation_failure($space),PhabricatorCaches::read()===$value];}PhabricatorCaches::put(new stdClass());$denied=false;try{governance_recovery_partial_space_mutation_failure($space);}catch(RuntimeException$e){$denied=$e->getMessage()==='recovery_partial_fixture_cache_unknown';}echo json_encode(['out'=>$out,'unknown'=>$denied]);'''
  p=subprocess.run(['php','-r',code,str(ROOT)],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);d=json.loads(p.stdout)
  self.assertEqual(d,{'out':[[x,True] for x in ['recovery_restore_sources_stale','recovery_restore_sources_stale','recovery_spaces_cache_rows_changed','recovery_spaces_cache_default_changed','recovery_restore_sources_stale']],'unknown':True})

"""Byte-identical diagnostic extraction; exercise original filesystem behavior."""
import ast,hashlib,json,os,pathlib,re,stat,subprocess,sys,tempfile,unittest
ROOT=pathlib.Path(__file__).resolve().parents[2]
COLLECTOR=ROOT/'scripts/ci/source_footprint.py'
BASELINE='7b23c47e329f32e4fe083d0efa23fd82d6eefe6f'
SHA='2e38c1c21dabc54dc8d4b9cb901d963f185196c33146e302ace844595d1f93b7'
class Footprint(unittest.TestCase):
 def test_all_four_immutable_original_copies_byte_identical(self):
  old=subprocess.check_output(['git','--no-replace-objects','show',BASELINE+':.github/workflows/atomic-feature-regressions.yml'],cwd=ROOT,text=True)
  literals=[ast.literal_eval(m.group(1)).encode()for m in re.finditer(r'(?m)^\s*script = (.+)$',old)];self.assertEqual(len(literals),4)
  raw=COLLECTOR.read_bytes();self.assertEqual(len(raw),3786);self.assertEqual(hashlib.sha256(raw).hexdigest(),SHA)
  for value in literals:self.assertEqual(value,raw)
  current=(ROOT/'.github/workflows/atomic-feature-regressions.yml').read_text();self.assertEqual(current.count("script = Path('scripts/ci/source_footprint.py').read_text()"),4)
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup);self.base=pathlib.Path(self.tmp.name);self.repo=self.base/'repo';self.repo.mkdir();self.temp=self.base/'runner';self.output=self.temp/'atomic-footprint/records';self.output.mkdir(parents=True,mode=0o700)
  self.env={k:v for k,v in os.environ.items()if not k.startswith('GIT_')};self.env.update(RUNNER_TEMP=str(self.temp),GIT_CONFIG_NOSYSTEM='1',GIT_CONFIG_GLOBAL=os.devnull,PYTHONDONTWRITEBYTECODE='1')
  self.git('init','--quiet');(self.repo/'one.txt').write_text('native fixture\n');(self.repo/'folder').mkdir();(self.repo/'folder/tracked.txt').write_text('tracked\n');(self.repo/'link').symlink_to('/nonexistent-outside-fixture')
  self.git('add','.');self.git('-c','user.name=Fixture','-c','user.email=fixture@example.invalid','-c','commit.gpgsign=false','commit','--quiet','-m','fixture')
 def git(self,*args):return subprocess.run(['git','-c','core.hooksPath=/dev/null',*args],cwd=self.repo,env=self.env,check=True,capture_output=True)
 def run_collector(self,stage):return subprocess.run([sys.executable,'-B',str(COLLECTOR),stage],cwd=self.repo,env=self.env,capture_output=True,timeout=10)
 def test_real_repository_normal_and_nofollow_symlink_record(self):
  result=self.run_collector('before_build');self.assertEqual(result.returncode,0,result.stderr);data=json.loads((self.output/'before_build.json').read_text());self.assertEqual(data['extra_names'],[]);self.assertEqual(data['missing_names'],[]);self.assertFalse(data['truncated']);rows={r['path']:r for r in data['rows']};self.assertEqual(rows['link']['type'],'symlink');self.assertNotIn('.git',rows);self.assertEqual(stat.S_IMODE((self.output/'before_build.json').stat().st_mode),0o600)
 def test_dirty_extra_and_missing_are_diagnostics_not_refusals(self):
  (self.repo/'extra.txt').write_text('extra');(self.repo/'folder/tracked.txt').unlink();result=self.run_collector('after_build');self.assertEqual(result.returncode,0,result.stderr);data=json.loads((self.output/'after_build.json').read_text());self.assertEqual(data['extra_names'],['extra.txt']);self.assertEqual(data['missing_names'],['folder/tracked.txt'])
 def test_unsupported_stage_and_unsafe_path_refuse(self):
  self.assertNotEqual(self.run_collector('unreviewed').returncode,0);self.assertEqual(list(self.output.iterdir()),[])
  (self.repo/'unsafe\nname').write_text('unsafe');self.assertNotEqual(self.run_collector('before_build').returncode,0)
 def test_private_output_mode_and_symlink_directory_refuse(self):
  self.output.chmod(0o755);self.assertNotEqual(self.run_collector('before_build').returncode,0);self.output.chmod(0o700);self.output.rmdir();elsewhere=self.base/'elsewhere';elsewhere.mkdir(mode=0o700);self.output.symlink_to(elsewhere,target_is_directory=True);self.assertNotEqual(self.run_collector('before_build').returncode,0);self.assertEqual(list(elsewhere.iterdir()),[])
 def test_existing_stage_result_is_never_overwritten(self):
  self.assertEqual(self.run_collector('before_build').returncode,0);first=(self.output/'before_build.json').read_bytes();self.assertNotEqual(self.run_collector('before_build').returncode,0);self.assertEqual((self.output/'before_build.json').read_bytes(),first)
if __name__=='__main__':unittest.main()

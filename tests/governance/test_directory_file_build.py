"""Fixed File-only command construction; Docker/base inspection collaborators."""
import importlib.util,json,subprocess,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
s=importlib.util.spec_from_file_location('directory_file_build',ROOT/'tests/atomic/build_directory_file.py');builder=importlib.util.module_from_spec(s);s.loader.exec_module(builder)
class FileBuildTest(unittest.TestCase):
 def run_build(self,head='a'*40,dirty='',inspect_failure=False):
  calls=[];base=dict(images={'go':'go@sha256:'+'b'*64,'alpine':'alpine@sha256:'+'c'*64})
  def runner(args,**kwargs):
   calls.append(args)
   if args[0]=='git':
    if args[3:]==['rev-parse','HEAD']:out=head
    elif args[3:]==['status','--porcelain','--untracked-files=all']:out=dirty
    else:out=json.dumps(base)
    return subprocess.CompletedProcess(args,0,out,'')
   return subprocess.CompletedProcess(args,0,'','')
  def inspect(gorge,lock,actual_runner):
   self.assertIs(actual_runner,runner);self.assertEqual(lock,base)
   if inspect_failure:raise ValueError('actual base inspection collaborator refused')
   return {'fixture-only-collaborator':True}
  with patch.dict(builder._SHARED,{'inspect_bases':inspect}):
   result=builder.build_file(ROOT,'a'*40,runner)
  return result,calls
 def test_exactly_one_File_build_and_locked_base_arguments(self):
  result,calls=self.run_build();builds=[x for x in calls if x[0]=='docker'];self.assertEqual(len(builds),1)
  self.assertIn('SERVICE=gorge-file-storage',builds[0]);self.assertIn('PORT=8100',builds[0]);self.assertNotIn('SERVICE=gorge-image',builds[0])
  self.assertEqual(result['servicesBuilt'],['gorge-file-storage']);self.assertEqual(result['pairedGorgeRevision'],'a'*40)
 def test_mismatched_or_dirty_source_refuses(self):
  for kwargs in [dict(head='d'*40),dict(dirty=' M go.mod')]:
   with self.subTest(kwargs=kwargs),self.assertRaises(ValueError):self.run_build(**kwargs)
 def test_locked_base_refusal_propagates_before_build(self):
  with self.assertRaises(ValueError):self.run_build(inspect_failure=True)
if __name__=='__main__':unittest.main()

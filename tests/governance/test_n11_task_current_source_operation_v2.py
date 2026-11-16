"""Negative owner/CLI and local output DATA only; real signed admission pending."""
import importlib.util,json,os,subprocess,sys,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2];REFERENCE=Path(os.environ.get('N11_TEST_SOURCE_ROOT',str(ROOT))).resolve();sys.path[:0]=[str(ROOT/'scripts/governance'),str(REFERENCE/'scripts/governance')]
import n11_task_source_current_v2 as adapter
spec=importlib.util.spec_from_file_location('N11_entry_DATA',ROOT/'scripts/governance/run_n11_task_current_v2.py');entry=importlib.util.module_from_spec(spec);spec.loader.exec_module(entry)
class NegativeControls(unittest.TestCase):
 def test_actual_issuer_refuses_none_and_foreign_owner(self):
  for owner in (None,object()):
   with self.assertRaises(ValueError):adapter._guard(owner,REFERENCE,'a'*40)
 def test_source_only_public_entry_has_no_native_or_browser_dispatch(self):
  import ast
  tree=ast.parse((ROOT/'scripts/governance/run_n11_task_current_v2.py').read_bytes());raw=ast.unparse(next(n for n in tree.body if isinstance(n,ast.FunctionDef)and n.name=='source_only'));self.assertNotIn("['parent']['main']",raw);self.assertNotIn('._run_task_with_source_operation(',raw);self.assertIn("'run_or_finalize_dispatch_executed': False",raw)
 def test_real_CLI_unknown_secret_value_is_not_echoed(self):
  with tempfile.TemporaryDirectory()as temp:
   output=Path(temp)/'output';env=dict(os.environ,PYTHONPATH=str(REFERENCE/'scripts/governance'),PYTHONDONTWRITEBYTECODE='1')
   p=subprocess.run([sys.executable,'-B',str(ROOT/'scripts/governance/run_n11_task_current_v2.py'),'--root',str(REFERENCE),'--expected-checkpoint','a'*40,'--config','unused','--task-source-manifest','unused','--output',str(output),'--unknown','PRIVATE_SECRET_VALUE'],capture_output=True,env=env,timeout=5)
   self.assertNotEqual(p.returncode,0);self.assertEqual(p.stdout,b'');self.assertEqual(p.stderr,b'N11 current source invocation unavailable\n');self.assertFalse(output.exists())
 def test_private_output_exact_readback_and_foreign_paths_preserved(self):
  with tempfile.TemporaryDirectory()as temp:
   parent=Path(temp).resolve();output=parent/'output';entry.write_exclusive(output,{'source_only_DATA':True,'runtime_qualified':False});raw=output.read_bytes();before=output.lstat()
   self.assertEqual(json.loads(raw),{'source_only_DATA':True,'runtime_qualified':False});self.assertEqual(before.st_mode&0o777,0o600)
   with self.assertRaises(FileExistsError):entry.write_exclusive(output,{'changed':True})
   self.assertEqual(output.read_bytes(),raw);self.assertEqual(tuple(getattr(output.lstat(),k)for k in('st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns')),tuple(getattr(before,k)for k in('st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns')))
   link=parent/'link';link.symlink_to(output)
   with self.assertRaises(FileExistsError):entry.write_exclusive(link,{})
   self.assertEqual(output.read_bytes(),raw);self.assertTrue(link.is_symlink())
 def test_output_parent_alias_and_public_mode_refuse(self):
  with tempfile.TemporaryDirectory()as temp:
   root=Path(temp).resolve();parent=root/'private';parent.mkdir(mode=0o700);alias=root/'alias';alias.symlink_to(parent,target_is_directory=True)
   with self.assertRaises(ValueError):entry.write_exclusive(alias/'output',{})
   parent.chmod(0o755)
   with self.assertRaises(ValueError):entry.write_exclusive(parent/'output',{})
   self.assertFalse((parent/'output').exists())
 def test_write_and_all_close_errors_are_retained(self):
  with tempfile.TemporaryDirectory()as temp:
   target=Path(temp).resolve()/'output';real_close=os.close;closed=[]
   def fault_close(fd):real_close(fd);closed.append(fd);raise OSError('owned close fault')
   with patch.object(entry.os,'write',side_effect=OSError('owned write fault')),patch.object(entry.os,'close',side_effect=fault_close):
    with self.assertRaises(BaseExceptionGroup)as caught:entry.write_exclusive(target,{})
   self.assertEqual(len(closed),2);self.assertEqual(len(caught.exception.exceptions),3);self.assertEqual(str(caught.exception.exceptions[0]),'owned write fault')
if __name__=='__main__':unittest.main()

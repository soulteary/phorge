"""Owner/CLI negatives plus genuine current source admission; no runtime grant."""
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

import hashlib,ast,copy,types
from task_inverse_test_api_v2 import owner
import source_operation_snapshot as source
class CurrentPairParentData(unittest.TestCase):
 CONTINUATION=frozenset(['.github/workflows/private-mail-wire.yml','scripts/governance/check_native_organization.py','support/governance/notification/wiki-render-service.php','tests/atomic/extract_native_renderer.py','tests/atomic/paired-gorge.json','tests/atomic/test_current_pair_consumers.py','deploy/acceptance/build-lock.json'])
 def test_genuine_current_pair_and_exact_parent_DATA(self):
  with owner(ROOT)as operation:
   ctx=operation._task_contexts[ROOT]
   self.assertEqual(len(ctx.ci_inverse),85)
   prior={k:v for k,v in ctx.ci_inverse.items()if k not in self.CONTINUATION}
   self.assertEqual(len(prior),78)
   self.assertEqual(hashlib.sha256(json.dumps(prior,sort_keys=True,separators=(',',':')).encode()).hexdigest(),'a6019af54c1f0b5bfb67e3d3a2ac5967412b6114f122aadda209f5662d5e972d')
   for name in self.CONTINUATION:
    self.assertEqual(ctx.current(name),(ROOT/name).read_bytes())
    raw=ctx.parent(name);pin=ctx.parent_pins[name]
    self.assertEqual(len(raw),pin['bytes']);self.assertEqual(hashlib.sha256(raw).hexdigest(),pin['sha256'])
   # Parent bytes authenticate historical inputs only. Live paired bodies stay
   # CURRENT and are never replaced by these historical DATA rows.
   current=json.loads(ctx.current('tests/atomic/paired-gorge.json'))['revision']
   self.assertNotEqual(current,'83351b0e7eba38e50ff076ba6763ca68ebc57e1b')
   self.assertIn(current.encode(),ctx.current('support/governance/notification/wiki-render-service.php'))
   self.assertIn(b'83351b0e7eba38e50ff076ba6763ca68ebc57e1b',ctx.parent('tests/atomic/paired-gorge.json'))
 def test_original_current_admission_refuses_changed_parent_DATA(self):
  with owner(ROOT)as operation:
   ctx=operation._task_contexts[ROOT];path='scripts/governance/task_source_operation_context.py';tree=ast.parse(ctx.current(path))
   cls=next(n for n in tree.body if isinstance(n,ast.ClassDef)and n.name=='TaskContext');init=next(n for n in cls.body if isinstance(n,ast.FunctionDef)and n.name=='__init__')
   # Compile only the three unchanged CURRENT admission statements. This is
   # a DATA negative control, never a replacement source owner or SQL grant.
   start=next(i for i,n in enumerate(init.body)if isinstance(n,ast.Expr)and 'CI_INVERSE_SHA'in ast.unparse(n))
   body=copy.deepcopy(init.body[start:start+3]);self.assertEqual(len(body),3)
   function=ast.FunctionDef(name='admit_DATA',args=ast.arguments(posonlyargs=[],args=[ast.arg(arg='self')],kwonlyargs=[],kw_defaults=[],defaults=[]),body=body,decorator_list=[])
   ns={k:getattr(source.task_admission,k)for k in ['need','sha','decode','CI_INVERSE','CI_INVERSE_SHA']};exec(compile(ast.fix_missing_locations(ast.Module(body=[function],type_ignores=[])),str(ROOT/path),'exec'),ns)
   ns['admit_DATA'](types.SimpleNamespace(current_raw=dict(ctx.current_raw)))
   original=json.loads(ctx.current(ns['CI_INVERSE']))
   mutations=[]
   bad=copy.deepcopy(original);bad['files'].pop(next(iter(self.CONTINUATION)));mutations.append(bad)
   bad=copy.deepcopy(original);bad['files']['unreviewed/extra']=next(iter(bad['files'].values()));mutations.append(bad)
   bad=copy.deepcopy(original);bad['files'][next(iter(self.CONTINUATION))]['sha256']='0'*64;mutations.append(bad)
   bad=copy.deepcopy(original);bad['files'][next(iter(self.CONTINUATION))]['bytes']=True;mutations.append(bad)
   for bad in mutations:
    rows=dict(ctx.current_raw);rows[ns['CI_INVERSE']]=(json.dumps(bad,sort_keys=True)+'\n').encode()
    with self.assertRaises(ValueError):ns['admit_DATA'](types.SimpleNamespace(current_raw=rows))

   # Exercise the real CURRENT physical-file mode/length/hash statements on an
   # owned temporary file. This is not full source-entry qualification.
   loop=next(n for n in init.body if isinstance(n,ast.For)and ast.unparse(n.iter)=='entries.items()')
   branch=next(n for n in loop.body if isinstance(n,ast.If)and ast.unparse(n.test)=="pin['kind'] == 'file'")
   physical=ast.FunctionDef(name='physical_DATA',args=ast.arguments(posonlyargs=[],args=[ast.arg(arg=x)for x in ['self','path','pin','rel']],kwonlyargs=[],kw_defaults=[],defaults=[]),body=copy.deepcopy(branch.body),decorator_list=[])
   ns.update({k:getattr(source.task_admission,k)for k in ['read','stat']});exec(compile(ast.fix_missing_locations(ast.Module(body=[physical],type_ignores=[])),str(ROOT/path),'exec'),ns)
   rel='tests/atomic/paired-gorge.json';pin=ctx.entries[rel]
   with tempfile.TemporaryDirectory()as temporary:
    target=Path(temporary)/'current-pair';target.write_bytes(ctx.current(rel));target.chmod(pin['mode'])
    ns['physical_DATA'](types.SimpleNamespace(current_raw={}),target,pin,rel)
    target.write_bytes(ctx.current(rel)+b'\n')
    with self.assertRaises(ValueError):ns['physical_DATA'](types.SimpleNamespace(current_raw={}),target,pin,rel)

 def test_active_cross_repository_builder_uses_patched_toolchain(self):
  with owner(ROOT)as operation:
   ctx=operation._task_contexts[ROOT]
   workflow=ctx.current('.github/workflows/private-mail-wire.yml').decode()
   self.assertEqual(workflow.count("go-version: '1.27.4'"),1)
   self.assertNotIn("go-version: '1.27.3'",workflow)
   lock=json.loads(ctx.current('deploy/acceptance/build-lock.json'))
   self.assertEqual(lock['images']['go'],'docker.io/library/golang@sha256:70bd7ddea8b6cbe5d6943cde279d6b67feb10cc924fb9100d1d8757c1575b280')
   self.assertEqual(lock['images']['alpine'],'docker.io/library/alpine@sha256:5291449c3df73caf6ed85e649dec1b9e818b39a5d8c871e97afc13e9cd5e8fa8')

if __name__=='__main__':unittest.main()

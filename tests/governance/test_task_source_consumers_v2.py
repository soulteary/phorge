#!/usr/bin/env python3
"""Actual versioned consumers. Explicit checkpoint required; no hidden HEAD adoption.
Each real case uses the unchanged external10s deadline. --unit-only never claims
normal SourceOperation/transport/freshness/runtime acceptance.
"""
import argparse,json,os,shutil,sys,tempfile,time,unittest,stat,types
from types import MappingProxyType
from pathlib import Path
sys.dont_write_bytecode=True
ROOT=Path(__file__).resolve().parents[2];sys.path.insert(0,str(ROOT/'scripts/governance'))
import source_operation_snapshot as source
import check_directory_transports_task_source_v2 as directory
import check_media_freshness_task_source_v2 as media
CASES=('normal-directory','normal-freshness','normal-joint','task-change','source-change','extra','alias','mode','delete','root-alias','wrong-checkpoint','exit-task-change','exit-extra','exit-alias','exit-mode','exit-delete')
def need(v):
 if v is not True:raise ValueError('Task_consumer_v2_test_refused')
class UnitBoundaries(unittest.TestCase):
 def test_fake_owner_refuses_before_any_admission_or_git(self):
  for verify in(directory.verify_in_operation,media.verify_in_operation):
   with self.assertRaises(ValueError):verify(object(),ROOT)
 def test_default_original_route_retained(self):
  operation=source.open_source_operation(ROOT);self.assertFalse(operation._task_source)
 def test_no_checkpoint_cannot_implicitly_adopt_current_head(self):
  operation=source.open_task_source_operation(ROOT)
  with self.assertRaises(ValueError):operation.__enter__()
  self.assertEqual(operation._state,'failed')
class LexicalPathComponent(unittest.TestCase):
 """Lexical equivalence controls only; no operation or runtime qualification."""
 def temporary(self):return tempfile.TemporaryDirectory(prefix='Task-lexical-component-',dir=str(Path(tempfile.gettempdir()).resolve()))
 def test_inventory_retains_all_modes_aliases_unknown_keys(self):
  with self.temporary()as directory:
   root=Path(directory);(root/'.git').mkdir();(root/'.git'/'excluded').write_text('git');(root/'empty').mkdir();(root/'nested').mkdir();a=root/'plain';a.write_bytes(b'plain');a.chmod(0o644);b=root/'nested'/'exec';b.write_bytes(b'exec');b.chmod(0o755);os.link(a,root/'alias');(root/'file-link').symlink_to(a);(root/'dir-link').symlink_to(root/'nested');os.mkfifo(root/'unknown-special')
   expected={'plain':'100644','alias':'100644','nested/exec':'100755','file-link':'120000','dir-link':'120000','unknown-special':'invalid'}
   self.assertEqual(source._inventory(root),expected)
 def component(self,root,parents):
  task=source.task_admission;c=types.SimpleNamespace(root=root,_directory_scopes_root=root,_directory_scopes=MappingProxyType({p:p.is_relative_to(root)for p in parents}))
  return c,task
 def test_directory_stamp_known_below_root_and_ancestors_exact(self):
  with self.temporary()as directory:
   root=Path(directory);child=root/'child';child.mkdir();parents=(child,root,*root.parents);c,task=self.component(root,parents)
   for p in parents:
    raw=task.stamp(p.lstat());expected=raw if p.is_relative_to(root)else raw[:5]
    self.assertEqual(task.TaskContext.directory_stamp(c,p),expected);self.assertEqual(task.TaskContext.directory_stamp(c,p,p.lstat()),expected)
 def test_directory_scope_cache_is_frozen(self):
  with self.temporary()as directory:
   root=Path(directory);c,_=self.component(root,(root,*root.parents))
   with self.assertRaises(TypeError):c._directory_scopes[root]=False
 def test_unseen_path_and_changed_root_use_original_classification(self):
  with self.temporary()as directory:
   root=Path(directory);child=root/'unseen';child.mkdir();c,task=self.component(root,(root,*root.parents));self.assertEqual(task.TaskContext.directory_stamp(c,child),task.stamp(child.lstat()));c.root=child
   self.assertEqual(task.TaskContext.directory_stamp(c,root),task.stamp(root.lstat())[:5])
 def test_cached_scope_never_caches_filesystem_metadata(self):
  with self.temporary()as directory:
   root=Path(directory);c,task=self.component(root,(root,*root.parents));before=task.TaskContext.directory_stamp(c,root);(root/'new').mkdir();after=task.TaskContext.directory_stamp(c,root);self.assertEqual(after,task.stamp(root.lstat()));self.assertNotEqual(before,after)
 def test_inventory_mode_contract_does_not_claim_inode_authority(self):
  with self.temporary()as directory:
   root=Path(directory);a=root/'a';a.write_bytes(b'one');a.chmod(0o644);first=source._inventory(root);old_inode=a.stat().st_ino;b=root/'replacement';b.write_bytes(b'one');b.chmod(0o644);os.replace(b,a);self.assertNotEqual(a.stat().st_ino,old_inode);self.assertEqual(source._inventory(root),first);a.chmod(0o755);self.assertEqual(source._inventory(root),{'a':'100755'})

class CapturedPathComponent(unittest.TestCase):
 """Path-allocation/component checks only; no actual source grant is issued."""
 def context(self,root,names):
  task=source.task_admission;c=types.SimpleNamespace(root=root,current_raw={},files={},directories={},_path_cache_root=root);paths={};parents={};chains={}
  for name in names:
   p=root/name;paths[name]=p
   if p.parent not in chains:chains[p.parent]=tuple(p.parents)
   parents[name]=chains[p.parent];c.current_raw[name]=p.read_bytes();c.files[name]=task.stamp(p.lstat())
   for parent in p.parents:
    if parent not in c.directories:c.directories[parent]=task.stamp(parent.lstat())if parent.is_relative_to(root)else task.stamp(parent.lstat())[:5]
  c._captured_paths=MappingProxyType(paths);c._captured_file_parents=MappingProxyType(parents);c._directory_scopes_root=root;c._directory_scopes=MappingProxyType({p:p.is_relative_to(root)for p in c.directories});c.require=lambda:None;c.directory_stamp=lambda p:task.TaskContext.directory_stamp(c,p)
  return c
 def temporary(self):return tempfile.TemporaryDirectory(prefix='Task-captured-path-component-',dir=str(Path(tempfile.gettempdir()).resolve()))
 def test_parent_chain_shared_and_path_maps_frozen(self):
  with self.temporary()as directory:
   root=Path(directory);(root/'a').write_bytes(b'a');(root/'b').write_bytes(b'b');c=self.context(root,('a','b'));self.assertIs(c._captured_file_parents['a'],c._captured_file_parents['b']);self.assertEqual(c._captured_file_parents['a'],tuple((root/'a').parents))
   with self.assertRaises(TypeError):c._captured_paths['a']=root/'b'
   with self.assertRaises(TypeError):c._captured_file_parents['a']=()
 def test_current_checks_leaf_and_all_parents_on_every_read(self):
  with self.temporary()as directory:
   root=Path(directory);p=root/'a';p.write_bytes(b'a');c=self.context(root,('a',));self.assertEqual(source.task_admission.TaskContext.current(c,'a'),b'a');(root/'new-child').mkdir()
   with self.assertRaises(ValueError):source.task_admission.TaskContext.current(c,'a')
 def test_mode_and_same_body_inode_drift_still_rejected(self):
  for change in ('mode','inode','alias'):
   with self.temporary()as directory:
    root=Path(directory);p=root/'a';p.write_bytes(b'a');c=self.context(root,('a',))
    if change=='mode':p.chmod(0o755)
    elif change=='inode':q=root/'replacement';q.write_bytes(b'a');os.replace(q,p)
    else:os.link(p,root/'alias')
    with self.assertRaises(ValueError):source.task_admission.TaskContext.current(c,'a')
 def test_unknown_cache_entry_falls_back_to_original_path(self):
  with self.temporary()as directory:
   root=Path(directory);(root/'a').write_bytes(b'a');c=self.context(root,('a',));c._captured_paths=MappingProxyType({});c._captured_file_parents=MappingProxyType({});self.assertEqual(source.task_admission.TaskContext.current(c,'a'),b'a')
 def test_root_change_does_not_reuse_old_path_or_chain(self):
  with self.temporary()as directory:
   outer=Path(directory);root=outer/'one';root.mkdir();(root/'a').write_bytes(b'a');other=outer/'two';other.mkdir();(other/'a').write_bytes(b'a');c=self.context(root,('a',));c.root=other
   with self.assertRaises(ValueError):source.task_admission.TaskContext.current(c,'a')
 def test_owner_requirement_runs_before_cached_read(self):
  with self.temporary()as directory:
   root=Path(directory);(root/'a').write_bytes(b'a');c=self.context(root,('a',))
   def denied():raise ValueError('component owner denied')
   c.require=denied
   with self.assertRaisesRegex(ValueError,'component owner denied'):source.task_admission.TaskContext.current(c,'a')

def mutate(root,case):
 p=root/'src/infrastructure/governance/content/PhorgeTaskContentSource.php';case=case.removeprefix('exit-')
 if case=='task-change':p.write_bytes(p.read_bytes()+b'\n// unreviewedTask\n')
 elif case=='source-change':
  p=root/'scripts/governance/check_directory_transports_task_source_v2.py';p.write_bytes(p.read_bytes()+b'\n# unreviewed consumer\n')
 elif case=='extra':(root/'src/unlisted-task-v2.php').write_bytes(b'<?php // unknown\n')
 elif case=='alias':os.link(p,p.parent/'unlisted-task-alias.php')
 elif case=='mode':p.chmod(0o755)
 elif case=='delete':p.unlink()
def run_case(case,checkpoint):
 need(case in CASES);start=time.monotonic();result={'case':case,'source_only':True,'SQL_executed':False,'engine_executed':False,'optional_feature_activation':False,'original76_executed':False,'actual_issued_SourceOperation':False};stage='enter'
 if case.startswith('normal-'):
  if case=='normal-directory':result['directory']=directory.verify(ROOT,checkpoint)
  elif case=='normal-freshness':result['freshness']=media.verify(ROOT,checkpoint)
  else:
   with source.open_task_source_operation(ROOT,expected_checkpoint=checkpoint)as operation:
    result['directory']=directory.verify_in_operation(operation,ROOT);result['freshness']=media.verify_in_operation(operation,ROOT)
   result['directory']['source_operation_exit_verified']=True;result['freshness']['source_operation_exit_verified']=True
  result.update(passed=True,actual_issued_SourceOperation=True,source_operation_entry_exit_passed=True)
 else:
  with tempfile.TemporaryDirectory(prefix='Task-v2-real-negative-')as temporary:
   home=Path(temporary).resolve(strict=True);producer=home/'source';shutil.copytree(ROOT,producer,symlinks=True,ignore=shutil.ignore_patterns('.git'))
   if case=='root-alias':
    alias=home/'alias';alias.symlink_to(producer,target_is_directory=True);producer=alias
   elif not case.startswith('exit-')and case!='wrong-checkpoint':mutate(producer,case)
   try:
    with source.open_task_source_operation(producer,expected_checkpoint='0'*40 if case=='wrong-checkpoint'else checkpoint)as operation:
     source.require_operation(operation,producer);result['actual_issued_SourceOperation']=True;stage='body'
     need(case.startswith('exit-'));mutate(producer,case);stage='exit'
    raise RuntimeError('negative unexpectedly accepted')
   except(ValueError,OSError)as e:
    expected='exit'if case.startswith('exit-')else'enter';need(stage==expected);result.update(passed=True,negative_refused_at=stage,error_type=type(e).__name__)
 result['seconds']=time.monotonic()-start;return result
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--unit-only',action='store_true');p.add_argument('--expected-checkpoint');p.add_argument('--case',choices=CASES,default='normal-joint');a=p.parse_args()
 if a.unit_only:
  unittest.main(argv=[sys.argv[0]],exit=True)
 if a.expected_checkpoint is None:p.error('--expected-checkpoint is required; current HEAD is never adopted')
 try:print(json.dumps(run_case(a.case,a.expected_checkpoint),sort_keys=True))
 except Exception:print('{"passed":false,"SQL_executed":false,"engine_executed":false}');raise SystemExit(1)

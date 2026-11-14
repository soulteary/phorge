#!/usr/bin/env python3
"""Actual versioned consumers. Explicit checkpoint required; no hidden HEAD adoption.
Each real case uses the unchanged external10s deadline. --unit-only never claims
normal SourceOperation/transport/freshness/runtime acceptance.
"""
import argparse,json,os,shutil,sys,tempfile,time,unittest
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

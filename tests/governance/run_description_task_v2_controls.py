#!/usr/bin/env python3
"""Explicitly substitute three current-source checks; retain every other control."""
import argparse,os,re,sys,unittest
from pathlib import Path
HERE=Path(__file__).resolve().parent
sys.path.insert(0,str(HERE))
def leaves(suite):
 for item in suite:
  if isinstance(item,unittest.TestSuite):yield from leaves(item)
  else:yield item
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--expected-checkpoint',required=True);a=p.parse_args()
 if re.fullmatch('[a-f0-9]{40}',a.expected_checkpoint)is None:raise SystemExit('explicit_Task_checkpoint_required')
 os.environ['PHORGE_TASK_TEST_CHECKPOINT']=a.expected_checkpoint
 suite=unittest.defaultTestLoader.discover(str(HERE),pattern='test_project_description*.py')
 tests=list(leaves(suite));old=[t for t in tests if t.__class__.__module__=='test_project_description_main_integration'];new=[t for t in tests if t.__class__.__module__=='test_project_description_main_task_v2']
 if len(old)!=3 or len(new)!=3 or {t._testMethodName for t in old}!={t._testMethodName for t in new}:raise SystemExit('Task_main_control_substitution_refused')
 selected=[t for t in tests if t not in old]
 result=unittest.TextTestRunner(verbosity=2).run(unittest.TestSuite(selected));raise SystemExit(0 if result.wasSuccessful()else 1)

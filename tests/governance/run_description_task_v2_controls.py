#!/usr/bin/env python3
"""Explicitly route39 Description controls to current Task source; retain all others."""
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
 if re.fullmatch('[a-f0-9]{40}',a.expected_checkpoint)is None or a.expected_checkpoint=='0'*40:raise SystemExit('explicit_Task_checkpoint_required')
 os.environ['PHORGE_TASK_TEST_CHECKPOINT']=a.expected_checkpoint
 suite=unittest.defaultTestLoader.discover(str(HERE),pattern='test_project_description*.py')
 tests=list(leaves(suite))
 from task_description_shard_routes_v3 import route_test_cases
 selected=route_test_cases(tests)
 result=unittest.TextTestRunner(verbosity=2).run(unittest.TestSuite(selected));raise SystemExit(0 if result.wasSuccessful()else 1)

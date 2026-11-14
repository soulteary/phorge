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
 if re.fullmatch('[a-f0-9]{40}',a.expected_checkpoint)is None or a.expected_checkpoint=='0'*40:raise SystemExit('explicit_Task_checkpoint_required')
 os.environ['PHORGE_TASK_TEST_CHECKPOINT']=a.expected_checkpoint
 suite=unittest.defaultTestLoader.discover(str(HERE),pattern='test_project_description*.py')
 tests=list(leaves(suite));old=[t for t in tests if t.__class__.__module__=='test_project_description_main_integration'];new=[t for t in tests if t.__class__.__module__=='test_project_description_main_task_v2']
 if len(old)!=3 or len(new)!=3 or {t._testMethodName for t in old}!={t._testMethodName for t in new}:raise SystemExit('Task_main_control_substitution_refused')
 old_consumers=[t for t in tests if t.__class__.__module__=='test_project_description_current_consumers']
 new_consumers=[t for t in tests if t.__class__.__module__=='test_project_description_current_consumers_task_v2']
 if len(old_consumers)!=8 or len(new_consumers)!=8 or {t._testMethodName for t in old_consumers}!={t._testMethodName for t in new_consumers}:raise SystemExit('Task_current_consumer_substitution_refused')
 old_admission=[t for t in tests if t.__class__.__module__=='test_project_description_admission']
 new_admission=[t for t in tests if t.__class__.__module__=='test_project_description_admission_task_v2']
 if len(old_admission)!=8 or len(new_admission)!=8 or {t._testMethodName for t in old_admission}!={t._testMethodName for t in new_admission}:raise SystemExit('Task_admission_substitution_refused')
 selected=[t for t in tests if t not in old and t not in old_consumers and t not in old_admission]
 if len({t.id()for t in selected})!=len(selected):raise SystemExit('Task_duplicate_test_id_refused')
 result=unittest.TextTestRunner(verbosity=2).run(unittest.TestSuite(selected));raise SystemExit(0 if result.wasSuccessful()else 1)

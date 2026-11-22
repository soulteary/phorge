"""Explicit current Task bridge successor; original immutable tests unchanged.

CURRENT code executes inside one genuine source owner. Historical bytes and
exact predecessor inverses are DATA, never a runtime execution authorization.
"""
import hashlib,sys,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
from task_inverse_test_api_v2 import owner
import source_operation_snapshot as source
from test_task_current_source_admission import bridge
ORIGINAL='tests/governance/test_task_current_source_admission.py'
class ExactHistoricalBridgeTaskV2(unittest.TestCase):
 def test_all_four_modified_bodies_restore_exact5903_parent(self):
  with owner(ROOT)as operation:
   context=operation._task_contexts[ROOT]
   old=context.current(ORIGINAL);pin=source.task_admission.TASK_NEW_PINS[ORIGINAL]
   self.assertEqual(len(old),pin['bytes']);self.assertEqual(hashlib.sha256(old).hexdigest(),pin['sha256'])
   self.assertEqual(len(bridge.CURRENT_SHARED),4)
   for relative,pin in bridge.CURRENT_SHARED.items():
    with self.subTest(relative=relative):
     raw=(ROOT/relative).read_bytes();self.assertEqual(raw,context.current(relative))
     if relative in source.task_admission.PHP86_COMPATIBILITY_PATHS:
      profile_raw=context.current(source.task_admission.PHP86_COMPATIBILITY_HISTORY)
      with self.assertRaises(ValueError):source.task_admission.php86_compatibility_inverse(relative,raw+b'\n',profile_raw)
      raw=source.task_admission.php86_compatibility_inverse(relative,raw,profile_raw)
     if relative in source.task_admission.HANDOFF_PATHS:
      handoff_raw=context.current(source.task_admission.HANDOFF_HISTORY)
      with self.assertRaises(ValueError):source.task_admission.handoff_inverse(relative,raw+b'\n',handoff_raw)
      raw=source.task_admission.handoff_inverse(relative,raw,handoff_raw)
     if relative in source.task_admission.RELEASE_HISTORY_PATHS:
      # Actual captured current DATA must first reach the exact original Task
      # intermediate; the original four-body reducer still runs below.
      history_raw=context.current(source.task_admission.RELEASE_HISTORY)
      history=source.task_admission.load_release_history(history_raw)
      with self.assertRaises(ValueError):source.task_admission.load_release_history(history_raw+b'\n')
      with self.assertRaises(ValueError):source.task_admission.release_history_inverse(relative,raw+b'\n',history)
      raw=source.task_admission.release_history_inverse(relative,raw,history)
     if relative==source.task_admission.TASK_PREUPGRADE_FIXTURE:
      recipe=context.current(source.task_admission.TASK_PREUPGRADE_RECIPE)
      inverse=source.task_admission.task_preupgrade_fixture_inverse
      with self.assertRaises(ValueError):inverse(relative,raw+b'\n',recipe)
      with self.assertRaises(ValueError):inverse(relative,raw,recipe+b'\n')
      raw=inverse(relative,raw,recipe)
     self.assertEqual(len(raw),pin['current_bytes'])
     self.assertEqual(hashlib.sha256(raw).hexdigest(),pin['current_sha256'])
     prior=bridge.reduce_selected(relative,raw)
     self.assertEqual(hashlib.sha256(prior).hexdigest(),pin['parent_sha256'])
     self.assertEqual(len(prior),pin['parent_bytes'])
     self.assertEqual(prior,context.parent(relative))

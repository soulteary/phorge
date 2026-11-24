"""Static artifact causality controls; no GitHub execution or SQL proof."""
import unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
GUARD="if: always() && steps.push-groups.outcome != 'skipped' && steps.push-groups.outcome != ''"
def check(text):
 start=text.index('      - name: Verify deterministic historical push-event grouping');end=text.index('      - name: Verify native permission routes',start);body=text[start:end]
 assert body.count('        id: push-groups\n')==1
 assert body.count('        '+GUARD+'\n')==1
 assert body.count('if-no-files-found: error')==1
 assert body.count('actions/upload-artifact@')==1
 assert "--profile push-groups-regression"in body
 assert 'continue-on-error'not in body and '|| true'not in body
 return body
class Causality(unittest.TestCase):
 def setUp(self):self.text=(ROOT/'.github/workflows/atomic-feature-regressions.yml').read_text()
 def test_exact_guard_and_required_artifact(self):check(self.text)
 def test_terminal_truth_table(self):
  check(self.text)
  for outcome,expected in [('success',True),('failure',True),('cancelled',True),('skipped',False),('',False)]:
   self.assertEqual(outcome!='skipped'and outcome!='',expected)
 def test_skipped_and_failed_artifact_mutants_refused(self):
  for old,new in [(GUARD,'if: always()'),(GUARD,'if: success()'),('        id: push-groups\n',''),('if-no-files-found: error','if-no-files-found: ignore')]:
   with self.subTest(mutant=new),self.assertRaises(AssertionError):check(self.text.replace(old,new))
if __name__=='__main__':unittest.main()

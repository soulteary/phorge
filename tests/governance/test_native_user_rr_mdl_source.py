"""Bounded source preparation checks; mapper and SQL remain external gates."""
import re, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
def inventory():
 driver=(ROOT/'tests/governance/run.php').read_text();part=driver.split('$classes = array(',1)[1].split(');',1)[0]
 classes=re.findall(r"'([A-Za-z0-9_]+TestCase)'",part);ids=[]
 for name in classes:
  paths=list((ROOT/'src').rglob(name+'.php'))
  if len(paths)!=1:raise ValueError('class source ambiguous')
  ids.extend(name+'::'+m for m in re.findall(r'public function (test[A-Za-z0-9_]+)\(',paths[0].read_text()))
 return classes,ids
class Source(unittest.TestCase):
 def test_inventory_is_source_derived_not_runtime(self):
  classes,ids=inventory();self.assertEqual(44,len(classes));self.assertEqual(265,len(ids));self.assertEqual(len(ids),len(set(ids)))
 def test_default_off_and_real_entry(self):
  options=(ROOT/'src/infrastructure/governance/recovery/PhorgeGovernanceRecoveryConfigOptions.php').read_text()
  self.assertIn("'governance.account-recovery.owned-rr-scope.enabled','bool',false",options)
  driver=(ROOT/'support/governance/native-organization/test-native-organization-mysql.php').read_text()
  self.assertIn("'--native-user-rr-mdl-only'=>'native-user-rr-mdl-only'",driver)
  self.assertIn("require_once __DIR__.'/workers.php'",driver)
  self.assertIn("$native=governance_native_rr_focused($fixture,$check)",driver)
 def test_same_native_scope_not_separate_fencer(self):
  s=(ROOT/'support/governance/recovery/native-writer-rr-mdl-component.php').read_text()
  self.assertIn("establishConnection('w')",s)
  self.assertNotIn('new mysqli',s);self.assertNotIn('forcedConnection',s)
  focused=(ROOT/'support/governance/recovery/native-writer-rr-focused.php').read_text()
  self.assertIn("foreach(array('commit','rollback')",focused)
  self.assertNotIn('catch(RuntimeException',focused)
if __name__=='__main__':unittest.main()

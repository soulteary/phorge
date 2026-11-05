"""Real native source classes with synthetic backing; no supported PHP/SQL proof."""
import json
from pathlib import Path
import subprocess
import unittest
ROOT=Path(__file__).resolve().parents[2]
class ApplicationConfigFacts(unittest.TestCase):
    def test_actual_native_source_controls(self):
        p=subprocess.run(['php',str(ROOT/'support/governance/recovery/tests/application-config-facts.php'),str(ROOT)],capture_output=True,text=True,check=True)
        self.assertEqual('',p.stderr);self.assertNotIn('SYNTHETIC_SECRET',p.stdout)
        result=json.loads(p.stdout);self.assertTrue(result['passed']);self.assertFalse(result['SQL_executed'])
        self.assertEqual(13,len(result['checks']));self.assertEqual(13,len(set(result['checks'])))
    def test_no_getter_provider_or_SQL_execution(self):
        source=(ROOT/'src/infrastructure/governance/recovery/PhorgeRecoveryApplicationConfigFacts.php').read_text()
        for forbidden in ('->getKeys(', '->getAllKeys(', 'loadAllOptions(', 'queryfx', 'getEnvConfig(', 'setIsDisabled(', 'var_export('):
            self.assertNotIn(forbidden,source)
        self.assertIn("hash_hmac('sha256'",source)
        self.assertIn("$this->finished=true",source)
        self.assertIn("$facts->owners[]=$row['object']",source)
if __name__=='__main__':unittest.main()

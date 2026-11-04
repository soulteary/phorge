"""Static fixture phase contract only; not a substitute for native guard/SQL proof."""
from pathlib import Path
import unittest
ROOT=Path(__file__).resolve().parents[2]
class PreparationPhaseTests(unittest.TestCase):
    def test_synthetic_admission_and_sessions_precede_measured_recovery_flag(self):
        source=(ROOT/'support/governance/recovery/organization-lifecycle-cases.php').read_text()
        boundary=source.index("    $flags=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.account-recovery.enabled',true)")
        before,measured=source[:boundary],source[boundary:]
        self.assertIn("getEnvConfig('governance.account-recovery.enabled')!==false",before)
        self.assertIn('$second_user=(new PhabricatorUser())',before)
        self.assertIn('$user=(new PhabricatorUser())',before)
        self.assertEqual(before.count('governance_native_request('),2)
        self.assertIn("$moving=$make('moving');$owner=$make('owner');",before)
        self.assertNotIn('new PhabricatorUser()',measured)
        self.assertNotIn('governance_native_request(',measured)
        self.assertNotIn("$make('",measured)
        self.assertNotIn("overrideEnvConfig('governance.account-recovery.enabled',false)",source)
if __name__=='__main__':unittest.main()

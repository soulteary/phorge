import unittest
from ci_test_partition import owner,TOOL_MODULES
class Partition(unittest.TestCase):
 def test_tools_have_exact_separate_owner(self):
  for module in TOOL_MODULES:self.assertEqual(owner(module),2)
 def test_similar_names_are_not_excluded(self):
  for module in TOOL_MODULES:self.assertIn(owner(module+'_extra'),(0,1))
 def test_product_safety_modules_remain_product_owned(self):
  for module in ('test_media_app_session_admission','test_native_directory','test_recovery_session_lease_gate','test_private_calendar_wire'):self.assertIn(owner(module),(0,1))

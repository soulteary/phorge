import unittest
from run_pending import capability_observation
class Process:
    def __init__(self, code): self.code = code
    def poll(self): return self.code
class ObservationTests(unittest.TestCase):
    def test_fixed_store_failure_no_message(self):
        value=capability_observation(503,b'{"error":{"code":"ERR_DELIVERY_STORE","message":"PRIVATE_SENTINEL"}}',Process(None))
        self.assertEqual(value['error_code'],'ERR_DELIVERY_STORE')
        self.assertTrue(value['owned_mailer_running'])
        self.assertNotIn('PRIVATE_SENTINEL',str(value))
    def test_unknown_body_and_exit_are_bounded(self):
        for body in (b'PRIVATE_SENTINEL',b'{"error":"PRIVATE_SENTINEL"}',b'null'):
            value=capability_observation(503,body,Process(7))
            self.assertEqual(value['error_code'],'unknown')
            self.assertEqual(value['owned_mailer_exit_code'],7)
            self.assertNotIn('PRIVATE_SENTINEL',str(value))
    def test_true_false_and_nonbool_are_distinct(self):
        for raw,want in ((b'true',True),(b'false',False),(b'1',None)):
            value=capability_observation(200,b'{"data":{"perPhysicalAttemptAuthorization":'+raw+b'}}',Process(None))
            self.assertIs(value['per_attempt_authorization'],want)
if __name__=='__main__':unittest.main()

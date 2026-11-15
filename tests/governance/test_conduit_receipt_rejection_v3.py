"""Pure closed diagnostic contract; no SourceOperation is imported or executed."""
import ast,json,pathlib,unittest
P=pathlib.Path(__file__).resolve().parents[2]/'scripts/governance/check_conduit_task_schema_receipt_v2.py'
def model():
 tree=ast.parse(P.read_text());nodes=[n for n in tree.body if isinstance(n,ast.Assign)and any(isinstance(t,ast.Name)and t.id=='REJECTION_REASONS'for t in n.targets)or isinstance(n,ast.FunctionDef)and n.name=='rejection_diagnostic'];ns={};exec(compile(ast.Module(body=nodes,type_ignores=[]),str(P),'exec'),ns);return ns
class ReasonTests(unittest.TestCase):
 def test_all_known_literals_closed(self):
  m=model()
  for text,reason in m['REJECTION_REASONS'].items():
   result=m['rejection_diagnostic'](ValueError(text));self.assertEqual(result['reason'],reason);self.assertFalse(result['native_receipt_accepted']);self.assertFalse(result['runtime_authority']);self.assertEqual(result['cleanup'],'unknown');self.assertNotIn(text,json.dumps(result))
 def test_unknown_sensitive_messages_are_null(self):
  m=model()
  for text in ('secret-token password SQL SELECT endpoint','', 'Task_Conduit_receipt_refused suffix'):
   result=m['rejection_diagnostic'](ValueError(text));self.assertIsNone(result['reason']);self.assertNotIn(text,json.dumps(result))if text else None
 def test_types_and_multiple_args_fail_closed(self):
  m=model();known=next(iter(m['REJECTION_REASONS']))
  class Foreign(ValueError):pass
  for error in (Foreign(known),RuntimeError(known),ValueError(known,'sensitive'),ValueError(42),ValueError()):self.assertIsNone(m['rejection_diagnostic'](error)['reason'])
 def test_no_exception_string_or_repr_called(self):
  m=model()
  class Hostile(Exception):
   def __str__(self):raise AssertionError('stringification forbidden')
   def __repr__(self):raise AssertionError('repr forbidden')
  self.assertIsNone(m['rejection_diagnostic'](Hostile('sensitive'))['reason'])
if __name__=='__main__':unittest.main()

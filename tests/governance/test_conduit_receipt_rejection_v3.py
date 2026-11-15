"""Pure closed diagnostic contract; no SourceOperation is imported or executed."""
import ast,json,pathlib,unittest
P=pathlib.Path(__file__).resolve().parents[2]/'scripts/governance/check_conduit_task_schema_receipt_v2.py'
def model():
 tree=ast.parse(P.read_text());nodes=[n for n in tree.body if isinstance(n,ast.Assign)and any(isinstance(t,ast.Name)and t.id in ('REJECTION_REASONS','ACCEPT_FAILURE_STAGES','DIAGNOSTIC_MAIN_STAGES','EXCEPTION_TYPES')for t in n.targets)or isinstance(n,ast.FunctionDef)and n.name in ('rejection_diagnostic','rejection_stage')];ns={'json':json,'accept':None};exec(compile(ast.Module(body=nodes,type_ignores=[]),str(P),'exec'),ns);return ns
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
 def test_exact_exception_types_and_sensitive_unknown(self):
  m=model()
  for cls,label in m['EXCEPTION_TYPES'].items():
   if cls is json.JSONDecodeError:error=cls('sensitive','private document',0)
   elif cls is UnicodeDecodeError:error=cls('utf-8',b'x',0,1,'sensitive')
   else:error=cls('sensitive')
   result=m['rejection_diagnostic'](error,'accept-call');self.assertEqual(result['error_type'],label);self.assertEqual(result['stage'],'accept-call');self.assertNotIn('sensitive',json.dumps(result))
  class Foreign(ValueError):pass
  self.assertIsNone(m['rejection_diagnostic'](Foreign('secret'),'unknown')['error_type'])
 def test_real_traceback_only_accept_code_maps_stage(self):
  m=model()
  def actual():raise KeyError('secret')
  m['accept']=actual;m['ACCEPT_FAILURE_STAGES']={actual.__code__.co_firstlineno:'native-body'}
  try:actual()
  except KeyError as error:self.assertEqual(m['rejection_diagnostic'](error,'accept-call')['stage'],'native-body')
  def foreign():raise KeyError('secret')
  try:foreign()
  except KeyError as error:self.assertEqual(m['rejection_diagnostic'](error,'output')['stage'],'output')
 def test_stage_enum_unknown_is_null(self):
  m=model();self.assertIsNone(m['rejection_diagnostic'](ValueError('secret'),'password')['stage']);self.assertIsNone(m['rejection_diagnostic'](ValueError('secret'))['stage'])
if __name__=='__main__':unittest.main()

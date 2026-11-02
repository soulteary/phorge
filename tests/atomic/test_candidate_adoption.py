import copy,importlib.util,json,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('adoption',ROOT/'scripts/upstream/check_candidate_adoption.py');module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
class CandidateAdoptionTests(unittest.TestCase):
 def setUp(self):
  self.data=json.loads((ROOT/'docs/upstream/candidate-adoption.json').read_text());self.design=json.loads((ROOT/'tests/atomic/design-coverage.json').read_text())
 def test_current_snapshot_maps_all_atoms_and_original_capabilities(self):self.assertEqual(module.validate(self.data,self.design)['atoms'],134)
 def test_duplicate_atom_is_rejected(self):
  self.data['atoms'][-1]=copy.deepcopy(self.data['atoms'][0])
  with self.assertRaises(ValueError):module.validate(self.data,self.design)
 def test_deferred_adoption_and_false_full_case_certificate_are_rejected(self):
  for change in ['adoption','certificate','activation']:
   data=copy.deepcopy(self.data)
   if change=='adoption':next(a for a in data['atoms'] if a['package']=='D08')['category']='已完成'
   elif change=='certificate':data['atoms'][0]['wholeDesignedCasesCertified']=True
   else:data['constraints']['optionalFeaturesEnabled']=True
   with self.subTest(change=change),self.assertRaises(ValueError):module.validate(data,self.design)
 def test_capability_source_drift_and_dangling_atoms_are_rejected(self):
  for change in ['source','atom']:
   data=copy.deepcopy(self.data)
   if change=='source':data['capabilities'][0]['sourceRevision']='a'*40
   else:data['capabilities'][0]['atoms']=['UNKNOWN']
   with self.subTest(change=change),self.assertRaises(ValueError):module.validate(data,self.design)
 def test_retained_native_model_is_not_counted_as_implemented(self):
  row=next(a for a in self.data['atoms'] if a['id']=='M01');row['category']='保留原生'
  with self.assertRaises(ValueError):module.validate(self.data,self.design)
 def test_swapped_implementation_flags_fail_even_when_total_is_unchanged(self):
  done=next(a for a in self.data['atoms'] if a['category']=='已完成');deferred=next(a for a in self.data['atoms'] if a['category']=='暂缓')
  done['candidateChangeUnintegrated']=True;deferred['candidateChangeUnintegrated']=False
  with self.assertRaises(ValueError):module.validate(self.data,self.design)
 def test_unknown_swapped_anchor_and_wrong_delivered_package_are_rejected(self):
  for change in ['unknown','swapped','package']:
   data=copy.deepcopy(self.data);first=next(a for a in data['atoms'] if a['id']=='B01');second=next(a for a in data['atoms'] if a['id']=='F01')
   if change=='unknown':first['caseIds'][0]='UNKNOWN-A'
   elif change=='swapped':first['caseIds'],second['caseIds']=second['caseIds'],first['caseIds']
   else:first['package']=second['package']
   with self.subTest(change=change),self.assertRaises(ValueError):module.validate(data,self.design)
if __name__=='__main__':unittest.main()

"""Pure inventory/real-suite identity controls; never actual source/SQL tests."""
import ast,copy,hashlib,unittest
from pathlib import Path
import task_explicit_shard_routes_v4 as r
ROOT=Path(__file__).resolve().parents[2]
def make():
 ids=[x for pair in r.ROUTES for x in pair]+list(r.RETAINED42)+list(r.DEFAULT_BOUNDARIES)+['ordinary.Other.test_keep','test_source_operation_snapshot_extra.Other.test_future']
 buckets={}
 for ident in ids:
  module,cls,method=ident.split('.');typ=type(cls,(unittest.TestCase,),{'__module__':module,method:lambda self:None});buckets.setdefault(module,[]).append(typ(method))
 groups=[]
 for module,cases in sorted(buckets.items()):
  suite=unittest.TestSuite([unittest.TestSuite(cases)]);rows=[{'id':t.id(),'module':module}for t in cases];groups.append((module,suite,rows))
 return groups,[row for m,s,rows in groups for row in rows]
def mutate(groups,remove=None,add=None):
 out=[]
 for module,suite,rows in groups:
  cases=r._leaves(suite);cases=[t for t in cases if t.id()!=remove]
  if add and module==add.split('.')[0]:
   mod,cls,method=add.split('.');cases.append(type(cls,(unittest.TestCase,),{'__module__':mod,method:lambda self:None})(method))
  if cases:out.append((module,unittest.TestSuite(cases),[{'id':t.id(),'module':module}for t in cases]))
 return out,[row for m,s,rows in out for row in rows]
class Controls(unittest.TestCase):
 def test_exact72_mapping_and_retained42_default2(self):
  groups,full=make();out,rows,metadata=r.route_groups(groups,full);self.assertEqual(len(full)-len(rows),72);self.assertEqual(metadata['count'],72);self.assertEqual(metadata['description_count'],39);self.assertEqual(metadata['source_snapshot_count'],33)
  self.assertEqual(rows,[row for row in full if row['id']not in{a for a,b in r.ROUTES}]);self.assertTrue(r.validate_routed_inventory(rows,metadata));self.assertTrue(set(r.RETAINED42)|set(r.DEFAULT_BOUNDARIES)<={row['id']for row in rows})
 def test_all_missing_old_new_retained_default_ids_refuse(self):
  groups,full=make()
  for ident in [x for pair in r.ROUTES for x in pair]+list(r.RETAINED42)+list(r.DEFAULT_BOUNDARIES):
   with self.subTest(ident=ident),self.assertRaises(ValueError):r.route_groups(*mutate(groups,remove=ident))
 def test_extra_in_every_closed_class_or_module_refuses(self):
  groups,full=make()
  for module in {x.split('.')[0]for pair in r.ROUTES for x in pair}:
   with self.subTest(module=module),self.assertRaises(ValueError):r.route_groups(*mutate(groups,add=module+'.Unknown.test_extra'))
 def test_duplicate_and_wrong_order_refuse(self):
  groups,full=make()
  for other in(full+[full[0]],full[::-1],full[:-1]):
   with self.assertRaises(ValueError):r.route_groups(groups,other)
 def test_split_module_and_cross_module_rows_refuse(self):
  groups,full=make()
  with self.assertRaises(ValueError):r.route_groups(groups+[groups[0]],full)
  bad=copy.deepcopy(full);bad[0]['module']='foreign'
  with self.assertRaises(ValueError):r.route_groups(groups,bad)
 def test_source_suite_missing_actual_case_refuses(self):
  groups,full=make();bad=[]
  for m,s,rows in groups:bad.append((m,unittest.TestSuite([])if m==r.SOURCE_MODULE else s,rows))
  with self.assertRaises(ValueError):r.route_groups(bad,full)
 def test_retained_test_objects_and_other_suite_objects_identical(self):
  groups,full=make();out,rows,metadata=r.route_groups(groups,full);original={m:(s,rs)for m,s,rs in groups};oldcases=r._leaves(original[r.SOURCE_MODULE][0]);selected=r._leaves(next(s for m,s,rs in out if m==r.SOURCE_MODULE))
  expected=[t for t in oldcases if t.id()in set(r.RETAINED42)];self.assertEqual(selected,expected)
  self.assertTrue(all(a is b for a,b in zip(selected,expected)))
  for m,s,rs in out:
   if m!=r.SOURCE_MODULE:self.assertIs(s,original[m][0]);self.assertIs(rs,original[m][1])
 def test_custom_source_suite_and_unknown_leaf_refuse(self):
  groups,full=make()
  class Custom(unittest.TestSuite):pass
  for value in(Custom(),object()):
   bad=[(m,value if m==r.SOURCE_MODULE else s,rows)for m,s,rows in groups]
   with self.assertRaises(ValueError):r.route_groups(bad,full)
 def test_metadata_every_field_and_reintroduced_old_refuse(self):
  groups,full=make();out,rows,metadata=r.route_groups(groups,full)
  for key in metadata:
   bad=copy.deepcopy(metadata);bad[key]=None
   with self.subTest(key=key),self.assertRaises(ValueError):r.validate_routed_inventory(rows,bad)
  with self.assertRaises(ValueError):r.validate_routed_inventory(rows,{**metadata,'extra':True})
  with self.assertRaises(ValueError):r.validate_routed_inventory(rows+[{'id':r.ROUTES[0][0],'module':r.ROUTES[0][0].split('.')[0]}],metadata)
 def test_module_shards_complete_disjoint_with_original_hash_assignment(self):
  groups,full=make();out,rows,metadata=r.route_groups(groups,full);owned=[set(),set()]
  for row in rows:owned[int.from_bytes(hashlib.sha256(row['module'].encode()).digest()[:8],'big')%2].add(row['id'])
  self.assertFalse(owned[0]&owned[1]);self.assertEqual(owned[0]|owned[1],{row['id']for row in rows})
 def test_current_source_method_inventories_exact(self):
  def ids(module):
   t=ast.parse((ROOT/'tests/governance'/(module+'.py')).read_text());return {module+'.'+c.name+'.'+f.name for c in t.body if isinstance(c,ast.ClassDef)for f in c.body if isinstance(f,ast.FunctionDef)and f.name.startswith('test_')}
  self.assertEqual(ids(r.SOURCE_MODULE),set(r.RETAINED42)|{a for a,b in r.SOURCE33})
  self.assertEqual(ids('test_source_operation_snapshot_task_v2'),set(r.DEFAULT_BOUNDARIES)|{b for a,b in r.SOURCE33 if b.startswith('test_source_operation_snapshot_task_v2.')})
  self.assertEqual(ids('test_source_snapshot_assessment_task_v2'),{b for a,b in r.SOURCE33 if b.startswith('test_source_snapshot_assessment_task_v2.')})
 def test_legacy_default_factory_exact_contract_no_Task_auto_adoption(self):
  tree=ast.parse((ROOT/'scripts/governance/source_operation_snapshot.py').read_text());f=next(n for n in tree.body if isinstance(n,ast.FunctionDef)and n.name=='open_source_operation')
  self.assertEqual(len(f.body),1);self.assertEqual(ast.unparse(f.body[0].value),'SourceOperation(_KEY, root)')
 def test_legacy_default_constructor_keeps_Task_false_and_checkpoint_none(self):
  tree=ast.parse((ROOT/'scripts/governance/source_operation_snapshot.py').read_text());c=next(n for n in tree.body if isinstance(n,ast.ClassDef)and n.name=='SourceOperation');f=next(n for n in c.body if isinstance(n,ast.FunctionDef)and n.name=='__init__')
  self.assertEqual([n.arg for n in f.args.args][-2:],['task_source','expected_checkpoint']);self.assertEqual([ast.literal_eval(n)for n in f.args.defaults],[False,None])
 def test_legacy_native_recipe_branch_stays_separate_and_unweakened(self):
  tree=ast.parse((ROOT/'scripts/governance/source_operation_snapshot.py').read_text());c=next(n for n in tree.body if isinstance(n,ast.ClassDef)and n.name=='SourceOperation');f=next(n for n in c.body if isinstance(n,ast.FunctionDef)and n.name=='_assert_current_recipe')
  self.assertEqual(ast.unparse(f.body[0].value),'admission.assert_current_recipe(root, operation=self) if self._task_source else admission.assert_current_recipe(root)')
if __name__=='__main__':unittest.main()

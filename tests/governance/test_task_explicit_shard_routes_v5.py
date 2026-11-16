"""Pure synthetic inventory/identity controls only; not actual source or SQL."""
import ast,copy,unittest
from pathlib import Path
import task_explicit_shard_routes_v4 as v4
import task_explicit_shard_routes_v5 as r
from test_task_explicit_shard_routes_v4 import make as original,mutate

def make():
 groups,full=original();groups=list(groups)
 for module,ids in ((r.OLD.split('.')[0],[r.OLD,*r.RETAINED]),(r.NEW.split('.')[0],[r.NEW])):
  cases=[]
  for ident in ids:
   mod,cls,method=ident.split('.');typ=type(cls,(unittest.TestCase,),{'__module__':mod,method:lambda self:None});cases.append(typ(method))
  groups.append((module,unittest.TestSuite(cases),[{'id':t.id(),'module':module}for t in cases]))
 return groups,[row for m,s,rows in groups for row in rows]
class Controls(unittest.TestCase):
 def test_preserves_original72_exactly_and_adds_one_bijection(self):
  groups,full=make();out,rows,meta=r.route_groups(groups,full)
  self.assertEqual(meta['previous'],v4.route_metadata());self.assertEqual(meta['count'],73);self.assertEqual(meta['extension_count'],1)
  self.assertEqual(rows,[row for row in full if row['id']not in{a for a,b in v4.ROUTES}|{r.OLD}]);self.assertTrue(r.validate_routed_inventory(rows,meta))
 def test_missing_wrong_extra_and_duplicate_roles_refuse(self):
  groups,full=make()
  for ident in (r.OLD,r.NEW,*r.RETAINED):
   with self.subTest(ident=ident),self.assertRaises(ValueError):r.route_groups(*mutate(groups,remove=ident))
  for ident in (r.OLD.rsplit('.',1)[0]+'.test_unknown',r.NEW.split('.')[0]+'.Other.test_unknown'):
   with self.subTest(ident=ident),self.assertRaises(ValueError):r.route_groups(*mutate(groups,add=ident))
  with self.assertRaises(ValueError):r.route_groups(groups,full+[full[-1]])
  wrong=copy.deepcopy(full);wrong[-1]['module']='foreign'
  with self.assertRaises(ValueError):r.route_groups(groups,wrong)
 def test_original_objects_preserved_except_exact_filtered_module_suite(self):
  groups,full=make();out,rows,meta=r.route_groups(groups,full);original_by={m:(s,rs)for m,s,rs in groups}
  for m,s,rs in out:
   if m not in (v4.SOURCE_MODULE,r.OLD.split('.')[0]):self.assertIs(s,original_by[m][0]);self.assertIs(rs,original_by[m][1])
   if m==r.OLD.split('.')[0]:
    selected=v4._leaves(s);expected=[t for t in v4._leaves(original_by[m][0])if t.id()!=r.OLD]
    self.assertEqual(selected,expected);self.assertTrue(all(a is b for a,b in zip(selected,expected)))
 def test_metadata_original_baseline_and_reintroduced_old_fail(self):
  groups,full=make();out,rows,meta=r.route_groups(groups,full)
  for key in meta:
   bad=copy.deepcopy(meta);bad[key]=None
   with self.subTest(key=key),self.assertRaises(ValueError):r.validate_routed_inventory(rows,bad)
  with self.assertRaises(ValueError):r.validate_routed_inventory(rows+[{'id':r.OLD,'module':r.OLD.split('.')[0]}],meta)
  bad=copy.deepcopy(meta);bad['previous']['count']=71
  with self.assertRaises(ValueError):r.validate_routed_inventory(rows,bad)
  for key,value in (('count',73.0),('extension_count',True)):
   bad=copy.deepcopy(meta);bad[key]=value
   with self.subTest(key=key),self.assertRaises(ValueError):r.validate_routed_inventory(rows,bad)
  bad=copy.deepcopy(meta);bad['previous']['count']=72.0
  with self.assertRaises(ValueError):r.validate_routed_inventory(rows,bad)
 def test_disjoint_module_assignment_and_source_object_mismatch(self):
  groups,full=make();out,rows,meta=r.route_groups(groups,full);import hashlib
  owned=[set(),set()]
  for row in rows:owned[int.from_bytes(hashlib.sha256(row['module'].encode()).digest()[:8],'big')%2].add(row['id'])
  self.assertFalse(owned[0]&owned[1]);self.assertEqual(owned[0]|owned[1],{r['id']for r in rows})
  for target in (r.OLD.split('.')[0],r.NEW.split('.')[0]):
   bad=[(m,unittest.TestSuite([])if m==target else s,rs)for m,s,rs in groups]
   with self.subTest(target=target),self.assertRaises(ValueError):r.route_groups(bad,full)

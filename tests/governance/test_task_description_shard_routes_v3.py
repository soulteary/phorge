"""Pure inventory/union controls; synthetic DATA only, never actual test execution."""
import ast,copy,hashlib,json,unittest
import task_description_shard_routes_v3 as r
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
WORKFLOW=ROOT/'.github/workflows/atomic-feature-regressions.yml'
def make():
 buckets={}
 for old,new in r.ROUTES:
  for ident in(old,new):buckets.setdefault(ident.split('.')[0],[]).append({'id':ident,'module':ident.split('.')[0]})
 # Similar prefixes are deliberately ordinary tests, never broad exclusions.
 for module in('test_project_description_new_future','test_project_description_main_integration_extra','test_unrelated'):
  buckets[module]=[{'id':module+'.Ordinary.test_unchanged','module':module}]
 groups=[(m,object(),rows)for m,rows in sorted(buckets.items())]
 return groups,[row for m,s,rows in groups for row in rows]
def inline_blocks(text,marker):
 rows=text.splitlines();out=[]
 for i,line in enumerate(rows):
  if line.strip()=="python3 - <<'"+marker+"'":
   end=next(j for j in range(i+1,len(rows))if rows[j].strip()==marker)
   out.append('\n'.join(row[8:]for row in rows[i+1:end])+'\n')
 return out
def union_namespace():
 block=inline_blocks(WORKFLOW.read_text(),'PYUNION')[0]
 tree=ast.parse(block);names={'need','digest','owner','validate'};selected=[n for n in tree.body if isinstance(n,ast.FunctionDef)and n.name in names]
 keys=next(ast.literal_eval(n.value)for n in tree.body if isinstance(n,ast.Assign)and any(isinstance(t,ast.Name)and t.id=='KEYS'for t in n.targets))
 ns={'hashlib':hashlib,'json':json,'KEYS':keys,'validate_routed_inventory':r.validate_routed_inventory}
 exec(compile(ast.Module(body=selected,type_ignores=[]),'current-owned-pure-union-validate','exec'),ns);return ns

def summary(index):
 groups,full=make();groups,full,routes=r.route_groups(groups,full);u=union_namespace();assigned=[row['id']for row in full if u['owner'](row['module'])==index]
 return {'schema':'atomic-governance-unit-shard-v4','metadata':{'synthetic':'not-a-run'},'partition':'module-sha256-explicit72-v1','task_routes':routes,'shard':index,'shards':2,'full':full,'full_digest':u['digest'](full),'assigned':assigned,'assigned_digest':u['digest'](assigned),'results':[{'id':ident,'status':'ok'}for ident in assigned],'test_count':len(assigned),'skipped_ids':[],'protocol_errors':0,'failure_count':0,'error_count':0,'refusal_stage':None,'passed':True}
class Controls(unittest.TestCase):
 def test_closed39_exact_original_objects_and_order(self):
  groups,full=make();before=copy.deepcopy(full);selected,rows,metadata=r.route_groups(groups,full);old={a for a,b in r.ROUTES}
  self.assertEqual(len(old),39);self.assertEqual(len(full)-len(rows),39);self.assertEqual(full,before)
  self.assertEqual(rows,[row for row in full if row['id']not in old])
  for group in selected:self.assertTrue(any(group is original for original in groups))
  self.assertTrue(r.validate_routed_inventory(rows,metadata))
 def test_missing_each_old_or_new_refuses(self):
  groups,full=make()
  for ident in[a for pair in r.ROUTES for a in pair]:
   modified=[(m,s,[row for row in rows if row['id']!=ident])for m,s,rows in groups]
   with self.subTest(ident=ident),self.assertRaises(ValueError):r.route_groups(modified,[row for m,s,rows in modified for row in rows])
 def test_extra_case_each_closed_module_refuses(self):
  groups,full=make()
  for old,new,count in r.MODULE_PAIRS:
   for module in(old,new):
    modified=[(m,s,rows+[{'id':m+'.Unknown.test_added','module':m}]if m==module else rows)for m,s,rows in groups]
    with self.subTest(module=module),self.assertRaises(ValueError):r.route_groups(modified,[row for m,s,rows in modified for row in rows])
 def test_duplicate_identity_refuses(self):
  groups,full=make();m,s,rows=groups[0];groups[0]=(m,s,rows+[rows[0]])
  with self.assertRaises(ValueError):r.route_groups(groups,[row for m,s,rows in groups for row in rows])
 def test_split_module_refuses(self):
  groups,full=make();groups.append(groups[0])
  with self.assertRaises(ValueError):r.route_groups(groups,full)
 def test_full_order_or_missing_ordinary_refuses(self):
  groups,full=make()
  for other in(full[::-1],full[:-1]):
   with self.assertRaises(ValueError):r.route_groups(groups,other)
 def test_future_similarly_named_modules_remain(self):
  groups,full=make();_,rows,_=r.route_groups(groups,full)
  self.assertEqual(sum(row['module'].endswith(('_future','_extra'))for row in rows),2)
 def test_changed_method_or_class_refuses(self):
  groups,full=make();bad=copy.deepcopy(full);bad[0]['id']+='X'
  with self.assertRaises(ValueError):r._closed_inventory(bad,False)
 def test_old_rows_cannot_reenter_routed_inventory(self):
  groups,full=make();_,rows,metadata=r.route_groups(groups,full);old=r.ROUTES[0][0]
  with self.assertRaises(ValueError):r.validate_routed_inventory(rows+[{'id':old,'module':old.split('.')[0]}],metadata)
 def test_routing_metadata_each_field_refuses(self):
  groups,full=make();_,rows,metadata=r.route_groups(groups,full)
  for key,value in [('schema','old'),('count',38),('mapping',metadata['mapping'][:-1]),('mapping_sha256','0'*64),('extra',True)]:
   bad=copy.deepcopy(metadata);bad[key]=value
   with self.subTest(key=key),self.assertRaises(ValueError):r.validate_routed_inventory(rows,bad)
 def test_both_shards_disjoint_complete_and_whole_module(self):
  u=union_namespace();a=summary(0);b=summary(1);self.assertEqual(a['full'],b['full'])
  self.assertFalse(set(a['assigned'])&set(b['assigned']));self.assertEqual(set(a['assigned'])|set(b['assigned']),{row['id']for row in a['full']})
  for index,data in enumerate((a,b)):self.assertEqual(u['validate'](data,index,data['metadata'])[1],data['assigned'])
 def test_union_every_original_failure_gate_remains(self):
  u=union_namespace();data=summary(0)
  mutations=[('passed',False),('skipped_ids',[data['assigned'][0]]),('protocol_errors',1),('failure_count',1),('error_count',1),('refusal_stage','failed'),('test_count',True),('test_count',len(data['assigned'])-1),('shard',True),('shards',3),('partition','module-sha256-v1'),('schema','atomic-governance-unit-shard-v2'),('results',data['results'][:-1]),('results',data['results'][::-1]),('assigned',data['assigned'][::-1]),('assigned_digest','0'*64),('full_digest','0'*64),('task_routes',{}),('metadata',{})]
  for key,value in mutations:
   bad=copy.deepcopy(data);bad[key]=value
   with self.subTest(key=key),self.assertRaises((RuntimeError,ValueError)):u['validate'](bad,0,data['metadata'])
 def test_standalone_routes_exact_same39(self):
  groups,full=make();cases=[]
  for row in full:
   module,cls,method=row['id'].split('.');typ=type(cls,(unittest.TestCase,),{'__module__':module,method:lambda self:None});cases.append(typ(method))
  actual=r.route_test_cases(cases);_,rows,_=r.route_groups(groups,full)
  self.assertEqual([x.id()for x in actual],[row['id']for row in rows])
 def test_both_current_inline_shards_identical_and_all_execution_guards_present(self):
  blocks=inline_blocks(WORKFLOW.read_text(),'PYUNIT');self.assertEqual(len(blocks),2);self.assertEqual(blocks[0],blocks[1])
  tree=ast.parse(blocks[0]);functions={n.name:n for n in tree.body if isinstance(n,(ast.FunctionDef,ast.ClassDef))}
  for name in ('need','digest','owner','inspect_suite','discover_plan','RecordedResult','metadata','main'):self.assertIn(name,functions)
  # Current source still discovers all tests, denies custom hooks and suites,
  # and executes the original wholemodule groups after explicitclosed routing.
  text=ast.unparse(tree)
  for obligation in ("pattern='test_*.py'",'loader_errors','custom_suite_unsupported','custom_root_suite_unsupported','cross_module_wrapper_unsupported','split_module_wrapper_unsupported','load_tests_hook_unsupported','route_groups(groups, full)',"'assigned_tests_failed_or_incomplete'",'not result.skipped','result.protocol_errors == 0','result.testsRun == len(assigned)','result.events ==',"'status': 'ok'",'unittest.TestSuite(selected)'):
   self.assertIn(obligation,text)
 def test_truthful_current_metadata_keeps_workflow_context_and_external_source_distinct(self):
  blocks=inline_blocks(WORKFLOW.read_text(),'PYUNIT')+inline_blocks(WORKFLOW.read_text(),'PYUNION');self.assertEqual(len(blocks),3)
  for block in blocks:
   tree=ast.parse(block);function=next(n for n in tree.body if isinstance(n,ast.FunctionDef)and n.name in ('metadata','expected_metadata'))
   dictionaries=[n for n in ast.walk(function)if isinstance(n,ast.Dict)and any(isinstance(k,ast.Constant)and k.value=='workflow_sha'for k in n.keys)]
   self.assertEqual(len(dictionaries),1);values={k.value:ast.unparse(v)for k,v in zip(dictionaries[0].keys,dictionaries[0].values)}
   self.assertEqual(values['workflow_sha'],"os.environ.get('GITHUB_SHA', '')")
   self.assertEqual(values['head_sha'],"os.environ.get('ATOMIC_EXPECTED_HEAD_SHA', '')")
   self.assertEqual(values['source_revision'],"git('rev-parse', 'HEAD')")
   comparisons=[n for n in ast.walk(function)if isinstance(n,ast.Compare)]
   self.assertTrue(any(ast.unparse(n)=="m['source_revision'] == m['head_sha']"for n in comparisons))
   self.assertFalse(any("m['workflow_sha']"in ast.unparse(n)and"m['source_revision']"in ast.unparse(n)for n in comparisons))
   for field in ('workflow_sha','head_sha','source_revision','source_tree'):self.assertIn(field,values)
   self.assertIn("'[0-9a-f]{40}'",ast.unparse(function))
 def test_current_union_keeps_same_run_complete_disjoint_inventory(self):
  blocks=inline_blocks(WORKFLOW.read_text(),'PYUNION');self.assertEqual(len(blocks),1);tree=ast.parse(blocks[0]);text=ast.unparse(tree)
  for obligation in ('expected_metadata()',"data['metadata'] == metadata","validate_routed_inventory(full, data['task_routes'])","summaries[0]['full'] == summaries[1]['full']",'exact_disjoint_union','same_complete_discovery','no_skip_or_refusal','no_errors','deterministic_assignment','every_assigned_once_ok_in_original_order'):
   self.assertIn(obligation,text)
 def test_all39_current_target_methods_resolve_directly_or_exact_existing_inheritance(self):
  modules={}
  for old,new,count in r.MODULE_PAIRS:
   for name in(old,new):
    tree=ast.parse((ROOT/'tests/governance'/(name+'.py')).read_bytes())
    modules[name]={node.name:node for node in tree.body if isinstance(node,ast.ClassDef)}
  for old,new in r.ROUTES:
   old_module,old_class,old_method=old.split('.');new_module,new_class,new_method=new.split('.')
   self.assertEqual(old_method,new_method)
   original=modules[old_module][old_class];target=modules[new_module][new_class]
   self.assertIn(old_method,{n.name for n in original.body if isinstance(n,ast.FunctionDef)})
   methods={n.name for n in target.body if isinstance(n,ast.FunctionDef)}
   if new_method not in methods:
    self.assertEqual(new_module,'test_project_description_current_consumers_task_v2')
    self.assertEqual(new_class,'TaskCurrentRecipeConsumerClosure')
    self.assertEqual([ast.unparse(base)for base in target.bases],['legacy_module.CurrentRecipeConsumerClosure'])
if __name__=='__main__':unittest.main()

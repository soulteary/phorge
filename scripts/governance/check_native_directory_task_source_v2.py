#!/usr/bin/env python3
"""Explicit current Task source inventory; static reference only, never a permit.
The old CLI/manifest are unchanged. Two metadata-read seams admit only explicit
exact reviewed file-version deltas; the single inventory seam and CURRENT
class/JX text checks remain unchanged.
"""
import argparse,ast,copy,hashlib,json,types
from pathlib import Path
import source_operation_snapshot as source
ROOT=Path(__file__).resolve().parents[2]
GATE='scripts/governance/check_native_directory.py'
MANIFEST='docs/governance/native-directory-callers.json'
GATE_SHA='961bd869db320e7d34a9f7cbdb854f85151dff0a2feeef28331525909ef7b0fb'
MANIFEST_SHA='1deb47e0184ae4cafc525de506f673ea3a6186e0f667ccfd9e973cfc6cbbb2b1'
METADATA_DELTAS={'src/applications/people/governance/auth/PhorgeGovernanceNativeIdentitySource.php': ('0a26b43c0462773969098d78616136a7ec0c2323d98511542d85e828f0103922', '3f57b08aedd111158bc12b56fb13398e7de22f29a512244c7ec6b13f5ff23402'), 'src/infrastructure/governance/execution/PhorgeProjectConfigEpochScope.php': ('64d0b0ca41b7fe557a12b5c7df33c3a1c5ad45d001386312e813b3353c7c26c4', '6003325bbb90f4b544d79955372457679ece96baec265dc2546c1f9335dfa70b'), 'src/infrastructure/governance/media/PhorgeGovernanceMediaExchangeService.php': ('9afb37b7d3b409333dfd01f614f85423ecd1192d795dffe1999cb0a5f8f3c7b0', 'de96d90606515128ff310f01a614db122d877b7df2a496d50d204ab371eea163'), 'src/infrastructure/governance/notification/PhorgePrivateMailPolicy.php': ('67112136ba242de5c8ada4b22ee07958722fcf3fb93b34dabf08bc353364a361', '22ea72b678bddb26058b8268903c4a1c8a1cc000655a63f0db0377178b4e22d9'), 'src/applications/metamta/storage/PhabricatorMetaMTAMail.php': ('56016d144982aa284ac0afc2fd272f65a785af26d85c502782d45fc3cfa07c28', 'f2340c51cb66a17c841f8d17f5e1ba425d4294a7abfb5d376c2130113c186212'), 'src/infrastructure/governance/consistency/PhorgeGovernanceNativeReadView.php': ('bac094310783c6b05625e8bb1805bebaeecec15dc103b470f7caabbe983e1aa5', 'de81f9c5042ca94247404013d171aa8e34f5cb837809c45b335c0516052aa95e'), 'src/infrastructure/governance/contracts/PhorgeGovernanceContentDecision.php': ('f3eb13b1a434219590c75f7fc331cc535fa13cf191c428d28acc8ca47b75a995', '26282032e65b10161dc4411c9741b1e58c82dd8d715c3865446430f7fc6c41ab'), 'src/infrastructure/governance/mapping/PhorgeGovernanceCapabilityMap.php': ('e7f8c2a843670d65184ac5d92613f00bfe591c0f334b3b099689dbf6626e3d9c', 'c918275a4bd8206be4cc7a2a11aed21a3f511642af78be67f2ec46566c825e30'), 'src/infrastructure/governance/native/PhorgeGovernanceNativePolicyCompositionVerifier.php': ('a503f3088502537e7e6881e75d0db1eebf0ec6f1b7ecda29b4ecd4b7fed8261f', '1593552b62b360a76ead59a4684660c8eb591e1674770ab8cea7389667358d31'), 'src/infrastructure/governance/native/PhorgeGovernanceNativeRuleHierarchyVerifier.php': ('21c8b69d97967d43f23103d80178493f7ab66eb45f2b1ab4e3739f135125a575', 'cb79b634229603a1cc169d81f1af90f1fee39062091f22415df637d38242bb53'), 'src/infrastructure/governance/native/PhorgeGovernanceNativeSourceReader.php': ('affcc1461b7591e922c5cd93c48132dff0868925defd8d3ba0ecd649a4a11344', 'f6e05760da5be99fd8875be4e5b337fda63b60d360f1387dccee4a3e022e7e16'), 'src/infrastructure/governance/observation/PhorgeGovernanceNativeObservationVerifier.php': ('21ae153a8a3cde5236e6a860b92ab46c80054373589311a08bf39653e70a00ef', 'ad7a271cfbbbdd2f0b407a20432f5ad377ec462421a8ca9da28566703a0245cb'), 'src/infrastructure/governance/readset/PhorgeGovernanceReadSetValidator.php': ('c30cec49d1c56fd981dbc290fd0ae1900b7bc594eabb4b2b1e42c8445e2570bb', 'ce0f5cead1b2d9e7233c9a46a42ff580cdaa12b74856a9396b66c94c0657ae2b'), 'src/infrastructure/governance/media/http/PhorgeGovernanceMediaFileGuard.php': ('79afd0eb6661c0665d5af8aeba10080ac5accb9244dc33a647a91b91217f0379', 'a8679638a240c7bb436afe46cd1ae46c9311ab6d8b8596276384d8dec30332d4')}
TASK_RUNTIME=frozenset(('src/infrastructure/governance/content/PhorgeTaskContentApprovalBinding.php','src/infrastructure/governance/content/PhorgeTaskContentGrantCommitProof.php','src/infrastructure/governance/content/PhorgeTaskContentGrantConfigOptions.php','src/infrastructure/governance/content/PhorgeTaskContentGrantConfiguration.php','src/infrastructure/governance/content/PhorgeTaskContentGrantPlan.php','src/infrastructure/governance/content/PhorgeTaskContentGrantRequest.php','src/infrastructure/governance/content/PhorgeTaskContentGrantSchemaSpec.php','src/infrastructure/governance/content/PhorgeTaskContentGrantService.php','src/infrastructure/governance/content/PhorgeTaskContentGrantStore.php','src/infrastructure/governance/content/PhorgeTaskContentGrantValue.php','src/infrastructure/governance/content/PhorgeTaskContentGrantWriteScope.php','src/infrastructure/governance/content/PhorgeTaskContentSource.php','src/infrastructure/governance/execution/PhorgeTaskGrantExecutionConfigSource.php'))
HASH_EXPRESSIONS=('path.read_bytes()','file(root, raw).read_bytes()')
INVENTORY_EXPRESSION="sorted(str(path.relative_to(root)) for prefix in ROOTS for path in (root / prefix).rglob('*.php') if '__tests__' not in path.parts)"
def need(value):
 if value is not True:raise ValueError('Task_native_directory_static_source_refused')
def sha(raw):return hashlib.sha256(raw).hexdigest()
def _manifest_data(raw):
 need(type(raw)is bytes and len(raw)<=262144 and sha(raw)==MANIFEST_SHA);return raw
def _metadata_data(relative,current,parent):
 # Pure historical DATA only. _metadata_bytes requires a genuine TaskContext,
 # which already admits the complete three-file proxy security cohort.
 need(type(relative)is str and type(current)is bytes and type(parent)is bytes)
 if current!=parent:
  need(relative in METADATA_DELTAS)
  old,new=METADATA_DELTAS[relative];need(sha(parent)==old and sha(current)==new)
 return parent

def _inventory_data(actual,captured,old_names,extra_names,additional):
 need(type(actual)is list and type(captured)is list and type(old_names)is list)
 need(len(actual)==len(set(actual))and len(captured)==len(set(captured))and len(old_names)==len(set(old_names)))
 need(set(extra_names)==set(TASK_RUNTIME)and set(additional)==set(TASK_RUNTIME))
 need(set(actual)==set(captured)==set(old_names)|set(TASK_RUNTIME)and not(set(old_names)&set(TASK_RUNTIME)))
 for name,(raw,parent,pin)in additional.items():
  need(type(raw)is bytes and parent is None and type(pin)is dict and set(pin)=={'bytes','sha256'}and type(pin['bytes'])is int and len(raw)==pin['bytes']and sha(raw)==pin['sha256'])
 return sorted(set(actual)-set(TASK_RUNTIME))
def _require(operation,root):
 root=Path(root);source.require_task_operation(operation,root)
 need(root==ROOT and operation.code_root==ROOT and operation.producer_root==ROOT and source.ROOT==ROOT)
 return operation._task_contexts[root]
def _metadata_bytes(operation,root,path):
 root=Path(root);path=Path(path);context=_require(operation,root)
 need(path.is_absolute()and path.is_relative_to(root)and path.resolve()==path)
 relative=str(path.relative_to(root));return _metadata_data(relative,context.current(relative),context.parent(relative))
def _runtime_inventory(operation,root,roots,old_names):
 context=_require(operation,root)
 actual=sorted(str(path.relative_to(root))for prefix in roots for path in(root/prefix).rglob('*.php')if '__tests__'not in path.parts)
 captured=sorted(name for name,row in context.entries.items()if name.endswith('.php')and any(name.startswith(prefix+'/')for prefix in roots)and '__tests__'not in Path(name).parts)
 additional={}
 for name in TASK_RUNTIME:
  need(name in context.entries and context.entries[name]['kind']=='file')
  additional[name]=(context.current(name),context.parent(name),source.task_admission.TASK_NEW_PINS[name])
 extras={name for name in captured if context.parent(name)is None}
 return _inventory_data(actual,captured,old_names,extras,additional)

class TypedSeams(ast.NodeTransformer):
 def __init__(self):self.reads=[];self.inventory=0
 def visit_Call(self,node):
  node=self.generic_visit(node)
  text=ast.unparse(node)
  if text in HASH_EXPRESSIONS:
   self.reads.append(text)
   return ast.copy_location(ast.Call(func=ast.Name(id='_metadata_bytes',ctx=ast.Load()),args=[ast.Name(id='operation',ctx=ast.Load()),ast.Name(id='root',ctx=ast.Load()),node.func.value],keywords=[]),node)
  return node
 def visit_Assign(self,node):
  node=self.generic_visit(node)
  if len(node.targets)==1 and isinstance(node.targets[0],ast.Name)and node.targets[0].id=='actual':
   need(ast.dump(node.value,include_attributes=False)==ast.dump(ast.parse(INVENTORY_EXPRESSION,mode='eval').body,include_attributes=False))
   self.inventory+=1
   node.value=ast.Call(func=ast.Name(id='_runtime_inventory',ctx=ast.Load()),args=[ast.Name(id='operation',ctx=ast.Load()),ast.Name(id='root',ctx=ast.Load()),ast.Name(id='ROOTS',ctx=ast.Load()),ast.Name(id='EXPECTED_RUNTIME_FILES',ctx=ast.Load())],keywords=[])
  return node

def current_tree(raw):
 need(type(raw)is bytes and sha(raw)==GATE_SHA)
 tree=ast.parse(raw);f=[n for n in tree.body if isinstance(n,ast.FunctionDef)and n.name=='validate'];need(len(f)==1)
 original=copy.deepcopy(f[0]);seams=TypedSeams();seams.visit(f[0]);need(seams.reads==list(HASH_EXPRESSIONS)and seams.inventory==1)
 f[0].args.kwonlyargs.append(ast.arg(arg='operation'));f[0].args.kw_defaults.append(None)
 f[0].body.insert(0,ast.Expr(value=ast.Call(func=ast.Name(id='_require',ctx=ast.Load()),args=[ast.Name(id='operation',ctx=ast.Load()),ast.Name(id='root',ctx=ast.Load())],keywords=[])))
 need(len(f[0].body)==len(original.body)+1)
 class UndoSeams(ast.NodeTransformer):
  def visit_Call(self,node):
   node=self.generic_visit(node)
   if isinstance(node.func,ast.Name)and node.func.id=='_metadata_bytes':
    need(len(node.args)==3 and not node.keywords)
    return ast.copy_location(ast.Call(func=ast.Attribute(value=node.args[2],attr='read_bytes',ctx=ast.Load()),args=[],keywords=[]),node)
   return node
  def visit_Assign(self,node):
   node=self.generic_visit(node)
   if len(node.targets)==1 and isinstance(node.targets[0],ast.Name)and node.targets[0].id=='actual':node.value=ast.parse(INVENTORY_EXPRESSION,mode='eval').body
   return node
 restored=copy.deepcopy(f[0]);restored.body.pop(0);restored.args.kwonlyargs.pop();restored.args.kw_defaults.pop();restored=UndoSeams().visit(restored)
 need(ast.dump(restored,include_attributes=False)==ast.dump(original,include_attributes=False))
 return ast.fix_missing_locations(tree),{'metadata_read_seams':len(seams.reads),'runtime_inventory_seams':seams.inventory}

def verify_in_operation(operation,root):
 root=Path(root);context=_require(operation,root)
 gate=context.current(GATE);manifest=_manifest_data(context.current(MANIFEST))
 tree,seams=current_tree(gate)
 ns={'__name__':'Task_CURRENT_native_directory_static','__file__':str(root/GATE),'_require':_require,'_metadata_bytes':_metadata_bytes,'_runtime_inventory':_runtime_inventory}
 exec(compile(tree,str(root/GATE),'exec'),ns)
 data=json.loads(manifest,object_pairs_hook=ns['unique_object'])
 result=ns['validate'](data,root,operation=operation)
 need(result['passed']is True and result['activation_blocked']is True and result['authority_proof']is False and result['runtime_certified']is False)
 return {'schema':'native-directory-current-Task-static-source-v2','original_parent_manifest_inventory':result,'original_parent_manifest_sha256':sha(manifest),'current_gate_sha256':sha(gate),'current_source_checkpoint':operation._task_expected_checkpoint,'additional_Task_runtime_files':sorted(TASK_RUNTIME),'current_runtime_files':result['runtime_files']+len(TASK_RUNTIME),'additional_Task_behavior_certified':False,'current_source_entry_verified':True,'source_operation_exit_verified':False,'seams':seams,'SQL_executed':False,'runtime_certified':False,'authority_proof':False,'activation_blocked':True}
def verify(root,checkpoint):
 with source.open_task_source_operation(root,expected_checkpoint=checkpoint)as operation:result=verify_in_operation(operation,root)
 result['source_operation_exit_verified']=True;return result
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--root',default=str(ROOT));p.add_argument('--expected-checkpoint',required=True);a=p.parse_args()
 try:print(json.dumps(verify(Path(a.root),a.expected_checkpoint),sort_keys=True))
 except Exception:print('{"passed":false,"SQL_executed":false,"runtime_certified":false,"authority_proof":false,"activation_blocked":true}');raise SystemExit(1)

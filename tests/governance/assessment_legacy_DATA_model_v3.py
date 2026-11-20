"""CURRENT-code legacy DATA model, never a producer/SourceOperation/default API.
Fixture bytes are read by unchanged current strict reader/cohort validators.
"""
import ast,builtins,hashlib,inspect,json,os,stat,sys,types
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import source_operation_snapshot as source
from assessment_task_test_api_v2 import opened
SELF='tests/governance/assessment_legacy_DATA_model_v3.py'
GRAPH=('project_description_source_delta','project_description_admission_delta','project_content_grant_source_delta','project_content_grant_ci_source_delta','recovery_oauth_client_lifecycle_media_provider_delta','recovery_oauth_client_lifecycle_media_warning_delta','recovery_oauth_client_lifecycle_media_delta','recovery_oauth_client_lifecycle_delta','recovery_oauth_authorization_source_delta','recovery_partial_current_schema_delta','recovery_oauth_code_ci_delta','recovery_oauth_code_source_delta','recovery_oauth_code_coordinator_delta','recovery_worker_terminal_delta','recovery_assessment_source_delta','recovery_oauth_source_delta','recovery_conduit_source_delta')
ASSESSMENT='check_recovery_assessment_credentials_v2'
EXPECTED_FUNCTIONS=(('check_recovery_assessment_credentials_v2', ('SQL_obligations', 'read_source_checked', 'source_view')), ('project_content_grant_source_delta', ('media_source_delta', 'reduce_if_selected')), ('project_description_admission_delta', ('_admitted_bytes', '_constants', '_editor_history', '_exact', '_normalize_metadata', '_normalize_production', '_original_manifest', '_target_bytes', 'assert_current_recipe', 'expected_manifest', 'manifest_bytes', 'native_pair_parent_DATA', 'need', 'reduce_if_selected', 'sha')), ('project_description_source_delta', ('canonical_constant', 'checked_bytes', 'constants', 'current_snapshot', 'exact', 'reconstruct_native_editor', 'reconstruct_native_state', 'refuse', 'sha', 'validate_snapshot')), ('recovery_conduit_source_delta', ('reduce_calendar_wire_union', 'reduce_policy_cleanup_driver')), ('recovery_oauth_authorization_source_delta', ('assert_cohort', 'reduce_if_selected')), ('recovery_oauth_client_lifecycle_delta', ('reduce_if_selected',)), ('recovery_oauth_client_lifecycle_media_delta', ('reduce_if_selected',)), ('recovery_oauth_client_lifecycle_media_provider_delta', ('reduce_if_selected',)), ('recovery_oauth_client_lifecycle_media_warning_delta', ('reduce_if_selected',)), ('recovery_oauth_code_ci_delta', ('reduce_if_selected',)), ('recovery_oauth_code_coordinator_delta', ('assert_cohort', 'source_view')), ('recovery_oauth_code_source_delta', ('assert_cohort', 'reduce_current', 'reduce_if_selected')), ('recovery_partial_current_schema_delta', ('assert_cohort', 'reduce_if_selected')), ('recovery_worker_terminal_delta', ('reduce_current', 'reduce_if_selected')))
STDLIB=frozenset(('argparse','base64','hashlib','importlib','json','os','pathlib','re','stat','subprocess','sys','types','zlib'))
def need(value):
 if value is not True:raise ValueError('Assessment_legacy_DATA_model_refused')
def sha(raw):return hashlib.sha256(raw).hexdigest()

def model_tree(name,raw):
 """Only four fixture-root seams; exact inverse restores complete CURRENT AST."""
 need(type(name)is str and name in GRAPH+(ASSESSMENT,)and type(raw)is bytes and 0<len(raw)<=2097152)
 tree=ast.parse(raw);need(not any(isinstance(n,ast.Name)and n.id=='_MODEL_FIXTURE_ROOT'for n in ast.walk(tree)));original=ast.dump(tree,include_attributes=False);changes=[]
 if name=='project_description_admission_delta':
  f=next(n for n in tree.body if isinstance(n,ast.FunctionDef)and n.name=='assert_current_recipe')
  need([a.arg for a in f.args.args]==['root','operation']and len(f.args.defaults)==2 and ast.unparse(f.args.defaults[0])=='ROOT')
  changes.append((f.args.defaults,0,f.args.defaults[0]));f.args.defaults[0]=ast.Name(id='_MODEL_FIXTURE_ROOT',ctx=ast.Load())
  for fname,callee,count in(('_normalize_metadata','manifest_bytes',1),('_normalize_production','description.current_snapshot',2)):
   f=next(n for n in tree.body if isinstance(n,ast.FunctionDef)and n.name==fname);found=[]
   for node in ast.walk(f):
    if isinstance(node,ast.Call)and ast.unparse(node.func)==callee and len(node.args)==1 and ast.unparse(node.args[0])=='ROOT'and not node.keywords:found.append(node)
   need(len(found)==count)
   for node in found:changes.append((node.args,0,node.args[0]));node.args[0]=ast.Name(id='_MODEL_FIXTURE_ROOT',ctx=ast.Load())
  need(len(changes)==4)
 mutated=ast.dump(tree,include_attributes=False)
 for seq,index,value in changes:seq[index]=value
 need(ast.dump(tree,include_attributes=False)==original)
 # Reapply only the exact four reviewed AST locations.
 if changes:
  for seq,index,value in changes:seq[index]=ast.Name(id='_MODEL_FIXTURE_ROOT',ctx=ast.Load())
 need(ast.dump(tree,include_attributes=False)==mutated)
 ast.fix_missing_locations(tree);return tree,len(changes)

def close_functions(definitions,lambdas,module_aliases):
 # Close ONLY the actual Assessment label-validator function dependency graph.
 # No arbitrary all-helper execution and no caller-selected function roots.
 required=set();pending=[(ASSESSMENT,'SQL_obligations')]
 while pending:
  name,symbol=pending.pop()
  if(name,symbol)in required:continue
  need(symbol in definitions[name]or symbol in lambdas[name]);required.add((name,symbol))
  node=definitions[name].get(symbol,lambdas[name].get(symbol))
  aliases=dict(module_aliases[name]);local_functions={}
  for assignment in ast.walk(node):
   if isinstance(assignment,ast.Assign)and len(assignment.targets)==1 and isinstance(assignment.targets[0],ast.Name)and isinstance(assignment.value,ast.Name):
    target=assignment.targets[0].id;origin=assignment.value.id
    if origin in aliases:aliases[target]=aliases[origin]
    if origin in definitions[name]:local_functions[target]=origin
  for call in ast.walk(node):
   if not isinstance(call,ast.Call):continue
   f=call.func
   if isinstance(f,ast.Name):
    target=local_functions.get(f.id,f.id)
    if target in definitions[name]or target in lambdas[name]:pending.append((name,target))
   elif isinstance(f,ast.Attribute)and isinstance(f.value,ast.Name)and f.value.id in aliases:
    target=aliases[f.value.id];need(f.attr in definitions[target]or f.attr in lambdas[target]);pending.append((target,f.attr))
 return required

def assessment_nodes(tree,allowed_modules,selected_functions):
 expected_imports=('import recovery_oauth_code_coordinator_delta as code_coord','import argparse, hashlib, json, os, re, stat','import recovery_worker_terminal_delta as worker_terminal','import recovery_conduit_source_delta as calendar_delta','from pathlib import Path','import check_completion as completion','import check_native_organization as native','import check_recovery_partial_composition as shared','import source_operation_snapshot as source_operation')
 imports=[n for n in tree.body if isinstance(n,(ast.Import,ast.ImportFrom))]
 need(tuple(ast.unparse(n)for n in imports)==expected_imports)
 assignments=[n for n in tree.body if isinstance(n,ast.Assign)]
 need(len(assignments)==4 and all(len(n.targets)==1 and isinstance(n.targets[0],ast.Name)for n in assignments))
 need({n.targets[0].id for n in assignments}=={'ROOT','_CODE_RECIPE_ROOT','SOURCE_INPUTS','ADAPTER_SOURCE_INPUTS'})
 root_nodes=[n for n in assignments if n.targets[0].id in('ROOT','_CODE_RECIPE_ROOT')]
 need([ast.unparse(n)for n in root_nodes]==['ROOT = Path(__file__).resolve().parents[2]','_CODE_RECIPE_ROOT = Path(__file__).resolve(strict=True).parents[2]'])
 literal_nodes=[n for n in assignments if n.targets[0].id in('SOURCE_INPUTS','ADAPTER_SOURCE_INPUTS')]
 for node in literal_nodes:need(type(ast.literal_eval(node.value))is dict and bool(ast.literal_eval(node.value)))
 import_nodes=[];omitted=set()
 omissions={'check_completion','check_native_organization','check_recovery_partial_composition','source_operation_snapshot'}
 for node in imports:
  if isinstance(node,ast.Import):
   keep=[]
   for item in node.names:
    if item.name in omissions:omitted.add(item.name)
    else:need(item.name in allowed_modules or item.name.split('.')[0]in STDLIB);keep.append(item)
   if keep:import_nodes.append(ast.Import(names=keep))
  else:need(node.level==0 and node.module=='pathlib');import_nodes.append(node)
 need(omitted==omissions)
 nodes=import_nodes+root_nodes+literal_nodes+selected_functions
 ast.fix_missing_locations(ast.Module(body=nodes,type_ignores=[]));return nodes

def frozen_equal(value,literal):
 if type(literal)is dict:
  return type(value)in(dict,types.MappingProxyType)and set(value)==set(literal)and all(frozen_equal(value[k],v)for k,v in literal.items())
 if type(literal)in(list,tuple):return type(value)is tuple and len(value)==len(literal)and all(frozen_equal(a,b)for a,b in zip(value,literal))
 if type(literal)is set:return type(value)is frozenset and value==literal
 return type(value)is type(literal)and value==literal

def _E_stamp(s):return(s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid,s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
def capture_E(root):
 # Observation only: no equality to authorized parent rows, no normalization.
 # Deliberately corrupt inputs remain corrupt for original current validators.
 result={};total=0
 def record(path):
  nonlocal total
  relative=str(path.relative_to(root));before=path.lstat();value={'identity':_E_stamp(before)}
  if stat.S_ISLNK(before.st_mode):value['target']=os.readlink(path)
  elif stat.S_ISREG(before.st_mode):
   need(0<=before.st_size<=16777216);total+=before.st_size;need(total<=67108864)
   fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
   try:
    digest=hashlib.sha256();offset=0
    while offset<before.st_size:
     part=os.read(fd,min(65536,before.st_size-offset));need(bool(part));digest.update(part);offset+=len(part)
    need(os.read(fd,1)==b''and _E_stamp(before)==_E_stamp(os.fstat(fd))==_E_stamp(path.lstat()));value['sha256']=digest.hexdigest()
   finally:os.close(fd)
  else:need(stat.S_ISDIR(before.st_mode))
  result[relative]=value;need(len(result)<=12000)
 record(root)
 def walk_error(error):raise error
 for base,dirs,files in os.walk(root,followlinks=False,onerror=walk_error):
  for name in dirs+files:
   path=Path(base)/name;record(path)
   if path.is_symlink()and name in dirs:dirs.remove(name)
 return result

def _fixture(root):
 root=Path(root);need(root.is_absolute()and root.resolve()==root and root!=ROOT and not root.is_relative_to(ROOT)and not ROOT.is_relative_to(root))
 s=root.lstat();need(stat.S_ISDIR(s.st_mode)and stat.S_IMODE(s.st_mode)==0o700 and s.st_uid==os.geteuid())
 return root,(s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid)
def _identity(root):
 s=root.lstat();return(s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid)

def legacy_DATA_labels(fixture):
 """Independent new model proof. Old public fixture API is NOT executed here."""
 fixture,identity=_fixture(fixture)
 with opened(ROOT)as operation:
  source.require_task_operation(operation,ROOT);need(operation.code_root==ROOT and operation.producer_root==ROOT)
  context=operation._task_contexts[ROOT];self_raw=context.current(SELF);need(self_raw==Path(__file__).read_bytes());S_checkpoint=operation._task_expected_checkpoint;S_program_sha256=sha(self_raw)
  need(tuple(source.GRAPH)==GRAPH and set(operation._modules)==set(GRAPH))
  # CURRENT callable program identity is captured, parent DATA never compiled.
  namespaces={name:dict(operation._modules[name].__dict__)for name in GRAPH}
  namespaces[ASSESSMENT]={'__file__':str(ROOT/'scripts/governance'/(ASSESSMENT+'.py'))}
  raws={name:context.current('scripts/governance/'+name+'.py')for name in GRAPH+(ASSESSMENT,)}
  need(raws[ASSESSMENT]==(ROOT/'scripts/governance'/ (ASSESSMENT+'.py')).read_bytes())
  trees={name:model_tree(name,raws[name])for name in namespaces}
  definitions={name:{n.name:n for n in tree.body if isinstance(n,(ast.FunctionDef,ast.AsyncFunctionDef))}for name,(tree,count)in trees.items()}
  lambdas={}
  for name,(tree,count)in trees.items():
   lambdas[name]={n.targets[0].id:n for n in tree.body if isinstance(n,ast.Assign)and len(n.targets)==1 and isinstance(n.targets[0],ast.Name)and isinstance(n.value,ast.Lambda)}
  identities={id(operation._modules[name]):name for name in GRAPH}
  module_aliases={name:{}for name in namespaces}
  for name,namespace in namespaces.items():
   for alias,value in namespace.items():
    if isinstance(value,types.ModuleType):
     target=identities.get(id(value),value.__name__ if value.__name__ in GRAPH else None)
     if target is not None:module_aliases[name][alias]=target
  for node in trees[ASSESSMENT][0].body:
   if isinstance(node,ast.Import):
    for item in node.names:
     if item.name in GRAPH:module_aliases[ASSESSMENT][item.asname or item.name]=item.name
  required=close_functions(definitions,lambdas,module_aliases)
  need(tuple((name,tuple(sorted(symbol for owner,symbol in required if owner==name)))for name in sorted({owner for owner,symbol in required}))==EXPECTED_FUNCTIONS)
  used={name for name,symbol in required};need(ASSESSMENT in used)
  models={name:types.ModuleType('unadmitted_legacy_DATA_'+name)for name in used}
  original_import=builtins.__import__
  def imported(name,globals=None,locals=None,fromlist=(),level=0):
   need(level==0)
   if name in models:return models[name]
   need(name.split('.')[0]in STDLIB and name!='source_operation_snapshot')
   return original_import(name,globals,locals,fromlist,level)
  for name in used:
   target=models[name].__dict__
   for key,value in namespaces[name].items():
    if inspect.isfunction(value)or inspect.ismethod(value):continue
    if isinstance(value,types.ModuleType):
     dependency=module_aliases[name].get(key)
     if dependency is not None:
      if dependency not in used:continue
      value=models[dependency]
     elif value.__name__.split('.')[0]not in STDLIB:continue
    target[key]=value
   # The one raw-JSON-hashed literal must retain its CURRENT public type.
   # Verify exact frozen equivalence; never broadly thaw private constants.
   if name=='project_description_admission_delta':
    literals=[n for n in trees[name][0].body if isinstance(n,ast.Assign)and len(n.targets)==1 and isinstance(n.targets[0],ast.Name)and n.targets[0].id=='CURRENT_METADATA']
    need(len(literals)==1);literal=ast.literal_eval(literals[0].value)
    need(frozen_equal(namespaces[name]['CURRENT_METADATA'],literal));target['CURRENT_METADATA']=literal
   target['__name__']='unadmitted_legacy_DATA_'+name
   target['__builtins__']={**vars(builtins),'__import__':imported}
   target['_MODEL_FIXTURE_ROOT']=fixture
  seam_count=0
  for name in used:
   tree,count=trees[name];seam_count+=count
   nodes=[n for n in tree.body if isinstance(n,(ast.FunctionDef,ast.AsyncFunctionDef))and(name,n.name)in required or isinstance(n,ast.Assign)and len(n.targets)==1 and isinstance(n.targets[0],ast.Name)and(name,n.targets[0].id)in required and isinstance(n.value,ast.Lambda)]
   if name==ASSESSMENT:nodes=assessment_nodes(tree,frozenset(models),nodes)
   exec(compile(ast.Module(body=nodes,type_ignores=[]),str(ROOT/'scripts/governance'/(name+'.py')),'exec'),models[name].__dict__)
  need(seam_count==4)
  # CURRENT helper loader is unchanged and still verifies its original exact
  # helper pin. Prove that pin also names actual captured CURRENT bytes.
  helper='tests/governance/test_project_content_grant_media_source_delta.py'
  need(sha(context.current(helper))==models['project_content_grant_source_delta'].MEDIA_SOURCE_DELTA_SHA)
  # The one newly reachable CURRENT loader stays byte-pinned by its unchanged
  # body; its helper is also part of this genuine S capture, never E authority.
  need(sha(context.current('scripts/governance/current_native_pair_metadata_v1.py'))=='08def5167c217549ec2307bff8f001c5a6585ce5cabe36a32828227df82e0750')
  for name,model in models.items():
   need(not any(isinstance(v,types.ModuleType)and v.__name__=='source_operation_snapshot'for v in model.__dict__.values()))
  seals={name:{key:id(value)for key,value in module.__dict__.items()}for name,module in models.items()}
  # No parent-row equality precheck: mutated DATA reaches the unchanged reader,
  # actual current admission, cohort, inverse and Assessment source/hash loops.
  need(_identity(fixture)==identity)
  S_identity=ROOT.lstat();need(identity[:2]!=(S_identity.st_dev,S_identity.st_ino))
  E_before=capture_E(fixture)
  S_files={(value[0],value[1])for relative,value in context.files.items()if context.entries[relative]['kind']=='file'}
  E_files={(value['identity'][0],value['identity'][1])for value in E_before.values()if stat.S_ISREG(value['identity'][2])}
  need(not S_files&E_files)
  try:
   checked=models['project_description_admission_delta'].assert_current_recipe(fixture)
   need(type(checked)is dict and checked['production_paths']==19 and checked['full_source_revision_authorized']is False and checked['runtime_qualified']is False)
   labels=models[ASSESSMENT].SQL_obligations(fixture)
   need(type(labels)is set and len(labels)==8 and all(type(x)is str for x in labels))
  except BaseException as error:
   try:need(_identity(fixture)==identity and capture_E(fixture)==E_before)
   except BaseException:error.add_note('E_terminal_refused')
   raise
  need(_identity(fixture)==identity and capture_E(fixture)==E_before)
  for name,module in models.items():need({k:id(v)for k,v in module.__dict__.items()}==seals[name])
  source.require_task_operation(operation,ROOT)
 return {'schema':'Assessment-current-code-legacy-DATA-labels-v3','labels':sorted(labels),'legacy_DATA_only':True,'public_default_fixture_executed':False,'E_is_producer_or_current_source':False,'runtime_authority':False,'SQL_qualified':False,'S_source_operation_exit_verified':True,'current_source_authority_for_E':False,'old_public_default_positive_proved':False,'validated_project_production_paths':checked['production_paths'],'validated_metadata_paths':checked['metadata_paths'],'DATA_validation_terminal':'returned-after-S-exit','E_terminal_inventory_and_bytes_unchanged':True,'S_checkpoint':S_checkpoint,'S_model_program_sha256':S_program_sha256,'E_inventory_count':len(E_before),'E_inventory_sha256':sha(json.dumps(E_before,sort_keys=True,separators=(',',':')).encode())}

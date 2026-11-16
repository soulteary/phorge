"""Closed versioned 72+1 routing; unchanged v4 baseline, no source authority."""
import hashlib,json,unittest
import task_explicit_shard_routes_v4 as previous
OLD='test_task_current_source_admission.ExactHistoricalBridge.test_all_four_modified_bodies_restore_exact5903_parent'
NEW='test_task_current_source_admission_task_v2.ExactHistoricalBridgeTaskV2.test_all_four_modified_bodies_restore_exact5903_parent'
RETAINED=(
 'test_task_current_source_admission.ExactHistoricalBridge.test_tamper_is_refused_before_inverse',
 'test_task_current_source_admission.ExactHistoricalBridge.test_exact_new_Task_files_removed_only_after_full_pin_check',
 'test_task_current_source_admission.ExactHistoricalBridge.test_unrelated_current_source_is_not_downgraded')
PAIR=((OLD,NEW),)
def need(value,label):
 if value is not True:raise ValueError('Task73_route_refused:'+label)
def digest(value):return hashlib.sha256(json.dumps(value,sort_keys=True,separators=(',',':')).encode()).hexdigest()
def route_metadata():
 return {'schema':'Task-explicit73-route-v5','count':73,'previous':previous.route_metadata(),'extension_count':1,'extension_mapping':[{'old':a,'new':b}for a,b in PAIR],'extension_sha256':digest(PAIR),'retained_bridge_ids':list(RETAINED)}
def extension(rows,routed):
 ids=previous._rows(rows);old_class=OLD.rsplit('.',1)[0]+'.';new_module=NEW.split('.')[0]
 need({i for i in ids if i.startswith(old_class)}==set(RETAINED)|(set()if routed else{OLD}),'closed_original_bridge_class')
 need({r['id']for r in rows if r['module']==new_module}=={NEW},'closed_successor_module')
 need(set(RETAINED)<={r['id']for r in rows},'original_negative_boundaries_retained')
def route_groups(groups,full):
 # Validate and execute the original routing protocol first, unchanged.
 base_groups,base_full,metadata=previous.route_groups(groups,full)
 need(metadata==previous.route_metadata(),'original72_unchanged');extension(base_full,False)
 out=[]
 for module,suite,rows in base_groups:
  if module in (OLD.split('.')[0],NEW.split('.')[0]):
   leaves=previous._leaves(suite)
   need([{'id':t.id(),'module':t.__class__.__module__}for t in leaves]==rows,'actual_bridge_role_suite')
  if module==OLD.split('.')[0]:
   chosen=[t for t in leaves if t.id()!=OLD];kept=[r for r in rows if r['id']!=OLD]
   need([t.id()for t in chosen]==[r['id']for r in kept],'only_exact_bridge_method')
   out.append((module,unittest.TestSuite(chosen),kept))
  else:out.append((module,suite,rows))
 rows=[r for m,s,rs in out for r in rs]
 need(rows==[r for r in base_full if r['id']!=OLD],'only_one_additional_obligation_routed')
 validate_routed_inventory(rows,route_metadata())
 return out,rows,route_metadata()
def validate_routed_inventory(rows,metadata):
 need(type(metadata)is dict and metadata==route_metadata()and digest(metadata)==digest(route_metadata()),'exact_versioned_metadata')
 previous.validate_routed_inventory(rows,metadata['previous']);extension(rows,True)
 return True

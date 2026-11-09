"""Strict selected fixture result; no generic passed-bool acceptance."""
import json
REQUIRED={'native-session-issued-and-loaded','actual-native-route','controller-response-status','actual-response-cookie','actual-redeem','actual-ready','actual-native-binding','actual-used-ledger','same-browser-account-generation','old-account-generation-denied','native-logout-invalidates-media','current-disabled-account-denied','current-session-expiry-denied'}
KEYS={'schema','passed','stage','diagnostic','cleanup_diagnostic','checks','unexpected_output_bytes','cleanup','failed_resources_retained','unknown_commit_reconciled','browser_tested','password_form_login_tested','TLS_tested','file_authorization_tested','production_changed'}
def accept(raw):
 def unique(rows):
  d={}
  for k,v in rows:
   if k in d:raise ValueError('duplicate')
   d[k]=v
  return d
 d=json.loads(raw,object_pairs_hook=unique)
 if set(d)!=KEYS or d['schema']!='native-media-session-positive-sql-v1' or d['passed']is not True or d['stage']!='native-controller-positive-cases':raise ValueError('receipt shape')
 if d['diagnostic']is not None or d['cleanup_diagnostic']is not None or type(d['unexpected_output_bytes'])is not int or d['unexpected_output_bytes']!=0:raise ValueError('diagnostic/output')
 for k in ['failed_resources_retained','unknown_commit_reconciled','browser_tested','password_form_login_tested','TLS_tested','file_authorization_tested','production_changed']:
  if d[k]is not False:raise ValueError('scope')
 rows=d['checks']
 if type(rows)is not list or any(type(x)is not str or x not in REQUIRED for x in rows)or set(rows)!=REQUIRED:raise ValueError('checks')
 # Actual fixed cases: four exchange flows, each three binding rejections + replay.
 expected={'native-session-issued-and-loaded':4,'actual-native-route':32,'controller-response-status':32,'actual-response-cookie':8,'actual-redeem':4,'actual-ready':4,'actual-native-binding':4,'actual-used-ledger':4,'same-browser-account-generation':1,'old-account-generation-denied':1,'native-logout-invalidates-media':1,'current-disabled-account-denied':1,'current-session-expiry-denied':1}
 # route/status each exchange: prepare, issue, three bad bindings, redeem,
 # replay, ready = eight. Do not accept an arbitrary superset/count summary.
 if any(rows.count(k)!=n for k,n in expected.items()):raise ValueError('check counts')
 c=d['cleanup']
 if type(c)is not dict or set(c)!={'ownedSchemasRemoved','ownedRuntimeUsersRemoved','readerRemoved','native_writer_sessions_zero','native_writer_MDL_zero'}:raise ValueError('cleanup shape')
 if type(c['ownedSchemasRemoved'])is not int or c['ownedSchemasRemoved']!=55 or type(c['ownedRuntimeUsersRemoved'])is not int or c['ownedRuntimeUsersRemoved']!=2 or c['readerRemoved']is not True or c['native_writer_sessions_zero']is not True or c['native_writer_MDL_zero']is not True:raise ValueError('cleanup')
 return {'schema':'media-positive-selected-acceptance-v1','accepted':True,'normal_SQL_controller_only':True,'browser_TLS_or_fault_proof':False}

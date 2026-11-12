"""Safe allowlist projection only; never uploads raw runner/fixture files."""
import argparse,hashlib,json,os,re,stat,sys
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'media-session-docker'))
import consume
SHA=re.compile('[a-f0-9]{64}')
def read_bytes(path,limit):
 path=Path(path);fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
 try:
  a=os.fstat(fd)
  if not stat.S_ISREG(a.st_mode)or a.st_nlink!=1 or a.st_uid!=os.geteuid()or a.st_mode&0o022 or not 0<=a.st_size<=limit:raise ValueError('input qualification')
  out=bytearray()
  while len(out)<a.st_size:
   b=os.pread(fd,min(65536,a.st_size-len(out)),len(out))
   if not b:raise ValueError('short input')
   out.extend(b)
  fields=lambda z:(z.st_dev,z.st_ino,z.st_mode,z.st_uid,z.st_gid,z.st_nlink,z.st_size,z.st_mtime_ns,z.st_ctime_ns)
  if os.pread(fd,1,a.st_size)!=b''or fields(a)!=fields(os.fstat(fd))or fields(a)!=fields(os.lstat(path)):raise ValueError('input changed')
  return bytes(out)
 finally:os.close(fd)
def load(path,limit=4*1024*1024):
 def unique(rows):
  d={}
  for k,v in rows:
   if k in d:raise ValueError('duplicate')
   d[k]=v
  return d
 b=read_bytes(path,limit)
 if len(b)>limit:raise ValueError('size')
 return json.loads(b,object_pairs_hook=unique),b
PHASES={'preflight','owned-native-fixture-create','native-controller-positive-cases'}
CLASSES={'RuntimeException','Exception','InvalidArgumentException','PhutilAggregateException','AphrontQueryException','AphrontConnectionLostQueryException','LogicException'}
def diagnostic(d):
 if d is None:return None
 if type(d)is not dict or set(d)!={'phase','exception_class','source','line'}or d['phase']not in {'primary','cleanup-native-writer','cleanup-control-close'}or type(d['line'])is not int or not 0<=d['line']<10000000:raise ValueError('diagnostic')
 # Source input may contain only the existing safe public-source shape; never
 # copy the path itself, even when it is repository relative.
 if type(d['source'])is not str or not(d['source']=='external'or re.fullmatch(r'(src|support|resources)/[A-Za-z0-9_./-]+\.php',d['source'])):raise ValueError('source')
 if d['source']!='external'and any(x in ('','..','.')for x in d['source'].split('/')):raise ValueError('source')
 if type(d['exception_class'])is not str or not re.fullmatch(r'[A-Za-z_][A-Za-z0-9_\\]{0,191}',d['exception_class']):raise ValueError('class')
 return {'phase':d['phase'],'exception_family':d['exception_class']if d['exception_class']in CLASSES else'other-Throwable','source_scope':'external'if d['source']=='external'else'public-repository','line':d['line']}
def fixture_projection(raw):
 d=json.loads(raw)
 if type(d)is not dict or set(d)!=consume.KEYS or d['schema']!='native-media-session-positive-sql-v1' or type(d['passed'])is not bool or d['stage']not in PHASES:raise ValueError('fixture')
 rows=d['checks']
 if type(rows)is not list or len(rows)>256 or any(type(x)is not str or x not in consume.REQUIRED for x in rows):raise ValueError('checks')
 if type(d['unexpected_output_bytes'])is not int or not 0<=d['unexpected_output_bytes']<=1073741824:raise ValueError('output')
 safe={'fixture_passed':d['passed'],'stage':d['stage'],'check_counts':{k:rows.count(k)for k in sorted(consume.REQUIRED)},'unexpected_output_bytes':d['unexpected_output_bytes'],'diagnostic':diagnostic(d['diagnostic']),'cleanup_diagnostic':diagnostic(d['cleanup_diagnostic'])}
 # Success still goes through the full original strict consumer, unchanged.
 safe['strict_selected_accepted']=False
 if d['passed']:safe['strict_selected_accepted']=consume.accept(raw)['accepted']
 return safe
def project(directory,freshness_path=None):
 root=Path(directory);result={'schema':'native-media-session-CI-safe-v1','strict_selected_accepted':False,'browser_TLS_files_fault_or_full_N09_N10_claim':False}
 f=root/'terminal.json'
 if f.is_file():
  d,_=load(f,8192)
  if set(d)not in ({'accepted','resources_retained','unknown','no_browser_TLS_or_fault_claim'},{'accepted','resources_retained','unknown','retry_or_cleanup_authorized'}):raise ValueError('terminal')
  if any(type(v)is not bool for v in d.values()):raise ValueError('terminal types')
  result['runner_terminal_accepted']=d['accepted'];result['unknown']=d['unknown'];result['resources_retained']=d['resources_retained']
 worker=None;capture=None
 if(root/'resources-worker.json').is_file():
  resources,_=load(root/'resources-worker.json',8192)
  if set(resources)!={'prerequisite','source_volume','database_volume','seed','db','worker'}or any(type(resources[k])is not str or not re.fullmatch('[a-f0-9]{64}',resources[k])for k in ('prerequisite','seed','db','worker')):raise ValueError('worker identity')
  worker=resources['worker']
 if(root/'worker-terminal-capture.json').is_file():
  capture,_=load(root/'worker-terminal-capture.json',8192)
  if set(capture)!={'worker_exit_code','stdout_sha256','stderr_sha256','known_terminal'}or type(capture['worker_exit_code'])is not int or not 0<=capture['worker_exit_code']<=255 or capture['known_terminal']is not True or any(type(capture[k])is not str or not SHA.fullmatch(capture[k])for k in ('stdout_sha256','stderr_sha256')):raise ValueError('worker terminal capture')
 # Only stdout of a known terminal docker logs command for the worker is a
 # fixture receipt. The command itself and any unrelated logs remain private.
 matches=[]
 for p in root.glob('*.intent.json'):
  d,_=load(p,65536);a=d.get('argv')
  if type(a)is list and len(a)==2 and a[0]=='logs'and a[1]==worker:
   key=p.name.split('.')[0];status,_=load(root/(key+'.status.json'),8192)
   if set(status)=={'parent_terminal_known','capture_complete','exit_code','stdout_sha256','stderr_sha256'}and status['parent_terminal_known']is True and status['capture_complete']is True and type(status['exit_code'])is int and status['exit_code']==0:
    raw=read_bytes(root/(key+'.stdout'),4*1024*1024)
    if len(raw)<=4*1024*1024 and hashlib.sha256(raw).hexdigest()==status.get('stdout_sha256')and capture is not None and capture['stdout_sha256']==status['stdout_sha256']and capture['stderr_sha256']==status['stderr_sha256']:
     try:matches.append(fixture_projection(raw))
     except (ValueError,KeyError,TypeError):pass
 if len(matches)>1:raise ValueError('ambiguous fixture')
 if matches:result.update(matches[0])
 if result.get('strict_selected_accepted')is True:
  if freshness_path is None:raise ValueError('current source freshness missing')
  fresh,_=load(freshness_path,8192)
  if set(fresh)!={'schema','protected_source_equal','base_revision','base_tree','current_revision','protected_leaf_count','reviewed_overlay_count','runtime_diff_allowed','docs_reports_are_not_runtime_proof'}or fresh['schema']!='media-positive-protected-source-freshness-v1'or fresh['protected_source_equal']is not True or fresh['runtime_diff_allowed']is not False or fresh['reviewed_overlay_count']!=8 or type(fresh['protected_leaf_count'])is not int or fresh['protected_leaf_count']<1 or fresh['docs_reports_are_not_runtime_proof']is not True or fresh['base_revision']!='b59b59c69e44240135d0bc9fc722b2f58c8f821a'or fresh['base_tree']!='339b66fc3ce3af01498972dede63611a8f645d9b'or type(fresh['current_revision'])is not str or not re.fullmatch('[a-f0-9]{40}',fresh['current_revision']):raise ValueError('current source freshness')
  result['current_revision']=fresh['current_revision'];result['protected_source_equal']=True;result['protected_leaf_count']=fresh['protected_leaf_count']
  accepted,_=load(root/'accepted.json',8192)
  keys={'schema','accepted','normal_SQL_controller_only','browser_TLS_or_fault_proof','source_archive_sha256','source_inventory_sha256','php_image','mysql_image','worker_exit'}
  if set(accepted)!=keys or accepted['schema']!='media-positive-selected-acceptance-v1'or accepted['accepted']is not True or accepted['normal_SQL_controller_only']is not True or accepted['browser_TLS_or_fault_proof']is not False or type(accepted['worker_exit'])is not int or accepted['worker_exit']!=0 or capture['worker_exit_code']!=0 or capture['stderr_sha256']!=hashlib.sha256(b'').hexdigest():raise ValueError('accepted binding')
  for key in ('source_archive_sha256','source_inventory_sha256'):
   if type(accepted[key])is not str or not SHA.fullmatch(accepted[key]):raise ValueError('source digest')
   result[key]=accepted[key]
  for key in ('php_image','mysql_image'):
   if type(accepted[key])is not str or not re.fullmatch('sha256:[a-f0-9]{64}',accepted[key]):raise ValueError('image digest')
   result[key+'_content_digest']=accepted[key][7:]
  inventory,ib=load(root/'public-source-inventory.json',8*1024*1024)
  if hashlib.sha256(json.dumps(inventory,sort_keys=True,separators=(',',':')).encode()).hexdigest()!=accepted['source_inventory_sha256']or inventory.get('archive_sha256')!=accepted['source_archive_sha256']or inventory.get('base')!=fresh['base_revision']or type(inventory.get('entries'))is not dict or len(inventory['entries'])!=9312 or type(inventory.get('overlay'))is not dict or len(inventory['overlay'])!=8 or inventory.get('base_object_provenance',{}).get('root_tree')!=fresh['base_tree']or inventory.get('base_object_provenance',{}).get('git_object_hashes_verified')is not True:raise ValueError('source inventory binding')
  result['source_leaf_count']=len(inventory['entries'])
  probe,_=load(root/'actual-image-prerequisite.json',8192)
  if probe.get('version')!='8.5.12'or any(probe.get(k)is not True for k in ('mysqli','mbstring','pcntl_exec','posix_identity','fsync'))or probe.get('jit')!='disable'or probe.get('buffer')!='0'or probe.get('cli')!='0':raise ValueError('runtime prerequisite')
  caps=probe.get('caps',{});names={'CapEff','CapPrm','CapInh','CapAmb','CapBnd'}
  if set(caps)!=names|{'NoNewPrivs'}or caps['NoNewPrivs']!='1'or any(caps[k]!='0000000000000000'for k in names):raise ValueError('runtime capabilities')
  baseline,_=load(root/'fresh-database-baseline.json',8192)
  if set(baseline)!={'version','uuid','performance_schema','mdl_enabled','databases'}or baseline['version']!='8.4.13'or type(baseline['uuid'])is not str or not re.fullmatch('[a-f0-9-]{36}',baseline['uuid'])or any(type(baseline[k])is not int for k in ('performance_schema','mdl_enabled','databases'))or baseline['performance_schema']!=1 or baseline['mdl_enabled']!=1 or baseline['databases']!=0:raise ValueError('database baseline')
  result['PHP_version']='8.5.12';result['MySQL_version']='8.4.13';result['JIT_disabled']=True;result['fresh_database_baseline_verified']=True;result['MDL_instrument_enabled']=True
 if result.get('runner_terminal_accepted')is not True or result.get('unknown')is not False or result.get('resources_retained')is not False:result['strict_selected_accepted']=False
 return result
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--run',required=True);p.add_argument('--output',required=True);p.add_argument('--freshness',required=True);a=p.parse_args()
 try:r=project(a.run,a.freshness)
 except Exception:r={'schema':'native-media-session-CI-safe-v1','projection_refused':True,'strict_selected_accepted':False}
 fd=os.open(a.output,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
 try:
  b=(json.dumps(r,sort_keys=True)+'\n').encode();n=os.write(fd,b)
  if n!=len(b):raise ValueError('short safe artifact')
  os.fsync(fd)
 finally:os.close(fd)
 raise SystemExit(0 if r.get('strict_selected_accepted')is True else 1)

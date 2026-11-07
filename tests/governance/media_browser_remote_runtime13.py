"""Owned local command/SSH handles. Fixed Root input paths, no generic CLI."""
import contextlib,hashlib,json,os,selectors,signal,stat,subprocess,sys,time,types,threading
from pathlib import Path
from .lifecycle import Parent,need,digest
from .transport import FixedTransport
W=Path(__file__).resolve().parent.parent
SHARED=W/'workbench-source-prep-adapter-overlay-auth/adapter.py'
SHARED_SHA='f39dac52db7b0c91a792a23a4afad7957f1e326a1fa4342e111c5268a46d2c39'

def safe(path,limit=1048576,uid=None,mode=None):
 fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
 try:
  a=os.fstat(fd);need(stat.S_ISREG(a.st_mode)and a.st_nlink==1 and a.st_uid==(os.getuid()if uid is None else uid)and a.st_size<=limit and(mode is None or stat.S_IMODE(a.st_mode)==mode));chunks=[];left=limit+1
  while left:
   data=os.read(fd,min(left,65536))
   if not data:break
   chunks.append(data);left-=len(data)
  raw=b''.join(chunks);b=os.fstat(fd);c=os.lstat(path)
  def stamp(s):return(s.st_dev,s.st_ino,s.st_uid,s.st_gid,s.st_mode,s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
  need(len(raw)==a.st_size and stamp(a)==stamp(b)==stamp(c));return raw
 finally:os.close(fd)
def pinned(path,pin,mode=0o600):
 need(digest(pin));raw=safe(path,mode=mode);need(hashlib.sha256(raw).hexdigest()==pin);return raw
def validate_ssh_config(raw,alias):
 import shlex
 values={};host=0
 for line in raw.decode('utf8').splitlines():
  parts=shlex.split(line,comments=True)
  if not parts:continue
  need(len(parts)==2);key=parts[0].lower();value=parts[1]
  if key=='host':host+=1;need(host==1 and value==alias);continue
  need(host==1 and key in{'hostname','port','user','identityfile','identityagent','userknownhostsfile','hostkeyalias','identitiesonly'}and key not in values)
  values[key]=value
 need(host==1 and all(k in values for k in('hostname','user','port','userknownhostsfile')))
 need(values['port'].isdigit()and 0<int(values['port'])<=65535)
 return True
class Runner:
 def __init__(self,write):self.write=write;self.handles=set();self.pumps={}
 def stop_owned_tunnel(self,p):
  need(p in self.handles and type(p)is subprocess.Popen)
  if p.poll()is None:
   p.terminate()
   try:p.wait(timeout=3)
   except subprocess.TimeoutExpired:p.kill();p.wait(timeout=3)
  need(p.poll()is not None);self.handles.remove(p)
  if p in self.pumps:
   state=self.pumps.pop(p)
   for thread in state['threads']:thread.join(2)
   if any(t.is_alive()for t in state['threads']):state['stop'].set()
   for thread in state['threads']:thread.join(1)
   for stream in(p.stdout,p.stderr):
    with contextlib.suppress(Exception):stream.close()
   self.write('tunnel-private-output',{'stdout':bytes(state['out']).hex(),'stderr':bytes(state['err']).hex(),'truncated':state['bad']or any(t.is_alive()for t in state['threads'])})
   need(not state['bad']and all(not t.is_alive()for t in state['threads']))
 def spawn_owned_tunnel(self,argv):
  # Register the returned handle and bounded nonblocking stream pumps before
  # postchecks. A pump failure is a failed tunnel, never a cleanup success.
  p=subprocess.Popen(argv,stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.PIPE,bufsize=0,start_new_session=True,env=dict(os.environ,SSH_ASKPASS_REQUIRE='never'))
  self.handles.add(p);state={'out':bytearray(),'err':bytearray(),'bad':False,'stop':threading.Event(),'threads':[]};self.pumps[p]=state
  def pump(stream,key):
   sel=selectors.DefaultSelector();os.set_blocking(stream.fileno(),False);sel.register(stream,selectors.EVENT_READ)
   try:
    while not state['stop'].is_set():
     if not sel.select(.1):continue
     try:data=os.read(stream.fileno(),65536)
     except BlockingIOError:continue
     if not data:return
     if len(state[key])+len(data)>2097152:state['bad']=True;return
     state[key].extend(data)
   except BaseException:state['bad']=True
   finally:sel.close()
  for stream,key in((p.stdout,'out'),(p.stderr,'err')):
   thread=threading.Thread(target=pump,args=(stream,key),daemon=False);state['threads'].append(thread);thread.start()
  return p
 def tunnel_alive(self,p):return p in self.handles and p.poll()is None and not self.pumps[p]['bad']
 def close_remaining(self):
  failures=[]
  for p in list(self.handles):
   try:self.stop_owned_tunnel(p)
   except BaseException:failures.append('known-handle-stop-unproved')
  need(not failures)
 def result(self,argv,raw=b'',timeout=30):return self(argv,raw,timeout,True)
 def __call__(self,argv,raw=b'',timeout=30,return_result=False,pass_fds=()):
  need(type(argv)is list and all(type(x)is str and '\0'not in x for x in argv)and type(raw)is bytes and len(raw)<=1048576)
  p=subprocess.Popen(argv,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,bufsize=0,start_new_session=True,pass_fds=pass_fds);self.handles.add(p);out=bytearray();err=bytearray();sel=selectors.DefaultSelector();deadline=time.monotonic()+timeout;offset=0
  try:
   for stream,tag in ((p.stdout,'out'),(p.stderr,'err')):os.set_blocking(stream.fileno(),False);sel.register(stream,selectors.EVENT_READ,tag)
   if raw:os.set_blocking(p.stdin.fileno(),False);sel.register(p.stdin,selectors.EVENT_WRITE,'in')
   else:p.stdin.close()
   while sel.get_map():
    need(time.monotonic()<deadline)
    for key,_ in sel.select(min(.1,max(0,deadline-time.monotonic()))):
     if key.data=='in':
      n=os.write(key.fd,raw[offset:offset+65536]);need(n>0);offset+=n
      if offset==len(raw):sel.unregister(key.fileobj);key.fileobj.close()
     else:
      data=os.read(key.fd,65536)
      if not data:sel.unregister(key.fileobj);key.fileobj.close();continue
      target=out if key.data=='out'else err;target.extend(data);need(len(target)<=2097152)
   need(time.monotonic()<deadline);code=p.wait(timeout=max(.001,deadline-time.monotonic()));self.write('command-private',{'returncode':code,'stdout':bytes(out).hex(),'stderr':bytes(err).hex(),'truncated':False})
   if return_result:return{'returncode':code,'stdout':bytes(out),'stderr':bytes(err),'truncated':False}
   need(code==0);return bytes(out)
  except BaseException as primary:
   # Retain bounded partial stderr/stdout even when stdin breaks before the
   # child's diagnostic pipes are fully drained. Never replace the primary.
   limit=time.monotonic()+.3
   while time.monotonic()<limit:
    progress=False
    for stream,target in((p.stdout,out),(p.stderr,err)):
     if stream.closed:continue
     try:data=os.read(stream.fileno(),65536)
     except(BlockingIOError,OSError):continue
     if data:target.extend(data[:max(0,2097152-len(target))]);progress=True
    if not progress:time.sleep(.01)
   try:self.write('command-private-failure',{'failure_type':type(primary).__name__,'returncode':p.poll(),'stdout':bytes(out).hex(),'stderr':bytes(err).hex(),'partial_or_truncated':True})
   except BaseException:pass
   raise
  finally:
   sel.close()
   for stream in(p.stdin,p.stdout,p.stderr):
    with contextlib.suppress(Exception):stream.close()
   if p in self.handles:self.stop_owned_tunnel(p)
 def await_reviewed_bundle(self,packet,envelope,socket,tunnel):
  return self.bundle_waiter(packet,envelope,socket,tunnel)
 def prove_exact_remote_container_absent(self,packet,id):
  # Do not collapse arbitrary CLI failure to "absent". A bound audited adapter
  # must return the independent typed observation from actual Docker API data.
  result=self.absence_probe(packet,id);need(type(result)is dict and result=={'same_engine':True,'exact_id':id,'container_absent':True})
 def absence_probe(self,packet,id):
  # Positive read-only list-by-exact-ID, no grep/prefix or nonzero inference.
  from .transport import DOCKER
  command=[DOCKER,'container','ls','--all','--no-trunc','--filter','id='+id,'--format','{{json .ID}}']
  t=FixedTransport(packet,self,None,self.write);raw=t.ssh(command);rows=raw.decode('ascii').splitlines();need(rows==[]);return{'same_engine':True,'exact_id':id,'container_absent':True}
def run_bound(review_pin,packet_pin):
 from .. import media_browser_measured_executor as remote_browser_stage
 browser_stage=remote_browser_stage.bound_stage()
 global W,SHARED
 W=Path(remote_browser_stage.AUTHORITY_DIRECTORY)
 SHARED=W/'workbench-source-prep-adapter-overlay-auth/adapter.py'
 review=json.loads(pinned(W/'media-remote-measured13-execution-review-root.private.json',review_pin));packet=json.loads(pinned(W/'media-remote-measured13-packet-root.private.json',packet_pin));need(review.get('scope')=='owned-media-private-remote-transport-and-measured-browser13'and review.get('packet_sha256')==packet_pin)
 # Fixed adapter filename, captured hash and Root execution review are mandatory.
 # This component may not replace native preparation with a JSON ready flag.
 raw=pinned(W/'media-owned-native-measured13-local-adapter.py',packet['local_proof_adapter_sha256']);adapter=types.ModuleType('owned_local_fixture');adapter.__file__=str(W/'media-owned-native-measured13-local-adapter.py');sys.modules[adapter.__name__]=adapter;exec(compile(raw,adapter.__file__,'exec'),adapter.__dict__)
 sharedraw=pinned(SHARED,SHARED_SHA,0o644);shared=types.ModuleType('owned_directory');shared.__file__=str(SHARED);sys.modules[shared.__name__]=shared;exec(compile(sharedraw,str(SHARED),'exec'),shared.__dict__)
 directory=W/('media-transport-'+os.urandom(12).hex());os.mkdir(directory,0o700);held=shared.OwnedDirectory(directory);counter=0
 def write(kind,value):
  nonlocal counter
  counter+=1;held.write(str(counter).zfill(4)+'-'+kind+'.private.json',(json.dumps(value,sort_keys=True)+'\n').encode())
 need(callable(getattr(adapter,'prepare_and_prove_current_native_fixture',None))and callable(getattr(adapter,'close_after_transport',None)))
 runner=Runner(write)
 from .stage import wait_reviewed_bundle,preflight_operator
 runner.bundle_waiter=lambda p,e,s,h:wait_reviewed_bundle(p,e,s,h,runner,held,write)
 transport=FixedTransport(packet,runner,adapter,write);parent=Parent(transport,write);primary=None
 try:
  # System tool must also match fixed canonical Root observation before spawn.
  need(packet['ssh_binary']=='/usr/bin/ssh');need(hashlib.sha256(safe(packet['ssh_binary'],16777216,0,0o755)).hexdigest()==packet['ssh_binary_sha256'])
  validate_ssh_config(pinned(Path(packet['ssh_config']),packet['ssh_config_sha256']),packet['ssh_alias']);preflight_operator(packet);parent.start()
  browser_result=browser_stage.execute(packet,transport.local_proof,adapter)
  write('browser-flow-v4-measured',browser_result)
  release=directory/'release';deadline=time.monotonic()+900
  while time.monotonic()<deadline:
   if release.exists():need(safe(release,64,mode=0o600)==packet['owner'].encode());break
   need(runner.tunnel_alive(parent.tunnel));time.sleep(.1)
  else:raise RuntimeError('owned private transport lease expired')
 except BaseException as e:primary=e
 finally:
  result=parent.close(primary)
  try:runner.close_remaining()
  except BaseException:result['passed']=False;result['remote_unknown_retained']=True;result['local_fixture_release_allowed']=False;result['cleanup_failures'].append('known-handle-stop-unproved')
  # Actual local fixture adapter owns stopHTTP/SQL0/release/DB/storage cleanup.
  # Call it even if a private writer or transport close failed; preserve primary.
  try:
   local_result=adapter.close_after_transport(result);need(type(local_result)is dict and local_result.get('passed')is True and local_result.get('cleanup_failures')==[])
  except BaseException as e:
   if primary is None:primary=e
   result['passed']=False;result['cleanup_failures'].append('local-native-cleanup-unproved')
  try:write('local-native-return',result)
  except BaseException as e:
   if primary is None:primary=e
   result['passed']=False
  finally:held.close()
 if primary is not None:raise primary
 need(result['passed'])

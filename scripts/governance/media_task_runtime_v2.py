"""CURRENT-only selected-media worker DATA adapter and held archive access.
No source admission issuer, Docker execution, SQL acceptance or runtime grant.
"""
import hashlib,hmac,json,os,re,stat
from pathlib import Path
SELF='scripts/governance/media_task_runtime_v2.py'
PRODUCER='support/governance/media-session-positive/run.php'
PINS='support/governance/media-session-positive/source-pins.json'
PRODUCER_SHA='bd3504a2212eaedafae27bbde8f6ca824086f8573d0cec95871af35a3f012f81'
PINS_SHA='5d708748e4bf47af83ae97edac0fb9f37ac89d41ce11b1d7d371fb595e24d222'
LOOP=b"  foreach(json_decode($manifest,true,32,JSON_THROW_ON_ERROR)as$relative=>$expected){if(!hash_equals($expected,hash_file('sha256',$root.'/'.$relative))){throw new RuntimeException('media_positive_source_changed');}}"
REPLACEMENT=b'  media_current_source_guard_v2($root,$manifest);'
TERMINAL=b'exit($passed&&!$failure?0:1);'
START=b"echo json_encode(array('schema'=>'native-media-session-positive-sql-v1'"

def need(value):
 if value is not True:raise ValueError('media_Task_runtime_v2_refused')
def sha(raw):return hashlib.sha256(raw).hexdigest()
def canonical(value):return json.dumps(value,sort_keys=True,separators=(',',':'),ensure_ascii=False,allow_nan=False).encode()
def stamp(s):return(s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid,s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
def hex40(v):return type(v)is str and re.fullmatch('[a-f0-9]{40}',v)is not None

def source_inputs(operation,root):
 """Only genuine CURRENT owner may derive physical and historical pin DATA."""
 import source_operation_snapshot as source
 root=Path(root);source.require_operation(operation,root);source.require_task_operation(operation,root)
 need(operation.code_root==root and operation.producer_root==root and source.ROOT==root)
 ctx=operation._task_contexts[root];need(ctx.operation is operation and ctx.current(SELF)==Path(__file__).read_bytes())
 pins=ctx.current(PINS);need(sha(pins)==PINS_SHA)
 legacy=json.loads(pins);need(type(legacy)is dict and len(legacy)==19)
 physical={}
 for relative,expected in legacy.items():
  need(type(relative)is str and type(expected)is str and re.fullmatch('[a-f0-9]{64}',expected)is not None)
  current=ctx.current(relative);need((root/relative).read_bytes()==current)
  # This is authenticated historical DATA, never runtime program execution.
  parent=ctx.parent(relative);need(sha(parent)==expected)
  physical[relative]=sha(current)
 raw=ctx.current(PRODUCER);need(sha(raw)==PRODUCER_SHA)
 source.require_task_operation(operation,root)
 return raw,{'checkpoint':operation._task_expected_checkpoint,'physical_source_pins':physical,'historical_source_pins_sha256':PINS_SHA}

def adapt_worker(raw,binding,key):
 """Pure, exactly reversible adapter; returned bytes confer no source authority.
 HMAC binds output to one controller run only, not prior source admission.
 """
 need(type(raw)is bytes and sha(raw)==PRODUCER_SHA and raw.startswith(b'<?php\n'))
 need(type(binding)is dict and set(binding)=={'checkpoint','physical_source_pins','historical_source_pins_sha256','archive_sha256','run_nonce'})
 need(hex40(binding['checkpoint'])and binding['historical_source_pins_sha256']==PINS_SHA)
 pins=binding['physical_source_pins'];need(type(pins)is dict and len(pins)==19)
 for relative,digest in pins.items():
  need(type(relative)is str and relative and not relative.startswith('/')and all(x not in ('','.','..')for x in relative.split('/'))and '\\'not in relative and '\x00'not in relative)
  need(type(digest)is str and re.fullmatch('[a-f0-9]{64}',digest)is not None)
 need(all(type(binding[k])is str and re.fullmatch('[a-f0-9]{64}',binding[k])is not None for k in ('archive_sha256','run_nonce')))
 need(type(key)is bytes and len(key)==32)
 payload=canonical(binding);encoded=payload.hex().encode();secret=key.hex().encode()
 header=(b"<?php\n$media_current_directory='/source/support/governance/media-session-positive';\n"
  b"$media_current_binding_v2=json_decode(hex2bin('"+encoded+b"'),true,32,JSON_THROW_ON_ERROR);\n"
  b"$media_current_key_v2=hex2bin('"+secret+b"');\n"
  b"function media_current_source_guard_v2($root,$manifest){global $media_current_binding_v2;"
  b"if(hash('sha256',$manifest)!==$media_current_binding_v2['historical_source_pins_sha256']){throw new RuntimeException('media_current_manifest');}"
  b"$old=json_decode($manifest,true,32,JSON_THROW_ON_ERROR);$current=$media_current_binding_v2['physical_source_pins'];"
  b"if(array_keys($old)!==array_keys($current)){throw new RuntimeException('media_current_pin_keys');}"
  b"foreach($current as$relative=>$expected){if(!hash_equals($expected,hash_file('sha256',$root.'/'.$relative))){throw new RuntimeException('media_current_physical_source');}}}\n")
 # Original manifest key order is preserved at runtime by comparison as sets.
 header=header.replace(b"array_keys($old)!==array_keys($current)",b"array_diff_key($old,$current)||array_diff_key($current,$old)")
 begin=b"ob_start();\n"+START
 terminal=(b"$media_current_native_v2=ob_get_clean();"
  b"$media_current_frame_v2=array('schema'=>'native-media-Task-selected-frame-v2','binding_sha256'=>'"+sha(payload).encode()+b"','native_json'=>$media_current_native_v2);"
  b"$media_current_frame_v2['mac']=hash_hmac('sha256',$media_current_frame_v2['binding_sha256'].\"\\n\".$media_current_native_v2,$media_current_key_v2);"
  b"echo json_encode($media_current_frame_v2,JSON_THROW_ON_ERROR).\"\\n\";"+TERMINAL)
 need(raw.count(LOOP)==raw.count(TERMINAL)==raw.count(START)==1)
 body=raw[6:].replace(LOOP,REPLACEMENT,1).replace(b'__DIR__',b'$media_current_directory').replace(START,begin,1).replace(TERMINAL,terminal,1)
 restored=body.replace(terminal,TERMINAL,1).replace(begin,START,1).replace(b'$media_current_directory',b'__DIR__').replace(REPLACEMENT,LOOP,1)
 need(b'<?php\n'+restored==raw)
 return header+body

def accept_frame(raw,binding,key,legacy_accept):
 """Runtime DATA consumption only; controller must prove processes/source exit."""
 need(type(raw)is bytes and 0<len(raw)<=4*1024*1024 and type(key)is bytes and len(key)==32)
 def closed(pairs):
  d={}
  for k,v in pairs:need(k not in d);d[k]=v
  return d
 frame=json.loads(raw,object_pairs_hook=closed)
 need(type(frame)is dict and set(frame)=={'schema','binding_sha256','native_json','mac'}and frame['schema']=='native-media-Task-selected-frame-v2')
 digest=sha(canonical(binding));need(frame['binding_sha256']==digest and type(frame['native_json'])is str and type(frame['mac'])is str)
 native=frame['native_json'].encode();expected=hmac.new(key,digest.encode()+b'\n'+native,hashlib.sha256).hexdigest()
 need(hmac.compare_digest(expected,frame['mac']))
 return legacy_accept(native)

class HeldArchive:
 """Keep exact four-name/nlink2 archive publication open through runtime.
 Input rows/manifest are DATA; enclosing genuine owner alone grants source use.
 """
 def __init__(self,path,rows,manifest,archive_module):
  self.path=Path(path);self.rows=rows;self.manifest=manifest;self.archive=archive_module;self.fds=[];self.closed=False
  try:
   need(self.path.is_absolute()and self.path.resolve()==self.path)
   self.dirfd=os.open(self.path,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW);self.fds.append(self.dirfd);self.directory=stamp(os.fstat(self.dirfd))[:5]
   need(stat.S_IMODE(self.directory[2])==0o700 and self.directory[3]==os.geteuid())
   names=set(os.listdir(self.dirfd));pending=[n for n in names if re.fullmatch('archive-[a-f0-9]{32}\\.pending',n)];inventory=[n for n in names if re.fullmatch('inventory-[a-f0-9]{32}\\.pending',n)]
   need(len(pending)==len(inventory)==1 and pending[0][8:-8]==inventory[0][10:-8])
   self.names={'current-source.tar':pending[0],'current-source-inventory.private.json':inventory[0]};need(names==set(self.names)|set(self.names.values()))
   self.held={};self.identities={}
   for final,temp in self.names.items():
    fd=os.open(final,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK,dir_fd=self.dirfd);self.fds.append(fd);self.held[final]=fd;self.identities[final]=stamp(os.fstat(fd))
   self.check()
  except BaseException as primary:
   try:self.close()
   except BaseException as secondary:raise BaseExceptionGroup('media_archive_primary_and_close_failures',[primary,secondary])
   raise
 def check(self):
  need(self.closed is False and stamp(os.fstat(self.dirfd))[:5]==self.directory==stamp(self.path.lstat())[:5])
  need(set(os.listdir(self.dirfd))==set(self.names)|set(self.names.values()))
  for final,temp in self.names.items():
   fd=self.held[final];s=os.fstat(fd);need(stat.S_ISREG(s.st_mode)and stat.S_IMODE(s.st_mode)==0o600 and s.st_uid==os.geteuid()and s.st_nlink==2)
   need(stamp(s)==self.identities[final]==stamp(os.stat(final,dir_fd=self.dirfd,follow_symlinks=False))==stamp(os.stat(temp,dir_fd=self.dirfd,follow_symlinks=False)))
  proof=self.archive.readback_fd(self.held['current-source.tar'],self.rows)
  need(all(self.manifest[k]==v for k,v in proof.items()))
  expected=self.archive.canonical(self.manifest)+b'\n';need(os.pread(self.held['current-source-inventory.private.json'],len(expected)+1,0)==expected)
 def read_bytes(self):
  self.check();fd=self.held['current-source.tar'];size=os.fstat(fd).st_size;parts=[];offset=0
  while offset<size:
   part=os.pread(fd,min(65536,size-offset),offset);need(bool(part));parts.append(part);offset+=len(part)
  need(os.pread(fd,1,size)==b'');self.check();return b''.join(parts)
 def close(self):
  errors=[]
  for fd in reversed(self.fds):
   try:os.close(fd)
   except OSError as e:errors.append(e)
  self.fds=[];self.closed=True
  if errors:raise ExceptionGroup('media_archive_close_failures',errors)

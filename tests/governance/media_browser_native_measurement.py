"""Root-owned current native runtime measurement, never a descriptor-only proof."""
from pathlib import Path
import hashlib,json,os,re,secrets,stat
try:
    from . import media_browser_account_switch_v3 as consumer
except ImportError:
    import media_browser_account_switch_v3 as consumer
FD_SHA='1ce056ba3c56eba304f54761929e3e2ef90aa3725942db4a6508df285432829e'
RECEIVER_SHA256='bb93bd54587fbaad70107ef482c88ba098f846dc2ef93213974ee868a6ce4883'
FD_SOURCE_SHA256=FD_SHA
FD_SOURCE_PATH=str(Path(__file__).with_name('media_browser_fd_source.php')) # Fixed captured sibling, never a caller target.
RECEIVER_PATH=Path(__file__).with_name('media_browser_native_source.php')
FIXTURE='support/governance/native-organization/media-wire-fixture.php'

def need(v):
    if v is not True:raise RuntimeError('native_media_actual_source_unproved')
def canonical(v):return json.dumps(v,sort_keys=True,separators=(',',':'),ensure_ascii=False).encode()
def digest(v):return hashlib.sha256(v).hexdigest()
def capture(path,pin):
    p=Path(path);need(p.is_absolute()and p.resolve()==p);before=[(x,os.lstat(x))for x in p.parents];need(all(stat.S_ISDIR(s.st_mode)for _,s in before))
    fd=os.open(p,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    stamp=lambda s:(s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid,s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
    try:
        a=os.fstat(fd);need(stat.S_ISREG(a.st_mode)and a.st_uid==os.getuid()and a.st_nlink==1 and not(a.st_mode&0o022)and 0<a.st_size<=1048576);raw=b''
        while len(raw)<a.st_size:
            part=os.read(fd,min(65536,a.st_size-len(raw)));need(bool(part));raw+=part
        need(digest(raw)==pin and stamp(a)==stamp(os.fstat(fd))==stamp(os.lstat(p)))
        for x,s in before:need((s.st_dev,s.st_ino,s.st_mode)==(os.lstat(x).st_dev,os.lstat(x).st_ino,os.lstat(x).st_mode))
        return raw
    finally:os.close(fd)

def collect(native,packet,local_proof):
    expected=consumer.native_expectation() # All null Root roles refuse before I/O.
    need(type(FD_SOURCE_PATH)is str and type(RECEIVER_SHA256)is str)
    b=native.b;contract=packet['local_native_contract']
    need(type(local_proof)is dict and local_proof.get('passed')is True and local_proof.get('native_fixture_owned')is True and local_proof.get('whole_source_current')is True and local_proof.get('browser_session_preseeded')is False)
    need(contract==expected['native_tuple']and contract['source_head']==b['source_head']and contract['source_tree']==b['source_tree'])
    need(b['source_identity']==expected['source_root_identity']and digest(canonical(b['source_files']))==expected['manifest_sha256']and digest(canonical(b['source_aliases']))==expected['aliases_sha256'])
    need(len(b['source_files'])==expected['files']and len(b['source_aliases'])==expected['aliases']and b['source_files'].get(FIXTURE)==consumer.source_review()['fixture_sha256'])
    # Read through the existing real owned guest RPC, not from caller booleans.
    cfg=consumer.decode(native.read_file('router.json'));need(type(cfg)is dict and cfg.get('owner')==native.owner)
    need(cfg.get('app_origin')==contract['app_origin']and cfg.get('file_origin')==contract['file_origin']and type(cfg.get('transport_proof'))is str and re.fullmatch('[a-f0-9]{64}',cfg['transport_proof'])is not None and cfg['transport_proof']==b['transport_proof']and digest(cfg['transport_proof'].encode())==contract['transport_proof_sha256'])
    need(local_proof.get('source_identity')==b['source_identity']and all(local_proof.get(k)==contract[k]for k in contract))
    # The current owned listener, not merely configured port metadata.
    need(native.server is not None and native.server_thread.is_alive()is True)
    address=native.server.socket.getsockname();need(type(address)is tuple and len(address)==2 and address[0]=='127.0.0.1'and type(address[1])is int and native.server.socket.fileno()>=0)
    port=address[1];need(type(packet['local_forward_port'])is int and packet['local_forward_port']==port==expected['local_forward_port']==local_proof.get('forward_port')==b['ports']['forward'])
    fd=capture(FD_SOURCE_PATH,FD_SOURCE_SHA256);receiver=capture(RECEIVER_PATH,RECEIVER_SHA256);need(fd.startswith(b'<?php')and receiver.startswith(b'<?php'))
    value={'files':b['source_files'],'aliases':b['source_aliases'],'identity':b['source_identity'],'measurement_nonce':secrets.token_hex(32)};request=canonical(value)
    need(len(request)<=4194304);code=fd[5:].decode()+receiver[5:].decode().replace('const NM_REQUEST_SHA256=null;',"const NM_REQUEST_SHA256='"+digest(request)+"';")+'\nnm_main();'
    raw=native.command(['exec','-i',b['container'],'/usr/local/bin/php','-r',code],request,timeout=90)
    observed=consumer.decode(raw);need(type(observed)is dict and observed.get('measurement_nonce')==value['measurement_nonce'])
    measured={**observed,'native_tuple':dict(contract),'local_forward_port':port,'native_owner_sha256':digest(native.owner.encode()),'receiver_sha256':RECEIVER_SHA256,'guest_receipt_sha256':digest(raw)}
    consumer.validate_native_measurement(measured,contract)
    native.record('actual-native-source-and-fixture-measurement',measured)
    return measured

def execute_measured_stage(native,packet,local_proof,dispatch):
    # dispatch is the captured host executor; unreachable on any measurement gap.
    measured=collect(native,packet,local_proof)
    return dispatch(measured)

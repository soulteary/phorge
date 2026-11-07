"""Concrete measured-source -> exact13 install -> fixed guest command host stage.

Placed beside the unchanged reviewed engine/owned_container/normal TLS package
by the captured Root launcher. No public routes, arbitrary command or image.
"""
import base64,hashlib,json,re
from pathlib import Path
try:
    from . import engine,owned_container,approved_origins as origins,remote_browser_stage
    from . import media_browser_install13 as installer,media_browser_native_measurement as measurement
    from . import media_browser_account_switch_v3 as consumer
except ImportError: # Host controls supply explicit engine/transport collaborators.
    engine=owned_container=origins=remote_browser_stage=None
    import media_browser_install13 as installer
    import media_browser_native_measurement as measurement
    import media_browser_account_switch_v3 as consumer
EXECUTION_LEASE_SHA256=None
ROOT_ENGINE_OBSERVATION_SHA256=None
ROOT_RUNTIME_OBSERVATION_SHA256=None
SOURCE_BUNDLE_SHA256=None
INSTALLER_SHA256=None
AUTHORITY_DIRECTORY=None

INSTALL13=r'''import sys,os,stat,hashlib,json
p='/opt/phorge-media-browser/source_install13.py'
def st(s):return(s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid,s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
def need(v):
 if v is not True:raise RuntimeError('fixed_installer13_unproved')
from pathlib import Path
path=Path(p);parents=[(x,os.lstat(x))for x in path.parents];need(all(stat.S_ISDIR(s.st_mode)for _,s in parents))
f=os.open(p,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
try:
 a=os.fstat(f);need(stat.S_ISREG(a.st_mode)and(a.st_uid,a.st_gid,a.st_nlink,stat.S_IMODE(a.st_mode))==(0,0,1,0o444)and 0<a.st_size<=1048576);raw=b''
 while len(raw)<a.st_size:
  b=os.read(f,min(65536,a.st_size-len(raw)));need(bool(b));raw+=b
 need(st(a)==st(os.fstat(f))==st(os.lstat(p))and hashlib.sha256(raw).hexdigest()==sys.argv[1])
 for x,s in parents:need((s.st_dev,s.st_ino,s.st_mode)==(os.lstat(x).st_dev,os.lstat(x).st_ino,os.lstat(x).st_mode))
finally:os.close(f)
m={'__file__':p,'__name__':'captured_fixed_install13'};exec(compile(raw,p,'exec'),m);m['SOURCE_BUNDLE_SHA256']=sys.argv[2]
print(json.dumps(m['execute_bound'](sys.stdin.buffer,sys.argv[3]),sort_keys=True))'''
EXECUTE13="import sys,importlib.util,json; d='/tmp/phorge-browser-v3-source-'+sys.argv[1]; s=importlib.util.spec_from_file_location('owned_measured_TLS_package',d+'/__init__.py',submodule_search_locations=[d]); p=importlib.util.module_from_spec(s); sys.modules[s.name]=p; s.loader.exec_module(p); m=__import__(s.name+'.media_browser_account_switch_v3',fromlist=['execute_bound_guest']); print(json.dumps(m.execute_bound_guest(sys.stdin.buffer.read(1048577)),sort_keys=True))"

def need(v):
    if v is not True:raise RuntimeError('measured_browser_host_executor_unproved')
def digest(x):return type(x)is str and re.fullmatch('[a-f0-9]{64}',x)is not None

def source_bundle(raw):
    need(type(raw)is bytes and 0<len(raw)<=installer.BUDGET and hashlib.sha256(raw).hexdigest()==SOURCE_BUNDLE_SHA256)
    v=installer.decode(raw);need(type(v)is dict and set(v)==installer.FILES)
    for n,x in v.items():
        need(type(x)is str);b=base64.b64decode(x,validate=True);need(base64.b64encode(b).decode()==x and len(b)<=262144 and b'\x00'not in b and(bool(b)or n=='__init__.py'))
    return raw

def checked(executor,args,data):
    r=executor._raw(args,input=data)
    need(type(r.returncode)is int and r.returncode==0 and r.stderr==b''and type(r.stdout)is bytes and len(r.stdout)<=1048576)
    return consumer.decode(r.stdout)

def owned_lifecycle(lifecycle,action):
    primary=cleanup_error=None;value=None
    try:value=action(lifecycle.create())
    except BaseException as e:primary=e
    finally:
        try:cleanup=lifecycle.cleanup()
        except BaseException as e:cleanup_error=e
    if primary is not None or cleanup_error is not None:raise owned_container.LifecycleFailure(primary,cleanup_error)from None
    need(type(cleanup)is dict and cleanup.get('removed')is True);return value

def _execute(profile,packet,local_proof,native,install_only):
    # No native command, role read or engine action until every external role.
    need(all(digest(v)for v in (EXECUTION_LEASE_SHA256,ROOT_ENGINE_OBSERVATION_SHA256,ROOT_RUNTIME_OBSERVATION_SHA256,SOURCE_BUNDLE_SHA256,INSTALLER_SHA256)))
    consumer.source_review()
    need(all(v is not None for v in (engine,owned_container,origins,remote_browser_stage)))
    contract=packet['local_native_contract']
    if not install_only:
        app,files=origins.verified(profile);need((app,files)==(contract['app_origin'],contract['file_origin']))
    capture=remote_browser_stage.capture
    lease_name='media-browser-measured13-install-probe-lease-root.private.json'if install_only else'media-browser-measured13-execution-lease-root.private.json'
    lease=consumer.decode(capture(lease_name,EXECUTION_LEASE_SHA256))
    need(type(lease)is dict and set(lease)=={'scope','native_tuple','local_forward_port','nonce','owner','native_container_id','public_route_authorized'})
    need(lease['scope']==('owned-measured13-source-install-probe'if install_only else'owned-measured13-normal-TLS-browser-attempt')and lease['native_tuple']==contract and type(lease['local_forward_port'])is int and lease['local_forward_port']==packet['local_forward_port']and lease['public_route_authorized']is False)
    need(owned_container.hex_value(lease['nonce'],24)and owned_container.hex_value(lease['owner'],64)and owned_container.hex_value(lease['native_container_id'],64)and lease['native_container_id']==native.b['container'])
    # Actual Root-owned native command/source/router/listener measurements, before
    # browser create/install. No caller may substitute a prepared JSON proof.
    measured=measurement.collect(native,packet,local_proof)
    consumer.validate_native_measurement(measured,contract)
    directory=Path(remote_browser_stage.AUTHORITY_DIRECTORY)
    bundle=source_bundle(capture('media-browser-measured13-source-bundle-root.private.json',SOURCE_BUNDLE_SHA256))
    origin_raw=None if install_only else capture('media-native-measured13-origin-review-root.private.json',origins.ROOT_APPROVED_ORIGIN_REVIEW_SHA256)
    engine.ROOT_OBSERVATION_SHA256=ROOT_ENGINE_OBSERVATION_SHA256;engine.ROOT_RUNTIME_OBSERVATION_SHA256=ROOT_RUNTIME_OBSERVATION_SHA256
    executor=engine.FixedPodman(directory/'media-browser-measured13-engine-observation-root.private.json',directory/'media-browser-measured13-runtime-observation-root.private.json',nonce=lease['nonce'],owner=lease['owner'],source=contract['source_head'])
    need(executor.observation['existing_root_owned_container']['Id']==lease['native_container_id'])
    lifecycle=owned_container.OwnedContainer(executor.command,directory/('measured13-browser-'+lease['nonce']+'-lease.private.json'),nonce=lease['nonce'],owner=lease['owner'],source=contract['source_head'],image_id=executor.actual_image,image_reference=executor.image_reference,php_container_id=lease['native_container_id'])
    def action(identity):
        executor.start()
        installed=checked(executor,['exec','-i','--user','1000:1000',identity,'/usr/bin/python3','-I','-B','-c',INSTALL13,INSTALLER_SHA256,SOURCE_BUNDLE_SHA256,lease['nonce']],bundle)
        need(type(installed)is dict and set(installed)=={'scope','directory','device','inode','source_bundle_sha256','files','host_execution'}and installed['scope']=='owned-browser-versioned13-data-install'and installed['directory']=='/tmp/phorge-browser-v3-source-'+lease['nonce']and installed['source_bundle_sha256']==SOURCE_BUNDLE_SHA256 and type(installed['files'])is int and installed['files']==13 and type(installed['device'])is int and installed['device']>0 and type(installed['inode'])is int and installed['inode']>0 and installed['host_execution']is False)
        if install_only:
            return {'scope':'owned-measured13-source-install-probe','passed':True,'native_source_verified':True,'actual_fixture_sha256':measured['actual_fixture_sha256'],'source_bundle_sha256':SOURCE_BUNDLE_SHA256,'files':13,'browser_executed':False,'TLS_proved':False,'SQL_cleanup_proved':False,'public_route_authorized':False}
        payload=dict(native_tuple=contract,native_measurement=measured,manifest=native.ready,origin_review=origin_raw.decode(),nonce=lease['nonce'],owner=lease['owner'])
        result=checked(executor,['exec','-i','--user','1000:1000',identity,'/usr/bin/python3','-I','-B','-c',EXECUTE13,lease['nonce']],json.dumps(payload,separators=(',',':')).encode())
        return consumer.validate_public(result)
    return owned_lifecycle(lifecycle,action)

def execute_owned_flow(profile,packet,local_proof,native):
    return _execute(profile,packet,local_proof,native,False)

def execute_install_probe(packet,local_proof,native):
    """Separate Root install-only lease; no TLS endpoint or browser dispatch."""
    return _execute(None,packet,local_proof,native,True)


class MeasuredRemoteStage:
    """Same execute(packet,local_proof,adapter) boundary as remote lifecycle."""
    def execute(self,packet,local_proof,adapter):
        need(all(digest(v)for v in (EXECUTION_LEASE_SHA256,ROOT_ENGINE_OBSERVATION_SHA256,ROOT_RUNTIME_OBSERVATION_SHA256,SOURCE_BUNDLE_SHA256,INSTALLER_SHA256)))
        consumer.source_review();need(all(v is not None for v in (engine,owned_container,origins,remote_browser_stage)));native=getattr(adapter,'_RUNTIME',None)
        need(native is not None and type(native.ready)is dict)
        raw=remote_browser_stage.capture('media-native-measured13-origin-review-root.private.json',origins.ROOT_APPROVED_ORIGIN_REVIEW_SHA256)
        profile=origins.approve(raw,native.ready.get('remote_origin_profile'))
        return execute_owned_flow(profile,packet,local_proof,native)


def bound_stage():
    need(all(digest(v)for v in (EXECUTION_LEASE_SHA256,ROOT_ENGINE_OBSERVATION_SHA256,ROOT_RUNTIME_OBSERVATION_SHA256,SOURCE_BUNDLE_SHA256,INSTALLER_SHA256)))
    consumer.source_review();need(type(AUTHORITY_DIRECTORY)is str and Path(AUTHORITY_DIRECTORY).is_absolute())
    need(all(v is not None for v in (engine,owned_container,origins,remote_browser_stage)))
    remote_browser_stage.AUTHORITY_DIRECTORY=AUTHORITY_DIRECTORY
    return MeasuredRemoteStage()

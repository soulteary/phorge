"""Deterministic source artifact producer; never creates authority or executes it."""
import base64,hashlib,json,os,stat
from pathlib import Path
try:
    from . import media_browser_install13 as installer
except ImportError:
    import media_browser_install13 as installer
BASE=Path(__file__).with_name('media_browser_runtime_base')
CURRENT={'media_browser_account_switch.py','media_browser_account_switch_v3.py','media_browser_legacy_consumer.py','media_browser_legacy_obligations.py'}

def read_source(p):
    p=Path(p);need=lambda x:None if x is True else (_ for _ in ()).throw(RuntimeError('artifact_source_unproved'))
    need(p.is_absolute()and p.resolve()==p);parents=[(x,os.lstat(x))for x in p.parents];need(all(stat.S_ISDIR(s.st_mode)for _,s in parents))
    fd=os.open(p,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK);stamp=lambda s:(s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid,s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
    try:
        a=os.fstat(fd);need(stat.S_ISREG(a.st_mode)and a.st_uid==os.getuid()and a.st_nlink==1 and not(a.st_mode&0o022)and 0<=a.st_size<=1048576);raw=b''
        while len(raw)<a.st_size:
            b=os.read(fd,min(65536,a.st_size-len(raw)));need(bool(b));raw+=b
        need(stamp(a)==stamp(os.fstat(fd))==stamp(os.lstat(p)))
        for x,s in parents:need((s.st_dev,s.st_ino,s.st_mode)==(os.lstat(x).st_dev,os.lstat(x).st_ino,os.lstat(x).st_mode))
        return raw
    finally:os.close(fd)

def build():
    provenance=json.loads(read_source(BASE.with_name('media_browser_runtime_base_provenance.json')))
    result={}
    for n in sorted(installer.FILES):
        p=Path(__file__).with_name(n)if n in CURRENT else BASE/n
        raw=read_source(p)
        if n not in CURRENT and hashlib.sha256(raw).hexdigest()!=provenance['source_sha256'][n]:raise RuntimeError('runtime_base_source_changed')
        result[n]=base64.b64encode(raw).decode()
    raw=json.dumps(result,sort_keys=True,separators=(',',':')).encode()
    if len(raw)>installer.BUDGET:raise RuntimeError('runtime_13_bundle_budget')
    return raw,{n:hashlib.sha256(base64.b64decode(v)).hexdigest()for n,v in result.items()}


# These host-side inputs are consumed by the unchanged actual FixedPodman
# constructor. They are not guest source files and never expand FILES13.
HOST_DEPENDENCIES = {'requirements-linux-arm64-py312.lock': '37901497f34d399c0640da575e1939d5ae755a15009362e6fa2b9c043d9d5832', 'seccomp-playwright-v1.64.1.json': 'cc3e61cabda6bbc1e53e54d27ba4d55a9d3be829b6dd1a596f4a7b31b1cc7849'}

def build_host_dependencies():
    data={}
    for name,pin in HOST_DEPENDENCIES.items():
        raw=read_source(BASE/name)
        if hashlib.sha256(raw).hexdigest()!=pin:raise RuntimeError('browser_fixed_host_dependency_changed')
        data[name]=raw
    return data


HOST_ENGINE_SOURCES = {'engine.py': '3a73b4d5d88802b5108b7dff211280cdeea1eb499a6ab1c8e4b50d77f928a07e', 'owned_container.py': '99dfc2308cef79de046c2e3e31c4535c986294dfa526b5747462bce41a70b6f7'}

def build_host_engine_inputs():
    # Fixed original modules plus their two same-directory data dependencies.
    data=build_host_dependencies()
    for name,pin in HOST_ENGINE_SOURCES.items():
        raw=read_source(BASE.with_name('media_browser_host_base')/name)
        if hashlib.sha256(raw).hexdigest()!=pin:raise RuntimeError('browser_host_engine_source_changed')
        data[name]=raw
    return data

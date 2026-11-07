"""Fixed versioned thirteen-file data installer; original nine-file installer untouched."""
import base64,hashlib,json,os,re,stat
from pathlib import Path

FILES={'__init__.py','entry.py','flow.py','launcher.py','nss_profile.py',
       'trust_controls.py','approved_origins.py','origin_rules.json','normal_tls_provider.py',
       'media_browser_account_switch.py','media_browser_account_switch_v3.py',
       'media_browser_legacy_consumer.py','media_browser_legacy_obligations.py'}
BUDGET=1048576
SOURCE_BUNDLE_SHA256=None

class Unproved(RuntimeError):pass

def need(v):
    if v is not True:raise Unproved('browser_13_source_install_unproved')

def stamp(s):return(s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid,s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
def decode(raw):
    def pairs(rows):
        d={}
        for k,v in rows:need(k not in d);d[k]=v
        return d
    return json.loads(raw,object_pairs_hook=pairs)

def install(raw,parent,*,expected_sha256,nonce):
    need(type(expected_sha256)is str and re.fullmatch('[a-f0-9]{64}',expected_sha256)is not None)
    need(type(raw)is bytes and 0<len(raw)<=BUDGET and hashlib.sha256(raw).hexdigest()==expected_sha256)
    need(type(nonce)is str and re.fullmatch('[a-f0-9]{24}',nonce)is not None)
    bundle=decode(raw);need(type(bundle)is dict and set(bundle)==FILES)
    content={}
    for name,value in bundle.items():
        need(type(value)is str);data=base64.b64decode(value,validate=True)
        need(base64.b64encode(data).decode()==value and len(data)<=262144 and (bool(data)or name=='__init__.py') and b'\x00'not in data);content[name]=data
    parent=Path(parent);need(parent.is_absolute()and parent.resolve()==parent)
    chain=[(p,os.lstat(p))for p in [*reversed(parent.parents),parent]]
    need(all(stat.S_ISDIR(s.st_mode)for _,s in chain))
    d=os.open(parent,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
    held=[]
    try:
        pa=os.fstat(d);need(stat.S_ISDIR(pa.st_mode)and pa.st_uid==os.geteuid())
        def parent_ok():
            need((pa.st_dev,pa.st_ino,pa.st_uid,pa.st_gid,pa.st_mode)==(lambda s:(s.st_dev,s.st_ino,s.st_uid,s.st_gid,s.st_mode))(os.lstat(parent)))
            for p,a in chain:need((a.st_dev,a.st_ino,a.st_mode,a.st_uid,a.st_gid)==(lambda s:(s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid))(os.lstat(p)))
        parent_ok();name='phorge-browser-v3-source-'+nonce
        os.mkdir(name,0o700,dir_fd=d) # Never adopt or overwrite an existing object.
        c=os.open(name,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW,dir_fd=d)
        try:
            ca=os.fstat(c)
            def child_ok():
                parent_ok();s=os.stat(name,dir_fd=d,follow_symlinks=False)
                need(stat.S_ISDIR(s.st_mode)and stat.S_IMODE(s.st_mode)==0o700 and s.st_uid==os.geteuid()and s.st_gid==ca.st_gid and(s.st_dev,s.st_ino)==(ca.st_dev,ca.st_ino)==(os.fstat(c).st_dev,os.fstat(c).st_ino))
            def leaf_ok(fd,filename,a):
                child_ok();s=os.fstat(fd);e=os.stat(filename,dir_fd=c,follow_symlinks=False)
                need(stat.S_ISREG(s.st_mode)and stat.S_IMODE(s.st_mode)==0o600 and s.st_nlink==1 and(s.st_uid,s.st_gid)==(os.geteuid(),a.st_gid)and(s.st_dev,s.st_ino)==(a.st_dev,a.st_ino)and stamp(s)==stamp(e));return s
            def readback(fd,filename,a,data):
                s=leaf_ok(fd,filename,a);need(s.st_size==len(data));os.lseek(fd,0,os.SEEK_SET);got=b''
                while len(got)<=len(data):
                    part=os.read(fd,min(65536,len(data)+1-len(got)))
                    if not part:break
                    got+=part
                need(got==data and stamp(s)==stamp(leaf_ok(fd,filename,a)));return s
            for filename,data in content.items():
                child_ok();fd=os.open(filename,os.O_RDWR|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW|os.O_NONBLOCK,0o600,dir_fd=c);held.append(fd);a=os.fstat(fd);leaf_ok(fd,filename,a)
                view=memoryview(data)
                while view:
                    n=os.write(fd,view);need(type(n)is int and n>0);view=view[n:]
                os.fsync(fd);s=readback(fd,filename,a,data);held[-1]=(fd,filename,a,data,s)
            os.fsync(c);child_ok();need(set(os.listdir(c))==FILES)
            for fd,filename,a,data,s in held:need(stamp(readback(fd,filename,a,data))==stamp(s))
            return {'scope':'owned-browser-versioned13-data-install','directory':str(parent/name),'device':ca.st_dev,'inode':ca.st_ino,'source_bundle_sha256':expected_sha256,'files':13,'host_execution':False}
        finally:
            for item in held:os.close(item[0]if type(item)is tuple else item)
            os.close(c)
    finally:os.close(d)

def install_bound(raw,nonce):
    # Root captures/pins the installer and compiles this role before stdin/input I/O.
    need(type(SOURCE_BUNDLE_SHA256)is str and re.fullmatch('[a-f0-9]{64}',SOURCE_BUNDLE_SHA256)is not None)
    return install(raw,Path('/tmp'),expected_sha256=SOURCE_BUNDLE_SHA256,nonce=nonce)


def execute_bound(stream,nonce):
    need(type(SOURCE_BUNDLE_SHA256)is str and re.fullmatch('[a-f0-9]{64}',SOURCE_BUNDLE_SHA256)is not None)
    return install_bound(stream.read(BUDGET+1),nonce)

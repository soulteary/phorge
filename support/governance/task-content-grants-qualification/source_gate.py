# Exact physical source window for the isolated Task metadata qualifier.
# The expected manifest hash is supplied only by the separately reviewed Root
# runner. Validation is not commit provenance, execution authority or SQL proof.
import hashlib,json,os,re,stat
from pathlib import Path
PARENT_REVISION='5903d98b14926401f0be49cb0a9d6168026a2642'
PARENT_TREE='e678af454b9521bb88951c11bf78b41c535aed34'
MAX_FILES=12000
MAX_TOTAL_BYTES=256*1024*1024
MAX_FILE_BYTES=16*1024*1024

def need(value):
    if value is not True:raise ValueError('task_qualification_source_window_refused')
def sha(raw):return hashlib.sha256(raw).hexdigest()
def decode(raw):
    def pairs(items):
        out={}
        for k,v in items:
            need(k not in out);out[k]=v
        return out
    return json.loads(raw,object_pairs_hook=pairs,parse_constant=lambda _:need(False))
def stamp(st):
    return(st.st_dev,st.st_ino,st.st_mode,st.st_uid,st.st_gid,st.st_nlink,
           st.st_size,st.st_mtime_ns,st.st_ctime_ns)
def read_stable(path,limit,private=False):
    path=Path(path);before=path.lstat()
    need(stat.S_ISREG(before.st_mode) and before.st_nlink==1 and 0<before.st_size<=limit)
    if private:need(before.st_uid==os.geteuid() and stat.S_IMODE(before.st_mode)==0o600)
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    try:
        need(stamp(before)==stamp(os.fstat(fd)));parts=[];size=0
        while size<before.st_size:
            raw=os.read(fd,min(65536,before.st_size-size));need(bool(raw));parts.append(raw);size+=len(raw)
        need(stamp(before)==stamp(os.fstat(fd))==stamp(path.lstat()))
        return b''.join(parts)
    finally:os.close(fd)
def name_ok(name):
    return(type(name)is str and len(name)<=512 and not name.startswith('/') and
           all(p not in ('','.','..','.git') and re.fullmatch(r'[A-Za-z0-9_.@-]+',p)
               for p in name.split('/')))
def private_manifest(path,expected_sha256):
    need(type(expected_sha256)is str and re.fullmatch('[a-f0-9]{64}',expected_sha256)is not None)
    raw=read_stable(path,8*1024*1024,True);need(sha(raw)==expected_sha256);m=decode(raw)
    need(type(m)is dict and set(m)=={'schema','parent_revision','parent_tree','entries'})
    need(m['schema']=='N05-Task-exact-source-window-v1' and m['parent_revision']==PARENT_REVISION and m['parent_tree']==PARENT_TREE)
    entries=m['entries'];need(type(entries)is dict and 1<=len(entries)<=MAX_FILES)
    for name,row in entries.items():
        need(name_ok(name) and type(row)is dict)
        if row.get('kind')=='file':
            need(set(row)=={'kind','mode','bytes','sha256'} and type(row['mode'])is int and row['mode']in(0o644,0o755) and type(row['bytes'])is int and 0<=row['bytes']<=MAX_FILE_BYTES)
        else:
            need(row.get('kind')=='symlink' and set(row)=={'kind','target','sha256'} and type(row['target'])is str and 0<len(row['target'])<=512 and not row['target'].startswith('/'))
        need(type(row['sha256'])is str and re.fullmatch('[a-f0-9]{64}',row['sha256'])is not None)
    return m

def snapshot(root,configuration_sha256=None):
    root=Path(root);need(root.is_absolute() and root.resolve()==root and root.is_dir() and not root.is_symlink())
    root_id=stamp(root.lstat());directories={};out={};total=0;git_metadata=False;configuration=False
    for directory,dirs,files in os.walk(root,followlinks=False):
        directory=Path(directory);st=directory.lstat()
        need(stat.S_ISDIR(st.st_mode) and not(st.st_mode&0o022));directories[directory]=stamp(st)
        # Only top-level Git metadata is outside the executed-source window.
        # It never supplies revision authority; Root checks committed HEAD separately.
        if directory==root and '.git' in dirs:
            g=root/'.git';gs=g.lstat();need(stat.S_ISDIR(gs.st_mode) and not g.is_symlink() and not(gs.st_mode&0o022));dirs.remove('.git');git_metadata=True
        for leaf in list(dirs):
            if (directory/leaf).is_symlink():dirs.remove(leaf);files.append(leaf)
        for leaf in files:
            path=directory/leaf;relative=path.relative_to(root).as_posix();st=path.lstat()
            if relative=='.git':
                need(stat.S_ISREG(st.st_mode) and st.st_nlink==1 and st.st_size<=4096 and not(st.st_mode&0o022));git_metadata=True;continue
            need(name_ok(relative))
            if relative=='conf/local/local.json':
                need(type(configuration_sha256)is str and re.fullmatch('[a-f0-9]{64}',configuration_sha256)is not None)
                need(sha(read_stable(path,65536,True))==configuration_sha256);configuration=True;continue
            if stat.S_ISLNK(st.st_mode):
                target=os.readlink(path);need(len(target)<=512 and not target.startswith('/'))
                need(path.resolve().is_relative_to(root) and stamp(st)==stamp(path.lstat()))
                row={'kind':'symlink','target':target,'sha256':sha(os.fsencode(target))}
            else:
                need(stat.S_ISREG(st.st_mode) and st.st_nlink==1 and st.st_size<=MAX_FILE_BYTES and stat.S_IMODE(st.st_mode)in(0o644,0o755))
                # Empty tracked files are legitimate; read_stable intentionally
                # requires nonempty private roles, so capture empties separately.
                if st.st_size==0:
                    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
                    try:need(stamp(st)==stamp(os.fstat(fd))==stamp(path.lstat()));raw=b''
                    finally:os.close(fd)
                else:raw=read_stable(path,MAX_FILE_BYTES)
                row={'kind':'file','mode':stat.S_IMODE(st.st_mode),'bytes':len(raw),'sha256':sha(raw)};total+=len(raw)
            out[relative]=row;need(len(out)<=MAX_FILES and total<=MAX_TOTAL_BYTES)
    for d,observed in directories.items():need(stamp(d.lstat())==observed)
    need(stamp(root.lstat())==root_id)
    need(configuration_sha256 is None or configuration)
    return {'entries':out,'private_configuration_bound':configuration,
            'git_metadata_excluded':git_metadata,'bytes':total}

def verify(root,manifest_path,expected_manifest_sha256,configuration_sha256=None):
    manifest=private_manifest(manifest_path,expected_manifest_sha256)
    actual=snapshot(root,configuration_sha256);need(actual['entries']==manifest['entries'])
    encoded=(json.dumps(actual['entries'],sort_keys=True,separators=(',',':'))+'\n').encode()
    return {'schema':'N05-Task-source-window-result-v1','parent_revision':PARENT_REVISION,
            'source_files':len(actual['entries']),'source_bytes':actual['bytes'],
            'source_window_sha256':sha(encoded),'source_manifest_sha256':expected_manifest_sha256,
            'private_configuration_bound':actual['private_configuration_bound'],
            'git_metadata_separately_checked_by_Root':False,
            'current_physical_source_verified':True,'committed_source_proved':False,
            'runtime_qualified':False,'SQL_executed':False,'execution_authorized':False}

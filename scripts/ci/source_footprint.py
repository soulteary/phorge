import json, os, stat, subprocess, sys
from pathlib import Path
STAGES = {'before_build','after_build','after_maps','after_verify','before_governance','after_governance'}
def need(ok):
    if not ok: raise ValueError('footprint_contract_refused')
def safe_name(name):
    need(type(name) is str and 0 < len(name) <= 1024 and not name.startswith('/') and all(x not in ('', '.', '..') for x in name.split('/')) and all(ord(c) >= 32 and ord(c) != 127 for c in name))
    return name
def collect(root):
    rows=[]
    flags=os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW|os.O_CLOEXEC
    fd=os.open(root,flags)
    def walk(parent,prefix):
        for name in sorted(os.listdir(parent)):
            if not prefix and name=='.git': continue
            rel=safe_name(prefix+name)
            st=os.stat(name,dir_fd=parent,follow_symlinks=False)
            kind='directory' if stat.S_ISDIR(st.st_mode) else 'regular' if stat.S_ISREG(st.st_mode) else 'symlink' if stat.S_ISLNK(st.st_mode) else 'other'
            rows.append({'path':rel,'type':kind,'mode':format(stat.S_IMODE(st.st_mode),'04o'),'size':st.st_size})
            need(len(rows)<=20000 and type(st.st_size) is int and st.st_size>=0)
            if kind=='directory':
                child=os.open(name,flags,dir_fd=parent)
                try:
                    current=os.fstat(child)
                    need((current.st_dev,current.st_ino)==(st.st_dev,st.st_ino))
                    walk(child,rel+'/')
                finally: os.close(child)
    try: walk(fd,'')
    finally: os.close(fd)
    return rows
def expected(root):
    env={'PATH':os.defpath,'GIT_CONFIG_NOSYSTEM':'1','GIT_CONFIG_GLOBAL':os.devnull,'GIT_NO_REPLACE_OBJECTS':'1'}
    raw=subprocess.run(['git','--no-replace-objects','-C',str(root),'-c','core.hooksPath=/dev/null','ls-tree','-rz','--name-only','HEAD'],env=env,check=True,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=30).stdout
    need(len(raw)<=8*1024*1024 and raw.endswith(b'\0'))
    names=[safe_name(n.decode('utf-8','strict')) for n in raw[:-1].split(b'\0')]
    need(len(names)<=20000 and len(names)==len(set(names)))
    return set(names)
def write_output(directory,stage,data):
    flags=os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW|os.O_CLOEXEC
    parent=os.open(directory,flags)
    try:
        st=os.fstat(parent);need(st.st_uid==os.getuid() and stat.S_IMODE(st.st_mode)==0o700)
        raw=(json.dumps(data,sort_keys=True,separators=(',',':'))+'\n').encode()
        need(len(raw)<=16*1024*1024)
        fd=os.open(stage+'.json',os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW|os.O_CLOEXEC,0o600,dir_fd=parent)
        try:
            os.fchmod(fd,0o600)
            view=memoryview(raw)
            while view:
                written=os.write(fd,view);need(written>0);view=view[written:]
            os.fsync(fd)
            done=os.fstat(fd);need(stat.S_ISREG(done.st_mode) and done.st_nlink==1 and stat.S_IMODE(done.st_mode)==0o600)
        finally: os.close(fd)
        final=os.stat(directory,follow_symlinks=False)
        need((st.st_dev,st.st_ino)==(final.st_dev,final.st_ino))
    finally:os.close(parent)
def main():
    need(len(sys.argv)==2 and sys.argv[1] in STAGES)
    stage=sys.argv[1];root=Path.cwd();rows=collect(root);names=expected(root)
    leaves={x['path'] for x in rows if x['type']!='directory'}
    extra=sorted(leaves-names);missing=sorted(names-leaves)
    need(len(extra)<=4000 and len(missing)<=4000)
    write_output(Path(os.environ['RUNNER_TEMP'])/'atomic-footprint'/'records',stage,{'schema':1,'stage':stage,'truncated':False,'rows':rows,'extra_names':extra,'missing_names':missing})
if __name__=='__main__':
    try:main()
    except Exception:
        print('atomic_footprint_diagnostic_refused',file=sys.stderr)
        raise SystemExit(1)

#!/usr/bin/env python3
"""Whitelist diagnostic clues only; never print native stderr/message/arguments."""
import argparse,hashlib,importlib.util,json,os,re,stat,subprocess
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
sp=importlib.util.spec_from_file_location('directory_failure_gate',ROOT/'scripts/governance/check_directory_transports_v1.py')
gate=importlib.util.module_from_spec(sp);sp.loader.exec_module(gate)
PREFIX=b'native-directory-transports-failure-v1 '
ALLOW='support/governance/native-organization/directory-transport-diagnostic-v1.json'
def raw_stderr(path):
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    try:
        before=os.fstat(fd);gate.need(stat.S_ISREG(before.st_mode) and before.st_nlink==1 and before.st_size<=1024*1024)
        data=b''
        while len(data)<before.st_size:
            block=os.read(fd,min(65536,before.st_size-len(data)));gate.need(bool(block));data+=block
        gate.need(os.read(fd,1)==b'')
        stamp=lambda s:(s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid,s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
        gate.need(stamp(before)==stamp(os.fstat(fd))==stamp(os.stat(path,follow_symlinks=False)))
        return data
    finally:os.close(fd)
def paths(root):
    out=set();raw=subprocess.check_output(['git','-C',str(root),'ls-tree','-rz','HEAD','--','src','support'])
    for row in raw.split(b'\0'):
        if not row:continue
        meta,name=row.split(b'\t',1);mode,kind,_=meta.split();name=name.decode('utf-8')
        if mode in {b'100644',b'100755'} and kind==b'blob' and name.endswith('.php'):out.add(name)
    return out

def project(raw,root=ROOT):
    gate.need(type(raw) is bytes and len(raw)<=1024*1024)
    allow=gate.read_json(Path(root)/ALLOW);gate.exact(allow['schema'],'native-directory-failure-allowlist-v1')
    known=paths(root);records=[]
    for line in raw.splitlines():
        if not line.startswith(PREFIX):continue
        gate.need(len(line)<=2048 and len(records)<8)
        item=json.loads(line[len(PREFIX):],object_pairs_hook=gate.unique)
        gate.need(type(item) is dict and set(item)=={'schema','kind','class','source','line','stage'})
        gate.exact(item['schema'],'native-directory-failure-marker-v1')
        gate.need(type(item['kind']) is str and item['kind'] in {'primary','cleanup'})
        gate.need(type(item['class']) is str and item['class'] in allow['classes'])
        gate.need(type(item['stage']) is str and item['stage'] in allow['stages'])
        source=item['source'];number=item['line']
        gate.need(type(source) is str and type(number) is int)
        if source=='unknown-source':gate.exact(number,0)
        else:
            gate.need(len(source)<=384 and re.fullmatch(r'(?:src|support)/[A-Za-z0-9_./-]+\.php',source) is not None)
            gate.need(not any(p in {'','.','..'} for p in source.split('/')) and source in known)
            gate.need(1<=number<=1000000 and number<=len((Path(root)/source).read_bytes().splitlines()))
        gate.need(item not in records);records.append(item)
    gate.need(bool(records))
    return dict(schema='native-directory-safe-failure-v1',diagnostic_only=True,selected_matrix_accepted=False,
        runtime_authority=False,stderr_bytes=len(raw),stderr_sha256=hashlib.sha256(raw).hexdigest(),failures=records)

TERMINALS=frozenset({'native-exit','native-stderr','strict-consumer'})
def optional_summary(path):
    try:raw=raw_stderr(path)
    except FileNotFoundError:return dict(available=False)
    return dict(available=True,bytes=len(raw),sha256=hashlib.sha256(raw).hexdigest())
def project_terminal(raw,terminal,receipt_path,consumer_path,root=ROOT):
    gate.need(terminal in TERMINALS)
    # A missing marker is diagnostic unavailability, never license to ignore a
    # malformed/extra marker. The original strict projector remains unchanged.
    if any(line.startswith(PREFIX) for line in raw.splitlines()):out=project(raw,root)
    else:
        gate.need(type(raw) is bytes and len(raw)<=1024*1024)
        out=dict(schema='native-directory-safe-failure-v1',diagnostic_only=True,
            selected_matrix_accepted=False,runtime_authority=False,
            stderr_bytes=len(raw),stderr_sha256=hashlib.sha256(raw).hexdigest(),failures=[])
    out['terminal_classification']=terminal
    out['private_receipt']=optional_summary(receipt_path)
    out['private_consumer_output']=optional_summary(consumer_path)
    return out

def main():
    parser=argparse.ArgumentParser();parser.add_argument('--stderr',required=True);parser.add_argument('--source-revision',required=True);parser.add_argument('--terminal',choices=sorted(TERMINALS));parser.add_argument('--receipt');parser.add_argument('--consumer-output');args=parser.parse_args()
    try:
        gate.source(ROOT,args.source_revision)
        raw=raw_stderr(args.stderr)
        if args.terminal:
            gate.need(type(args.receipt) is str and type(args.consumer_output) is str)
            result=project_terminal(raw,args.terminal,args.receipt,args.consumer_output)
        else:result=project(raw)
        result['source_revision']=args.source_revision
        print(json.dumps(result,sort_keys=True));return 0
    except Exception:
        print('{"schema":"native-directory-safe-failure-v1","diagnostic_available":false,"selected_matrix_accepted":false}');return 1
if __name__=='__main__':raise SystemExit(main())

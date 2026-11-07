"""Private stdin-only browser entry; trusted image anchor required first.

Run by the fixed parent through /usr/bin/python3 -I -B, never a tmpfs shebang.
Only the image's immutable reviewed anchor can establish browser/NSS hashes.
"""
import hashlib
import importlib
import importlib.util
import json
import os
from pathlib import Path
import re
import ssl
import stat
import sys

ANCHOR = Path('/opt/phorge-media-browser/runtime-anchor.json')
ANCHOR_SHA256 = None
HOME_PARENT = Path('/home/pwuser')


class EntryUnproved(RuntimeError):
    pass


def need(value):
    if value is not True: raise EntryUnproved('fixed_browser_entry_unproved')


def decode(raw):
    def pairs(values):
        out={}
        for k,v in values:
            need(k not in out);out[k]=v
        return out
    return json.loads(raw,object_pairs_hook=pairs,
        parse_constant=lambda _: (_ for _ in ()).throw(EntryUnproved('fixed_browser_JSON_unproved')))


def read_anchor():
    need(type(ANCHOR_SHA256) is str and re.fullmatch('[a-f0-9]{64}',ANCHOR_SHA256) is not None)
    fd=os.open(ANCHOR,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    try:
        st=os.fstat(fd)
        need(stat.S_ISREG(st.st_mode) and st.st_uid==0 and st.st_nlink==1 and not st.st_mode&0o222 and st.st_size<=65536)
        raw=os.read(fd,65537); after=os.fstat(fd)
        need(len(raw)==st.st_size and (st.st_dev,st.st_ino,st.st_mtime_ns,st.st_ctime_ns)==(after.st_dev,after.st_ino,after.st_mtime_ns,after.st_ctime_ns))
        need(hashlib.sha256(raw).hexdigest()==ANCHOR_SHA256)
        value=decode(raw)
        return validate_anchor(value)
    finally:os.close(fd)


def validate_anchor(value):
    # Acyclic: tool measurement A exists before entry(A) and bundle B. Source
    # revision/bundle authority belongs to the independent fixed parent chain.
    need(type(value) is dict and set(value)=={'schema_version','browser_sha256','certutil_sha256'})
    need(type(value['schema_version']) is int and value['schema_version']==1)
    need(all(type(value[k]) is str and re.fullmatch('[a-f0-9]{64}',value[k]) is not None for k in ['browser_sha256','certutil_sha256']))
    return value


def private_home(nonce, owner, certificate=None):
    need(type(nonce) is str and re.fullmatch('[a-f0-9]{24}',nonce) is not None)
    root=HOME_PARENT
    need(root.resolve()==root)
    parent=os.open(root,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
    try:
        before=os.fstat(parent)
        need(before.st_uid==1000)
        name='phorge-browser-'+nonce
        os.mkdir(name,0o700,dir_fd=parent)
        fd=os.open(name,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW,dir_fd=parent)
        try:
            initial=os.fstat(fd)
            for filename,data in [('owner',owner.encode())]+([('test-ca.pem',certificate)] if certificate is not None else []):
                target=os.open(filename,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600,dir_fd=fd)
                try:
                    view=memoryview(data)
                    while view:
                        n=os.write(target,view);need(n>0);view=view[n:]
                    os.fsync(target)
                finally:os.close(target)
            os.fsync(fd)
            now=os.stat(name,dir_fd=parent,follow_symlinks=False)
            outer=os.stat(root,follow_symlinks=False)
            need((before.st_dev,before.st_ino)==(outer.st_dev,outer.st_ino))
            need((initial.st_dev,initial.st_ino)==(now.st_dev,now.st_ino) and now.st_uid==1000 and stat.S_IMODE(now.st_mode)==0o700)
            return root/name,(initial.st_dev,initial.st_ino)
        finally:os.close(fd)
    finally:os.close(parent)


def execute(raw):
    # No input bytes can turn unpinned image provenance into authority.
    anchor=read_anchor()
    need(sys.platform=='linux' and os.geteuid()==1000 and type(raw) is bytes and len(raw)<=1048576)
    data=decode(raw)
    need(type(data) is dict and set(data)=={'schema_version','nonce','owner','manifest','runtime'})
    need(type(data['schema_version']) is int and data['schema_version']==1)
    need(type(data['owner']) is str and re.fullmatch('[a-f0-9]{64}',data['owner']) is not None)
    runtime=data['runtime']
    need(type(runtime) is dict and set(runtime)=={'root_pem','root_DER_sha256','wrong_leaf_origin','untrusted_nonce'})
    need(type(runtime['root_pem']) is str and 0<len(runtime['root_pem'])<=16384)
    pem=runtime['root_pem'].encode('ascii'); der=ssl.PEM_cert_to_DER_cert(runtime['root_pem'])
    need(hashlib.sha256(der).hexdigest()==runtime['root_DER_sha256'])
    need(type(runtime['wrong_leaf_origin']) is str and re.fullmatch(r'https://app\.media\.localhost:[1-9][0-9]{0,4}',runtime['wrong_leaf_origin']) is not None)
    need(runtime['untrusted_nonce']!=data['nonce'])
    directory=Path(__file__).parent
    spec=importlib.util.spec_from_file_location('owned_browser_candidate',directory/'__init__.py',submodule_search_locations=[str(directory)])
    package=importlib.util.module_from_spec(spec);sys.modules[spec.name]=package;spec.loader.exec_module(package)
    launcher=importlib.import_module('.launcher',package.__name__)
    # Only the independently fixed readonly anchor supplies tool identities.
    launcher.BROWSER_SHA256=anchor['browser_sha256']
    launcher.nss_profile.CERTUTIL_SHA256=anchor['certutil_sha256']
    untrusted,untrusted_id=private_home(runtime['untrusted_nonce'],data['owner'])
    trusted,trusted_id=private_home(data['nonce'],data['owner'],pem)
    os.environ['HOME']=str(trusted)
    from playwright.sync_api import sync_playwright
    with sync_playwright() as browser:
        result=launcher.run(browser,data['manifest'],untrusted_home=untrusted,untrusted_identity=untrusted_id,
            trusted_home=trusted,trusted_identity=trusted_id,owner=data['owner'],root_DER_sha256=runtime['root_DER_sha256'],
            wrong_leaf_origin=runtime['wrong_leaf_origin'])
    # Entire ephemeral homes are removed by the parent's stable-ID container
    # cleanup. Closing browser contexts/driver has succeeded before this return.
    return {'schema_version':1,'scope':'fixed-browser-entry-v1','passed':True,
            'browser_flow':result,'owned_entry_cleanup':True,'deployment':False}


def main():
    try:
        value=execute(sys.stdin.buffer.read(1048577))
    except Exception:
        value={'schema_version':1,'scope':'fixed-browser-entry-v1','passed':False,
               'browser_flow':None,'owned_entry_cleanup':False,'deployment':False}
        print(json.dumps(value,sort_keys=True));return 1
    print(json.dumps(value,sort_keys=True));return 0


if __name__=='__main__':sys.exit(main())

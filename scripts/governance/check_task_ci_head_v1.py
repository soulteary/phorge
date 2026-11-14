#!/usr/bin/env python3
"""Bind externally supplied CI event head to exact Root-signed checkout.
No HEAD auto-adoption, key generation/import, private key, engine or SQL.
Runner must install the already-known PUBLIC verifier key before this command.
"""
import argparse,hashlib,json,os,re,stat,subprocess,sys
from pathlib import Path
SIGNER='CD85ED7465AFF2FFA2FFB686DBCDD8CBF440F8DE'
REPOSITORY='soulteary/phorge'
PUBLIC_KEY_SHA256='d3ffbebe7d73c4223e3a85e924913dcbd7ce0492649b117c8110074d4c5ffa7d'
def need(v):
 if v is not True:raise ValueError('task_CI_head_admission_refused')
def hex40(v):return type(v)is str and re.fullmatch('[a-f0-9]{40}',v)is not None and v!='0'*40
def decode(raw):
 def pairs(items):
  out={}
  for k,v in items:need(k not in out);out[k]=v
  return out
 return json.loads(raw,object_pairs_hook=pairs,parse_constant=lambda _:need(False))
def bind_event(name,event,expected_head,github_sha):
 need(type(event)is dict and hex40(expected_head)and hex40(github_sha))
 need(event.get('repository',{}).get('full_name')==REPOSITORY)
 if name=='pull_request':
  head=event.get('pull_request',{}).get('head',{}).get('sha');need(hex40(head)and head==expected_head)
  role='PR-context-may-be-merge-commit'
 elif name=='workflow_dispatch':need(expected_head==github_sha);role='dispatch-external-context-commit'
 elif name=='push':need(event.get('deleted')is False and event.get('after')==expected_head==github_sha);role='push-external-event-commit'
 else:raise ValueError('task_CI_event_not_supported')
 return {'schema':'task-CI-expected-event-head-v1','event_name':name,'expected_checkpoint':expected_head,'github_context_sha':github_sha,'github_context_role':role,'GITHUB_SHA_rewritten':False,'HEAD_adopted_as_authority':False}
def read_event(path):
 path=Path(path);need(path.is_absolute()and path.resolve()==path);a=path.lstat()
 need(stat.S_ISREG(a.st_mode)and a.st_nlink==1 and a.st_uid==os.geteuid()and not a.st_mode&0o022 and 0<a.st_size<=4*1024*1024)
 fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
 try:
  raw=bytearray()
  while len(raw)<a.st_size:
   b=os.read(fd,min(65536,a.st_size-len(raw)));need(bool(b));raw.extend(b)
  key=lambda s:(s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid,s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
  need(os.read(fd,1)==b''and key(a)==key(os.fstat(fd))==key(path.lstat()));return bytes(raw)
 finally:os.close(fd)
def prepare_public_trust(home,key_path):
 home=Path(home);key_path=Path(key_path)
 need(home.is_absolute()and home.parent.resolve()==home.parent and not home.exists()and not home.is_symlink())
 raw=read_event(key_path);need(hashlib.sha256(raw).hexdigest()==PUBLIC_KEY_SHA256)
 # Only the exact Root-provided PUBLIC key can enter this NEW owned home.
 # No default user keyring, ownertrust, private key or signing operation.
 home.mkdir(mode=0o700);ring=home/'.gnupg';ring.mkdir(mode=0o700)
 env={'PATH':'/usr/bin:/bin','HOME':str(home),'LANG':'C','GNUPGHOME':str(ring)}
 command=['/usr/bin/gpg','--batch','--no-options','--no-autostart','--homedir',str(ring)]
 shown=subprocess.run(command+['--with-colons','--import-options','show-only','--import'],input=raw,env=env,capture_output=True,check=True,timeout=10)
 rows=shown.stdout.decode().splitlines();fingerprints=[r.split(':')[9]for r in rows if r.startswith('fpr:')]
 need(fingerprints and fingerprints[0]==SIGNER and not any(r.startswith(('sec:','ssb:'))for r in rows))
 subprocess.run(command+['--import-options','import-minimal','--import'],input=raw,env=env,capture_output=True,check=True,timeout=10)
 (home/'public-source-trust.json').write_text(json.dumps({'schema':'task-CI-public-verifier-home-v1','public_key_sha256':PUBLIC_KEY_SHA256,'primary_fingerprint':SIGNER,'private_key_used':False},sort_keys=True)+'\n');(home/'public-source-trust.json').chmod(0o600)
 return {'public_trust_prepared':True,'HOME':str(home),'public_key_sha256':PUBLIC_KEY_SHA256,'private_key_used':False,'default_keyring_modified':False}
def verify_checkout(root,expected_head,trust_home=None):
 need(hex40(expected_head));root=Path(root);need(root.is_absolute()and root.resolve()==root)
 # Do not inherit Git worktree/object/config selectors from an ambient caller.
 env={k:v for k,v in os.environ.items()if not k.startswith('GIT_')}
 if trust_home is not None:
  home=Path(trust_home);need(home.is_absolute()and home.resolve()==home and stat.S_IMODE(home.stat().st_mode)==0o700 and home.stat().st_uid==os.geteuid())
  ring=home/'.gnupg';need(ring.resolve()==ring and ring.is_dir()and not ring.is_symlink()and stat.S_IMODE(ring.stat().st_mode)==0o700 and ring.stat().st_uid==os.geteuid())
  proof=decode(read_event(home/'public-source-trust.json'));need(proof=={'schema':'task-CI-public-verifier-home-v1','public_key_sha256':PUBLIC_KEY_SHA256,'primary_fingerprint':SIGNER,'private_key_used':False})
  need(not any((home/'.gnupg'/name).exists()for name in('gpg.conf','common.conf','gpg-agent.conf','scdaemon.conf')))
  env['HOME']=str(home);env.pop('GNUPGHOME',None)
 env.update({'GIT_CONFIG_NOSYSTEM':'1','GIT_CONFIG_SYSTEM':'/dev/null','GIT_CONFIG_GLOBAL':'/dev/null','GIT_NO_REPLACE_OBJECTS':'1','GIT_NO_LAZY_FETCH':'1','GIT_TERMINAL_PROMPT':'0'})
 def git(*args):return subprocess.run(['git','--no-replace-objects','-c','core.hooksPath=/dev/null','-c','core.fsmonitor=false','-c','gpg.format=openpgp','-c','gpg.program=/usr/bin/gpg','-c','gpg.openpgp.program=/usr/bin/gpg','-C',str(root),*args],env=env,stdin=subprocess.DEVNULL,capture_output=True,check=True,timeout=10)
 need(git('rev-parse','HEAD').stdout.decode().strip()==expected_head)
 signature=git('verify-commit','--raw',expected_head)
 bad=rb'^\[GNUPG:\] (?:BADSIG|ERRSIG|EXPSIG|EXPKEYSIG|REVKEYSIG|KEYREVOKED|SIGEXPIRED|KEYEXPIRED)(?: |$)'
 need(re.search(bad,signature.stderr,re.M)is None)
 need(re.findall(rb'^\[GNUPG:\] VALIDSIG ([A-F0-9]{40}) ',signature.stderr,re.M)==[SIGNER.encode()])
 git('diff','--quiet',expected_head,'--');git('diff','--cached','--quiet',expected_head,'--')
 return {'exact_checkout_matches_external_event_head':True,'Root_signer_fingerprint_matched':True,'private_key_used':False,'keys_imported_or_generated':False,'public_verification_key_must_already_exist':True}
def verify(root,event_file,event_name,expected_head,github_sha,trust_home=None):
 raw=read_event(event_file);result=bind_event(event_name,decode(raw),expected_head,github_sha);result.update(verify_checkout(root,expected_head,trust_home));result.update(event_file_sha256=hashlib.sha256(raw).hexdigest(),source_inventory_or_SQL_qualified=False);return result
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--prepare-public-trust',action='store_true');p.add_argument('--public-key');p.add_argument('--trust-home')
 for name in('root','event-file','event-name','expected-head','github-sha'):p.add_argument('--'+name)
 a=p.parse_args()
 try:
  if a.prepare_public_trust:
   need(a.public_key is not None and a.trust_home is not None);print(json.dumps(prepare_public_trust(a.trust_home,a.public_key),sort_keys=True))
  else:
   need(all(getattr(a,n)is not None for n in('root','event_file','event_name','expected_head','github_sha')));print(json.dumps(verify(a.root,a.event_file,a.event_name,a.expected_head,a.github_sha,a.trust_home),sort_keys=True))
 except Exception:print('{"CI_head_admitted":false,"source_inventory_or_SQL_qualified":false}');raise SystemExit(1)

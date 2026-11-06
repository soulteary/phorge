#!/usr/bin/env python3
"""Fixed CI prepare/consumer runner; no caller descriptor or digest input."""
import argparse,io,json,os,re,secrets,stat,subprocess,tarfile,shutil,sys
from pathlib import Path
import build_owner
import execution
from provenance import source_bindings,decode,digest,verify_build_info
from anchor import canonical,create_seed,close_anchor
ROOT=Path(__file__).resolve().parents[3]
SOURCE=Path('/tmp/governance-src');BINDIR=Path('/tmp/governance-bin');BINARY=BINDIR/'native-rr-process-owner-ci'
def command(args,*,cwd=None,env=None):
 r=subprocess.run(args,cwd=cwd,env=env,capture_output=True)
 if r.returncode:raise RuntimeError('fixed CI preparation command failed')
 return r.stdout
def private_file(path,raw,mode=0o400):
 fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
 with os.fdopen(fd,'wb')as stream:stream.write(raw);stream.flush();os.fsync(stream.fileno());os.fchmod(stream.fileno(),mode);s=os.fstat(stream.fileno())
 n=path.lstat()
 if(n.st_dev,n.st_ino)!=(s.st_dev,s.st_ino)or path.read_bytes()!=raw:raise RuntimeError('private output identity changed')
 return s
class OwnedPublic:
 ALLOWED={'build-record.json','build-info.json','native-rr-process-owner','mysql-service.json','native.json','integration.json','whole-runtime.json','strict-accepted.json','strict-cli.json','prepare-result.json','checkpoint.json'}
 REQUIRED=ALLOWED-{'checkpoint.json'}
 def __init__(self,path,fd,identity):self.path=path;self.fd=fd;self.identity=identity;self.entries={}
 @classmethod
 def create(cls,path):
  # Assignment to the runner's public variable happens only after this succeeds.
  path.mkdir(mode=0o700)
  fd=None
  try:
   fd=os.open(path,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW);identity=os.fstat(fd)
   obj=cls(path,fd,identity);obj.verify();return obj
  except BaseException:
   if fd is not None:os.close(fd)
   raise
 def verify(self):
  current=self.path.lstat();held=os.fstat(self.fd)
  if self.path.is_symlink()or not stat.S_ISDIR(current.st_mode)or(current.st_dev,current.st_ino)!=(self.identity.st_dev,self.identity.st_ino)or(held.st_dev,held.st_ino)!=(self.identity.st_dev,self.identity.st_ino)or current.st_uid!=os.getuid()or stat.S_IMODE(current.st_mode)!=0o700:raise RuntimeError('public_directory_ownership_unproved')
  return True
 def file(self,name):
  self.read(name) # Refuse a replaced/unregistered receipt before handing a path to CLI.
  return self.path/name
 def write(self,name,raw,mode=0o400):
  self.verify()
  if name not in self.ALLOWED:raise ValueError('unknown public file')
  fd=os.open(name,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600,dir_fd=self.fd)
  with os.fdopen(fd,'wb')as stream:
   stream.write(raw);stream.flush();os.fsync(stream.fileno());os.fchmod(stream.fileno(),mode);identity=os.fstat(stream.fileno())
  self.entries[name]=(identity.st_dev,identity.st_ino,mode,len(raw),digest(raw))
  os.fsync(self.fd);self.verify()
  if self.read(name)!=raw:raise RuntimeError('public_file_readback_unproved')
 def read(self,name):
  self.verify()
  if name not in self.entries:raise RuntimeError('unregistered_public_file')
  fd=os.open(name,os.O_RDONLY|os.O_NOFOLLOW,dir_fd=self.fd)
  try:
   info=os.fstat(fd);expected=self.entries[name]
   if not stat.S_ISREG(info.st_mode)or info.st_uid!=os.getuid()or info.st_nlink!=1 or(info.st_dev,info.st_ino,stat.S_IMODE(info.st_mode),info.st_size)!=expected[:4]or info.st_size>67108864:raise RuntimeError('public_file_identity_unproved')
   with os.fdopen(fd,'rb',closefd=False)as stream:raw=stream.read(67108865)
   after=os.fstat(fd)
   if(info.st_dev,info.st_ino,info.st_size)!=(after.st_dev,after.st_ino,after.st_size)or digest(raw)!=expected[4]:raise RuntimeError('public_file_changed')
  finally:os.close(fd)
  self.verify();return raw
 def hashes(self):
  self.verify();names=set(os.listdir(self.fd))
  if names!=self.REQUIRED:raise RuntimeError('public_evidence_inventory_unproved')
  result={name:digest(self.read(name))for name in sorted(names)};self.verify();return result
 def close(self):
  if self.fd is not None:os.close(self.fd);self.fd=None

def remove_owned_directory(path,identity,allowed=None):
 s=path.lstat()
 if path.is_symlink()or(s.st_dev,s.st_ino)!=(identity.st_dev,identity.st_ino)or s.st_uid!=os.getuid()or stat.S_IMODE(s.st_mode)!=0o700:raise RuntimeError('owned directory unknown retained')
 if allowed is not None and set(p.name for p in path.iterdir())-set(allowed):raise RuntimeError('unknown directory entry retained')
 shutil.rmtree(path)
 if path.exists()or path.is_symlink():raise RuntimeError('owned directory removal unproved')
def main(argv=None):
 p=argparse.ArgumentParser();p.add_argument('--go',required=True,type=Path);p.add_argument('--execute-sql',action='store_true');a=p.parse_args(argv)
 os.umask(0o077)
 head=command(['git','-C',str(ROOT),'rev-parse','HEAD']).decode().strip();identity=build_owner.ci_identity(os.environ,head)
 if command(['git','-C',str(ROOT),'status','--porcelain','--untracked-files=all']):raise ValueError('current clean signed-source checkout required')
 sources=source_bindings(ROOT,lambda path:command(['git','-C',str(ROOT),'show',head+':'+path]))
 temp=Path(os.environ.get('RUNNER_TEMP',''))
 if not temp.is_absolute()or temp.is_symlink()or not temp.is_dir():raise ValueError('trusted CI temporary root required')
 owner=secrets.token_hex(16);output=temp/('native-rr-run-'+owner);output.mkdir(mode=0o700)
 output_identity=output.lstat();seed_dir=Path('/tmp')/('governance-native-rr-ci-'+owner)
 # This external lease is persisted before any source stage, seed or binary mutation.
 private_file(output/'owner-lease.json',canonical({'schema_version':1,'owner':owner,**identity,'source_sha256':sources,'stage':str(SOURCE),'binary':str(BINARY),'anchor_directory':str(seed_dir)}))
 public=None;lease=None;source_identity=None;binary_identity=None;bindir_identity=None;SQL_started=False;SQL_cleanup_proved=False;primary=None;seed_attempted=False;source_attempted=False;binary_attempted=False
 result={'scope':'isolated-hosted-RR21-build-anchor-and-native-fixture','source_revision':head,'source_kind':identity['source_kind'],'PR_head_measured':False,'passed':False,'SQL_executed':False,'helper_executed':False,'portable_RR21_accepted':False,'production_authority':False,'cleanup_failures':[]}
 try:
  for path in (SOURCE,BINDIR,seed_dir):
   if path.exists()or path.is_symlink():raise ValueError('fixed isolated target preexists')
  seed_attempted=True
  lease=create_seed(seed_dir,identity,sources,owner)
  build_dir=output/'build';build_owner.main(['--go',str(a.go),'--output-dir',str(build_dir)])
  actual_info=decode(command([str(a.go),'version','-m','-json',str(build_dir/'native-rr-process-owner')]))
  verify_build_info(actual_info,head,'amd64')
  archive=command(['git','-C',str(ROOT),'archive',head]);expected_runtime,aliases=execution.source_manifest(archive);source_attempted=True;SOURCE.mkdir(mode=0o700);source_identity=SOURCE.lstat()
  with tarfile.open(fileobj=io.BytesIO(archive))as tar:
   if any(n=='src/.phutil_module_cache'or n.startswith('src/.phutil_module_cache/')for n in tar.getnames()):raise ValueError('cold source contains symbol cache')
  execution.extract_current_archive(archive,SOURCE)
  if any(digest((SOURCE/path).read_bytes())!=pin for path,pin in sources.items()):raise ValueError('staged critical source differs')
  php=Path('/usr/bin/php')
  if command([str(php),'-r','echo PHP_VERSION;'])!=b'8.5.12':raise ValueError('exact native PHP required')
  signed_map=command(['git','-C',str(ROOT),'show',head+':src/__phutil_library_map__.php'])
  if(SOURCE/'src/__phutil_library_map__.php').read_bytes()!=signed_map:raise ValueError('staged signed map mismatch')
  command([str(php),'scripts/runtime/build.php','xhpast'],cwd=SOURCE)
  if(SOURCE/'src/__phutil_library_map__.php').read_bytes()!=signed_map:raise ValueError('parser preparation changed signed application map')
  command([str(php),'bin/rebuild-library-map',str(SOURCE/'src')],cwd=SOURCE)
  if(SOURCE/'src/__phutil_library_map__.php').read_bytes()!=signed_map:raise ValueError('cold mapper source mismatch')
  if not BINDIR.exists():BINDIR.mkdir(mode=0o700);bindir_identity=BINDIR.lstat()
  elif BINDIR.is_symlink()or not BINDIR.is_dir():raise ValueError('binary directory unknown')
  binary_attempted=True
  binary_identity=private_file(BINARY,(build_dir/'native-rr-process-owner').read_bytes(),0o500)
  parser=SOURCE/'support/runtime/src/parser/xhpast/bin/xhpast'
  current_sources=source_bindings(ROOT,lambda path:command(['git','-C',str(ROOT),'show',head+':'+path]))
  control=close_anchor(lease,build_dir,actual_info,php.read_bytes(),parser.read_bytes(),current_sources,a.go.read_bytes())
  private_file(output/'external-control.json',canonical(control))
  env=dict(os.environ,PHORGE_GOVERNANCE_ISOLATED_TEST='1',PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION=head,PHORGE_GOVERNANCE_NATIVE_RR_EXECUTION_PROFILE=identity['profile'],PHORGE_GOVERNANCE_NATIVE_RR_CI_CONTROL=canonical(control).decode())
  smoke='require $argv[1];$p=GovernanceNativeRRCIProfile::fromEnvironment();echo json_encode(array("profile_accepted"=>true,"production_authority"=>false));'
  smoke_raw=command([str(php),'-r',smoke,str(SOURCE/'support/governance/recovery/native-writer-rr-ci-profile.php')],env=env)
  if decode(smoke_raw)!={'profile_accepted':True,'production_authority':False}:raise ValueError('actual PHP consumer refused')
  result.update(passed=True,external_anchor_verified_by_PHP=True,actual_build_provenance_verified=True,cold_mapper_executed=True,source_classmap_sha256=digest(signed_map))
  # Publish only provenance/binary and fixed booleans, never owner controls,
  # lease paths, raw nonces, private source stages or configuration.
  public=OwnedPublic.create(temp/'native-rr-owner-build')
  for name in ('build-record.json','build-info.json','native-rr-process-owner'):
   public.write(name,(build_dir/name).read_bytes(),0o500 if name=='native-rr-process-owner'else 0o400)
  if a.execute_sql:
   service_id=os.environ.get('NATIVE_RR_MYSQL_SERVICE_ID','')
   if not re.fullmatch('[a-f0-9]{64}',service_id):raise ValueError('fixed CI MySQL service ID required')
   service=execution.service_identity(decode(command(['docker','--context','default','inspect','--type','container',service_id])),service_id)
   public.write('mysql-service.json',canonical(service))
   # Readiness is supplied by the owned CI service; this real connection also
   # refuses another MySQL version/role/proxy before creating any fixture data.
   mysql_code='$c=new mysqli("127.0.0.1","root","native-rr-ci-disposable-only","",3306);$r=$c->query("SELECT VERSION() version,CURRENT_USER() principal,CURRENT_ROLE() role,@@proxy_user proxy")->fetch_assoc();$c->close();echo json_encode($r);'
   mysql=decode(command([str(php),'-r',mysql_code]))
   execution.exact(mysql,{'version':'8.4.13','principal':'root@%','role':'NONE','proxy':None})
   pure_raw=command([str(php),str(SOURCE/'tests/governance/run.php')],cwd=SOURCE,env=env)
   pure=decode(pure_raw);pure_accepted=execution.validate_pure(pure,execution.pure_inventory(SOURCE))
   public.write('native.json',pure_raw);result['pure_methods']=280;result['pure_classes']=49;result['new_pure_executions']=1
   # Persist outside the staged source BEFORE the real producer can CREATE.
   metadata_user='gov_c_metadata_'+secrets.token_hex(8);metadata_owner=secrets.token_hex(32);measurement_run=secrets.token_hex(16)
   private_file(output/'metadata-owner.json',canonical({'source_revision':head,'run_id':identity['run_id'],'run_attempt':identity['run_attempt'],'principal':metadata_user,'host':'%','owner_nonce':metadata_owner,'measurement_run_nonce':measurement_run,'registered_before_SQL':True}))
   env.update(PHORGE_GOVERNANCE_FIXTURE_DSN='mysql:host=127.0.0.1;port=3306;dbname=governance_fixture_bootstrap;charset=utf8mb4',PHORGE_GOVERNANCE_FIXTURE_USER='root',PHORGE_GOVERNANCE_FIXTURE_PASSWORD=execution.MYSQL_PASSWORD,PHORGE_GOVERNANCE_METADATA_PRINCIPAL_NAME=metadata_user,PHORGE_GOVERNANCE_METADATA_OWNER_NONCE=metadata_owner,PHORGE_GOVERNANCE_METADATA_RUN_NONCE=measurement_run)
   SQL_started=True;result['SQL_executed']=True
   native=subprocess.run([str(php),str(SOURCE/'support/governance/native-organization/test-native-organization-mysql.php'),'--native-user-rr-mdl-only'],cwd=SOURCE,env=env,capture_output=True)
   # Store only bounded native JSON, never raw stderr/SQL/configuration streams.
   if native.stderr!=b'':raise ValueError('native RR stderr must be empty')
   receipt=decode(native.stdout);public.write('integration.json',native.stdout)
   whole=execution.whole_runtime(SOURCE,expected_runtime,aliases);public.write('whole-runtime.json',canonical(whole))
   SQL_cleanup_proved=execution.cleanup_proved(receipt,head)
   if native.returncode!=0:raise ValueError('actual native RR case failed')
   accepted,cli_raw=execution.strict_accept(SOURCE,receipt,head,public.file('integration.json'))
   public.verify()
   public.write('strict-accepted.json',canonical(accepted));public.write('strict-cli.json',cli_raw)
   if not SQL_cleanup_proved:raise ValueError('native SQL cleanup is unproved')
   # Only actual fixture/probe execution and strict acceptance reach this state.
   result.update(portable_RR21_accepted=True,helper_executed=True,SQL_checks=21,component_SQL_obligations=12,runtime_files_exact=whole['actual_files'],new_mapper_executions=1,new_pure_executions=1,SQL_cleanup_proved=True,strict_CLI_equals_inprocess=True)
 except BaseException as error:primary=error;result['passed']=False;result['error_type']=type(error).__name__;raise
 finally:
  # Unknown SQL lifecycle retains the stage and owner artifacts for reconciliation.
  retain=SQL_started and not SQL_cleanup_proved
  actions=[]
  for attempted,identity,path,label in ((seed_attempted,lease,seed_dir,'seed'),(source_attempted,source_identity,SOURCE,'source'),(binary_attempted,binary_identity,BINARY,'binary')):
   if attempted and identity is None and(path.exists()or path.is_symlink()):result['cleanup_failures'].append(label+'_creation_unknown_retained')
  if not retain:
   if binary_identity is not None:
    def remove_binary():
     s=BINARY.lstat()
     if BINARY.is_symlink()or(s.st_dev,s.st_ino)!=(binary_identity.st_dev,binary_identity.st_ino):raise RuntimeError('owner binary changed retained')
     BINARY.unlink()
    actions.append(('owner-binary',remove_binary))
   if source_identity is not None:actions.append(('source-stage',lambda:remove_owned_directory(SOURCE,source_identity)))
   if lease is not None:
    class Identity:st_dev=lease['dev'];st_ino=lease['ino']
    actions.append(('anchor',lambda:remove_owned_directory(seed_dir,Identity,('seed.json','anchor.json'))))
   if bindir_identity is not None:
    def remove_bindir():
     s=BINDIR.lstat()
     if BINDIR.is_symlink()or(s.st_dev,s.st_ino)!=(bindir_identity.st_dev,bindir_identity.st_ino)or list(BINDIR.iterdir()):raise RuntimeError('binary parent unknown retained')
     BINDIR.rmdir()
    actions.append(('binary-parent',remove_bindir))
  else:result['cleanup_failures'].append('SQL_lifecycle_unknown_retained')
  for label,action in actions:
   try:action()
   except BaseException:result['cleanup_failures'].append(label+'_cleanup_unproved')
  if result['cleanup_failures']:result['passed']=False
  public_error=None
  if public is not None:
   try:
    public.verify()
    public.write('prepare-result.json',canonical(result))
    if result['passed']and result['portable_RR21_accepted']and not result['cleanup_failures']:
     public.verify()
     checkpoint={**result,'evidence_sha256':public.hashes(),'all_sources_complete':False,'restoration_authorized':False,'production_deployment':False,'minimum_grants_negative_SQL_proved':False,'transport_fault_matrix_proved':False,'TERM_KILL_fault_matrix_proved':False}
     public.write('checkpoint.json',canonical(checkpoint))
   except BaseException as error:
    public_error=error;result['passed']=False;result['cleanup_failures'].append('public_output_unproved')
   finally:
    try:public.close()
    except BaseException as error:
     result['passed']=False;result['cleanup_failures'].append('public_handle_close_unproved')
     if public_error is None:public_error=error
  try:private_file(output/'prepare-result.json',canonical(result))
  except BaseException:
   if primary is None:raise
  if primary is None:
   if public_error is not None:raise public_error
   if result['cleanup_failures']:raise RuntimeError('portable_prepare_cleanup_unproved')
if __name__=='__main__':main()

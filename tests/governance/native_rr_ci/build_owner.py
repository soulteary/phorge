#!/usr/bin/env python3
"""Fixed hosted-CI owner build recipe. Does not run PHP, SQL, VM or a daemon."""
import argparse,datetime,os,re,stat,subprocess,tempfile,shutil,json
from pathlib import Path
from provenance import digest,decode,source_bindings,verify_build_info
ROOT=Path(__file__).resolve().parents[3]
def command(args,*,env=None,cwd=None):
 r=subprocess.run(args,cwd=cwd,env=env,capture_output=True)
 if r.returncode:raise RuntimeError('fixed build command failed')
 return r.stdout
def release_event(env):
 """Read the actual runner event; an expression copied into env is insufficient."""
 fd=None
 try:
  path=Path(env.get('GITHUB_EVENT_PATH',''))
  if not path.is_absolute()or path.resolve(strict=True)!=path:raise ValueError('actual release event required')
  fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK);before=os.fstat(fd)
  if not stat.S_ISREG(before.st_mode)or not 0<before.st_size<=1048576:raise ValueError('bounded release event required')
  raw=bytearray()
  while len(raw)<before.st_size:
   block=os.read(fd,before.st_size-len(raw))
   if not block:raise ValueError('complete release event required')
   raw.extend(block)
  fields=('st_dev','st_ino','st_mode','st_uid','st_gid','st_nlink','st_size','st_mtime_ns','st_ctime_ns')
  if os.read(fd,1)or any(getattr(before,k)!=getattr(after,k)for after in (os.fstat(fd),path.lstat())for k in fields):raise ValueError('stable release event required')
  value=decode(raw)
  if type(value)is not dict or type(value.get('inputs'))is not dict or type(value.get('repository'))is not dict:raise ValueError('release event inputs and repository required')
  return value
 except (OSError,TypeError)as error:raise ValueError('actual release event required')from error
 finally:
  if fd is not None:os.close(fd)
def ci_identity(env,head):
 if env.get('GITHUB_ACTIONS')!='true'or env.get('RUNNER_OS')!='Linux'or env.get('RUNNER_ARCH')!='X64'or env.get('GITHUB_SHA')!=head or not re.fullmatch('[a-f0-9]{40}',head):raise ValueError('fixed hosted CI identity required')
 for key in ('GITHUB_RUN_ID','GITHUB_RUN_ATTEMPT'):
  if not re.fullmatch('[1-9][0-9]{0,19}',env.get(key,'')):raise ValueError('same-run identity required')
 event=env.get('GITHUB_EVENT_NAME');ref=env.get('GITHUB_REF','')
 workflow=env.get('GITHUB_WORKFLOW_REF')
 direct=workflow=='soulteary/phorge/.github/workflows/native-rr-owner-build.yml@'+ref
 release=workflow=='soulteary/phorge/.github/workflows/container-image.yml@'+ref
 if env.get('GITHUB_JOB')!='owner-build'or not(direct or release):raise ValueError('fixed reviewed workflow/job required')
 if release:
  tag=env.get('NATIVE_RR_RELEASE_TAG','')
  if env.get('GITHUB_REPOSITORY')!='soulteary/phorge'or event!='workflow_dispatch'or not re.fullmatch(r'20[0-9]{2}\.[0-9]{2}\.[0-9]{2}-r[1-9][0-9]*',tag)or ref!='refs/tags/'+tag:raise ValueError('exact immutable release caller required')
  datetime.datetime.strptime(tag.split('-')[0],'%Y.%m.%d')
  if env.get('NATIVE_RR_WORKFLOW_SHA')!=head or env.get('NATIVE_RR_EXPECTED_HEAD')!=head:raise ValueError('same-source release workflow and input required')
  payload=release_event(env)
  if payload['inputs'].get('release_tag')!=tag or payload['repository'].get('full_name')!='soulteary/phorge':raise ValueError('actual release event does not match caller')
  if command(['git','-C',str(ROOT),'rev-parse','--verify','refs/tags/'+tag+'^{commit}']).decode().strip()!=head:raise ValueError('actual release tag commit required')
 if event=='pull_request':
  if not re.fullmatch(r'refs/pull/[1-9][0-9]*/merge',ref):raise ValueError('synthetic PR checkout identity required')
  kind='pull-request-synthetic-merge'
 elif event=='workflow_dispatch':
  if not re.fullmatch(r'refs/(heads|tags)/[A-Za-z0-9_./-]+',ref):raise ValueError('explicit dispatch ref required')
  kind='workflow-dispatch-checkout'
 else:raise ValueError('unsupported build event')
 return {'source_revision':head,'source_kind':kind,'PR_head_measured':False,'run_id':env['GITHUB_RUN_ID'],'run_attempt':env['GITHUB_RUN_ATTEMPT'],'profile':'isolated-native-RR21-linux-amd64-v1'}
def exclusive(path,raw,mode=0o400):
 fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
 with os.fdopen(fd,'wb')as stream:
  stream.write(raw);stream.flush();os.fsync(stream.fileno());identity=os.fstat(stream.fileno())
 os.chmod(path,mode)
 if path.is_symlink()or(path.stat().st_dev,path.stat().st_ino)!=(identity.st_dev,identity.st_ino)or path.read_bytes()!=raw:raise RuntimeError('build artifact readback unproved')
 return digest(raw)
def cleanup_build_cache(output, output_identity, cache, cache_identity):
 current=output.lstat()
 if output.is_symlink()or(current.st_dev,current.st_ino)!=(output_identity.st_dev,output_identity.st_ino):raise RuntimeError('build output ownership unknown retained')
 current=cache.lstat()
 if cache.is_symlink()or(current.st_dev,current.st_ino)!=(cache_identity.st_dev,cache_identity.st_ino)or not stat.S_ISDIR(current.st_mode):raise RuntimeError('build cache ownership unknown retained')
 shutil.rmtree(cache)
 if cache.exists()or cache.is_symlink():raise RuntimeError('private compiler cache cleanup unproved')
def main(argv=None):
 p=argparse.ArgumentParser();p.add_argument('--go',required=True,type=Path);p.add_argument('--output-dir',required=True,type=Path);a=p.parse_args(argv)
 os.umask(0o077)
 head=command(['git','-C',str(ROOT),'rev-parse','HEAD']).decode().strip();identity=ci_identity(os.environ,head)
 if command(['git','-C',str(ROOT),'status','--porcelain','--untracked-files=all']):raise ValueError('clean current CI checkout required')
 committed=lambda path:command(['git','-C',str(ROOT),'show',head+':'+path])
 sources=source_bindings(ROOT,committed)
 go=a.go
 if not go.is_absolute()or go.is_symlink()or not go.is_file()or not os.access(go,os.X_OK):raise ValueError('explicit regular Go toolchain required')
 env={key:os.environ[key]for key in ('HOME','TMPDIR')if key in os.environ}
 env.update(PATH=str(go.parent)+':/usr/bin:/bin',GOENV='off',GOWORK='off',GOTOOLCHAIN='local',GOFLAGS='',GOEXPERIMENT='',GOPROXY='off',GOSUMDB='off',GONOPROXY='',GONOSUMDB='',GOVCS='*:off',CGO_ENABLED='0',GOOS='linux',GOARCH='amd64',GOAMD64='v1')
 if command([str(go),'version'],env=env).strip()!=b'go version go1.27.3 linux/amd64':raise ValueError('fixed native CI toolchain required')
 output=a.output_dir
 if not output.is_absolute()or output.exists()or output.is_symlink()or ROOT==output or ROOT in output.parents:raise ValueError('fresh external output directory required')
 # Validate the prefilled module-cache boundary before creating any output.
 module_cache=os.environ.get('NATIVE_RR_LOCKED_GOMODCACHE')
 if not module_cache or not Path(module_cache).is_absolute()or not Path(module_cache).is_dir()or Path(module_cache).is_symlink():raise ValueError('explicit locked module cache required')
 output.mkdir(mode=0o700);owned=output.lstat();cache=Path(tempfile.mkdtemp(prefix='native-rr-build-cache-',dir=output));os.chmod(cache,0o700);cache_identity=cache.lstat()
 env['GOCACHE']=str(cache);env['GOMODCACHE']=module_cache;primary_error=None
 try:
  binary=output/'native-rr-process-owner'
  module=ROOT/'support/governance/recovery/owned-process'
  if command([str(go),'mod','verify'],env=env,cwd=module).strip()!=b'all modules verified':raise ValueError('locked module cache verification failed')
  command([str(go),'build','-mod=readonly','-buildvcs=true','-trimpath','-o',str(binary),'.'],env=env,cwd=ROOT/'support/governance/recovery/owned-process')
  info_raw=command([str(go),'version','-m','-json',str(binary)],env=env);info=decode(info_raw);verify_build_info(info,head,'amd64')
  if command([str(go),'mod','verify'],env=env,cwd=module).strip()!=b'all modules verified':raise ValueError('module cache changed during build')
  if source_bindings(ROOT,committed)!=sources or command(['git','-C',str(ROOT),'status','--porcelain','--untracked-files=all']):raise ValueError('source changed during build')
  if binary.is_symlink()or not binary.is_file():raise ValueError('actual built regular binary required')
  raw=binary.read_bytes();os.chmod(binary,0o500)
  result={'schema_version':1,**identity,'source_sha256':sources,'binary_sha256':digest(raw),'build_info_sha256':digest(info_raw),'go_binary_sha256':digest(go.read_bytes()),'source_clean_before_after':True,'build_info_verified':True,'descriptor_is_execution_authority':False,'external_trusted_anchor_established':False,'PHP_executed':False,'SQL_executed':False}
  exclusive(output/'build-info.json',info_raw);exclusive(output/'build-record.json',(json.dumps(result,sort_keys=True,indent=2)+'\n').encode())
  if binary.read_bytes()!=raw:raise RuntimeError('actual binary readback changed')
 except BaseException as error:
  primary_error=error;raise
 finally:
  try:cleanup_build_cache(output,owned,cache,cache_identity)
  except BaseException:
   if primary_error is None:raise
   primary_error.owned_build_cache_cleanup_unproved_retained=True
if __name__=='__main__':main()

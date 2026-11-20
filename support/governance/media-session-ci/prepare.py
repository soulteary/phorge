"""Bind the current locked PHP build ID and fixed MySQL image to the runner's 3-key config."""
import argparse,hashlib,json,os,re,subprocess
from pathlib import Path
LEGACY_PHP_DATA='ghcr.io/soulteary/phorge@sha256:6e5b5b230fa07c0d4b1b0c6a948791ed77eb1583c54a13d7c8b2f7927adc7dda'
MYSQL='mysql@sha256:a0225d1ea6631c8bc2385dc4c2faba17bbf1834ecf369e1659a9a382bd8d5dd7'
BUILD_LOCK_SHA='39d9998c8dc6702f537c216cfebe0f15a25ad2cadae1d4fd769ae45fbd815ea9'
def build_arguments(root):
 raw=(Path(root)/'deploy/acceptance/build-lock.json').read_bytes()
 if hashlib.sha256(raw).hexdigest()!=BUILD_LOCK_SHA:raise ValueError('unreviewed build lock')
 lock=json.loads(raw)
 return ['--build-arg', 'PHP_BASE_IMAGE='+lock['images']['php'], '--build-arg', 'APCU_VERSION='+lock['apcu'], '--build-arg', 'APCU_SHA256='+lock['apcuSHA256'], '--build-arg', 'DEBIAN_SNAPSHOT='+lock['debianSnapshot']]
def build_runtime(root,iidfile,configdir):
 args=build_arguments(root)
 subprocess.run(['/usr/bin/docker','--config',str(configdir),'--host','unix:///var/run/docker.sock','build','--iidfile',str(iidfile)]+args+['--file',str(Path(root)/'Dockerfile'),str(root)],env={'PATH':os.defpath},check=True,stdout=subprocess.DEVNULL,timeout=1200)
 value=Path(iidfile).read_text().strip()
 if not re.fullmatch('sha256:[a-f0-9]{64}',value):raise ValueError('exact built image ID required')
 return value

def bind(output,php_image_id):
 if type(php_image_id)is not str or not re.fullmatch('sha256:[a-f0-9]{64}',php_image_id):raise ValueError('exact built image ID required')
 p=Path(output);p.mkdir(mode=0o700);configdir=p/'empty-docker-config';configdir.mkdir(mode=0o700);ids={}
 for name,ref in [('php_image',php_image_id),('mysql_image',MYSQL)]:
  r=subprocess.run(['/usr/bin/docker','--config',str(configdir),'--host','unix:///var/run/docker.sock','image','inspect','--format','{{.Id}}',ref],env={'PATH':os.defpath},check=True,capture_output=True,timeout=30)
  if r.stderr or not re.fullmatch(b'sha256:[a-f0-9]{64}\n?',r.stdout):raise ValueError('immutable local image refused')
  ids[name]=r.stdout.decode().strip()
  if name=='php_image' and ids[name]!=php_image_id:raise ValueError('built image ID mismatch')
 d=dict(ids,docker='/usr/bin/docker');f=p/'config.private.json';fd=os.open(f,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
 try:
  b=json.dumps(d,sort_keys=True,separators=(',',':')).encode()+b'\n';n=os.write(fd,b)
  if n!=len(b):raise ValueError('short config')
  os.fsync(fd)
 finally:os.close(fd)
 return d
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--output');p.add_argument('--php-image-id');p.add_argument('--build-root');p.add_argument('--iidfile');p.add_argument('--docker-config');a=p.parse_args()
 if a.build_root:
  if not a.iidfile or not a.docker_config or a.output or a.php_image_id:p.error('exclusive build arguments required')
  print(build_runtime(a.build_root,a.iidfile,a.docker_config))
 else:
  if not a.output or not a.php_image_id or a.iidfile or a.docker_config:p.error('output and exact php-image-id required')
  bind(a.output,a.php_image_id);print('{"immutable_local_images_bound":true}')

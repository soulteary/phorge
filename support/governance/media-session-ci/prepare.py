"""Bind only explicitly pulled immutable CI images to the runner's 3-key config."""
import argparse,json,os,re,subprocess
from pathlib import Path
PHP='ghcr.io/soulteary/phorge@sha256:6e5b5b230fa07c0d4b1b0c6a948791ed77eb1583c54a13d7c8b2f7927adc7dda'
MYSQL='mysql@sha256:a0225d1ea6631c8bc2385dc4c2faba17bbf1834ecf369e1659a9a382bd8d5dd7'
def bind(output):
 p=Path(output);p.mkdir(mode=0o700);configdir=p/'empty-docker-config';configdir.mkdir(mode=0o700);ids={}
 for name,ref in [('php_image',PHP),('mysql_image',MYSQL)]:
  r=subprocess.run(['/usr/bin/docker','--config',str(configdir),'--host','unix:///var/run/docker.sock','image','inspect','--format','{{.Id}}',ref],env={'PATH':os.defpath},check=True,capture_output=True,timeout=30)
  if r.stderr or not re.fullmatch(b'sha256:[a-f0-9]{64}\n?',r.stdout):raise ValueError('immutable local image refused')
  ids[name]=r.stdout.decode().strip()
 d=dict(ids,docker='/usr/bin/docker');f=p/'config.private.json';fd=os.open(f,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
 try:
  b=json.dumps(d,sort_keys=True,separators=(',',':')).encode()+b'\n';n=os.write(fd,b)
  if n!=len(b):raise ValueError('short config')
  os.fsync(fd)
 finally:os.close(fd)
 return d
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--output',required=True);a=p.parse_args();bind(a.output);print('{"immutable_local_images_bound":true}')

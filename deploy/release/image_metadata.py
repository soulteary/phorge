#!/usr/bin/env python3
"""Read-only GHCR metadata gate. A missing tag is only an HTTP 404."""
import argparse,base64,datetime,hashlib,json,os,re,subprocess,urllib.error,urllib.parse,urllib.request
from pathlib import Path

ACCEPT='application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.v2+json'

def digest(raw):return 'sha256:'+hashlib.sha256(raw).hexdigest()

def identity(repository,tag,sha,ref):
    if not re.fullmatch(r'[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*',repository):raise ValueError('Invalid repository.')
    if not re.fullmatch(r'20[0-9]{2}\.[0-9]{2}\.[0-9]{2}-r[1-9][0-9]*',tag):raise ValueError('A dated immutable release tag is required.')
    datetime.datetime.strptime(tag.split('-')[0],'%Y.%m.%d')
    if not re.fullmatch('[a-f0-9]{40}',sha) or ref!='refs/tags/'+tag:raise ValueError('Run from the exact existing release tag.')
    return True

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args,**kwargs):return None

class Registry:
    def __init__(self,repository,username=None,password=None):
        if bool(username)!=bool(password):raise ValueError('Both registry exchange credentials are required.')
        self.repository=repository;self.opener=urllib.request.build_opener(NoRedirect());self.token=None
        self.exchange_authorization='Basic '+base64.b64encode((username+':'+password).encode()).decode() if username else None
    def get(self,url,authorized=False,blob=False,exchange=False):
        headers={'Accept':ACCEPT}
        if exchange:
            if not url.startswith('https://ghcr.io/token?'):raise ValueError('Fixed credential exchange endpoint required.')
            if self.exchange_authorization:headers['Authorization']=self.exchange_authorization
        if authorized:
            if not url.startswith('https://ghcr.io/v2/'+self.repository+'/'):raise ValueError('Fixed registry origin required.')
            headers['Authorization']='Bearer '+self.token
        try:
            with self.opener.open(urllib.request.Request(url,headers=headers),timeout=30) as r:return r.read()
        except urllib.error.HTTPError as ex:
            if blob and ex.code in (301,302,303,307,308):
                target=urllib.parse.urlsplit(ex.headers.get('Location',''))
                if target.scheme!='https' or target.hostname not in ('ghcr.io','pkg-containers.githubusercontent.com') or target.port not in (None,443) or target.username or target.password:raise ValueError('Unsafe blob redirect.') from None
                # Never forward registry credentials to a blob-storage origin.
                return self.get(target.geturl())
            raise
    def authenticate(self):
        query=urllib.parse.urlencode({'service':'ghcr.io','scope':'repository:'+self.repository+':pull'})
        result=json.loads(self.get('https://ghcr.io/token?'+query,exchange=True));self.token=result.get('token') or result.get('access_token')
        if not isinstance(self.token,str) or not self.token:raise ValueError('Registry token unavailable.')
    def manifest(self,reference,missing=False):
        if not re.fullmatch(r'(?:sha256:[a-f0-9]{64}|[a-zA-Z0-9_.-]+)',reference):raise ValueError('Invalid manifest reference.')
        try:return self.get('https://ghcr.io/v2/'+self.repository+'/manifests/'+reference,authorized=True)
        except urllib.error.HTTPError as ex:
            if missing and ex.code==404:return None
            raise
    def config(self,expected):
        if not re.fullmatch('sha256:[a-f0-9]{64}',expected):raise ValueError('Invalid config digest.')
        raw=self.get('https://ghcr.io/v2/'+self.repository+'/blobs/'+expected,authorized=True,blob=True)
        if digest(raw)!=expected:raise ValueError('Config digest mismatch.')
        return json.loads(raw)

def runtime_digests(raw,registry,repository,tag,sha):
    index=json.loads(raw);platforms={}
    if index.get('schemaVersion')!=2 or not isinstance(index.get('manifests'),list):raise ValueError('Multi-platform index required.')
    for row in index['manifests']:
        p=row.get('platform',{});arch=p.get('architecture');os_name=p.get('os')
        if arch==os_name=='unknown' and row.get('annotations',{}).get('vnd.docker.reference.type')=='attestation-manifest':continue
        if os_name!='linux' or arch not in ('amd64','arm64') or arch in platforms:raise ValueError('Unsupported or duplicate runtime platform.')
        expected=row.get('digest','')
        if not re.fullmatch('sha256:[a-f0-9]{64}',expected):raise ValueError('Invalid child digest.')
        child=registry.manifest(expected)
        if digest(child)!=expected:raise ValueError('Child manifest digest mismatch.')
        manifest=json.loads(child);config=registry.config(manifest['config']['digest']);labels=config.get('config',{}).get('Labels',{})
        if config.get('architecture')!=arch or config.get('os')!='linux':raise ValueError('Runtime platform mismatch.')
        wanted={'org.opencontainers.image.revision':sha,'org.opencontainers.image.source':'https://github.com/'+repository,'org.opencontainers.image.version':tag}
        if any(labels.get(k)!=v for k,v in wanted.items()):raise ValueError('Image identity mismatch.')
        platforms[arch]=expected
    if set(platforms)!={'amd64','arm64'}:raise ValueError('Both runtime architectures required.')
    return platforms

def validate_index(raw,registry,repository,tag,sha):
    return sorted(runtime_digests(raw,registry,repository,tag,sha))

def inspect(registry,repository,tag,sha):
    version=registry.manifest(tag,missing=True);source=registry.manifest('sha-'+sha,missing=True)
    if version is None and source is None:return {'exists':False,'digest':'','platforms':[],'platformDigests':{}}
    if version is None or source is None:raise ValueError('Incomplete immutable tag set; do not overwrite or infer a repair.')
    if digest(version)!=digest(source):raise ValueError('Version/source tags disagree; overwrite refused.')
    platforms=runtime_digests(version,registry,repository,tag,sha)
    return {'exists':True,'digest':digest(version),'platforms':sorted(platforms),'platformDigests':platforms}

def main():
    ap=argparse.ArgumentParser();ap.add_argument('--repository',required=True);ap.add_argument('--tag',required=True);ap.add_argument('--sha',required=True);ap.add_argument('--ref',required=True);ap.add_argument('--output',type=Path,required=True);ap.add_argument('--github-output',type=Path);ap.add_argument('--require-published',action='store_true');a=ap.parse_args()
    identity(a.repository,a.tag,a.sha,a.ref)
    if subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip()!=a.sha or subprocess.check_output(['git','rev-parse','refs/tags/'+a.tag+'^{commit}'],text=True).strip()!=a.sha:raise ValueError('Tag, checkout and source event disagree.')
    registry=Registry(a.repository,os.environ.get('GHCR_USER'),os.environ.get('GHCR_TOKEN'));registry.authenticate();r=inspect(registry,a.repository,a.tag,a.sha)
    if a.require_published and not r['exists']:raise ValueError('Published image unavailable.')
    r.update(repository=a.repository,tag=a.tag,sourceRevision=a.sha,ref=a.ref,overwritePermitted=False);a.output.write_text(json.dumps(r,indent=2)+'\n')
    if a.github_output:
        with a.github_output.open('a') as f:f.write('exists='+str(r['exists']).lower()+'\ndigest='+r['digest']+'\n')
    print('Image identity and non-overwrite gate passed; published =',r['exists'])
if __name__=='__main__':main()

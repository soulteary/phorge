"""Local TLS front end for a real disposable S3 service; no signing is mocked."""
import http.server
import ssl
import subprocess
import threading
import urllib.error
import urllib.request


def validate_isolated_topology(config):
    for service in config['services'].values():
        if service.get('ports') or service.get('container_name'):
            raise ValueError('Acceptance must not publish ports or reuse container names')
        for mount in service.get('tmpfs', []):
            if not isinstance(mount, str) or not mount.split(':', 1)[0].startswith('/'):
                raise ValueError('Acceptance tmpfs target must be an absolute container path')
        for mount in service.get('volumes', []):
            if mount.get('type') == 'tmpfs' and not mount.get('target', '').startswith('/'):
                raise ValueError('Acceptance tmpfs target must be an absolute container path')
            if mount.get('type') == 'tmpfs' and mount.get('target') == '/tmp':
                mode = mount.get('tmpfs', {}).get('mode', 0)
                if not isinstance(mode, int) or mode & 0o1003 != 0o1003:
                    raise ValueError('Acceptance /tmp must be writable by the image service user with sticky mode')


class S3TLS:
    def __init__(self, endpoint, work):
        self.endpoint = endpoint.rstrip('/')
        self.cert = work/'s3-cert.pem'; key=work/'s3-key.pem'
        subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes',
            '-keyout',str(key),'-out',str(self.cert),'-days','1','-subj','/CN=localhost',
            '-addext','subjectAltName=IP:127.0.0.1,DNS:localhost'],check=True,
            stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        parent=self
        class Handler(http.server.BaseHTTPRequestHandler):
            def exchange(self):
                data=self.rfile.read(int(self.headers.get('Content-Length',0)))
                headers={k:v for k,v in self.headers.items() if k.lower() not in ('connection','content-length')}
                request=urllib.request.Request(parent.endpoint+self.path,data if data else None,
                    headers=headers,method=self.command)
                try: response=urllib.request.urlopen(request,timeout=10)
                except urllib.error.HTTPError as ex: response=ex
                with response:
                    body=response.read(16*1024*1024+1)
                    if len(body)>16*1024*1024: raise RuntimeError('S3 fixture response exceeded bound')
                    self.send_response(response.status)
                    for k,v in response.headers.items():
                        if k.lower() not in ('transfer-encoding','connection','content-length'):self.send_header(k,v)
                    self.send_header('Content-Length',str(len(body)));self.end_headers()
                    if self.command!='HEAD':self.wfile.write(body)
            do_GET=do_PUT=do_DELETE=do_HEAD=exchange
            def log_message(self,*args):pass
        self.server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler)
        context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);context.load_cert_chain(self.cert,key)
        self.server.socket=context.wrap_socket(self.server.socket,server_side=True)
        self.thread=threading.Thread(target=self.server.serve_forever,daemon=True);self.thread.start()
        self.host='127.0.0.1:'+str(self.server.server_port)
    def close(self):
        self.server.shutdown();self.server.server_close();self.thread.join(timeout=5)


def prepare_phorge_snapshot(source, image, target):
    """Run the supplied source with the parser artifacts built into its image."""
    import shutil
    from pathlib import Path
    from acceptance_manifest import runtime_identity, source_identity

    source_runtime = runtime_identity(source / 'support/runtime/manifest.json')
    image_runtime = runtime_identity(image / 'support/runtime/manifest.json')
    if source_runtime != image_runtime:
        raise ValueError('Built image runtime does not match the supplied source')
    identity = source_identity(source)
    def ignore_deployment(directory, names):
        ignored = set(shutil.ignore_patterns('.git', '.phutil_module_cache',
                                             '__pycache__', '*.pyc', '.env')(directory, names))
        relative = Path(directory).relative_to(source)
        if relative.as_posix() == 'conf':
            ignored.update({'local', 'custom', 'keys'} & set(names))
        elif relative.as_posix() == 'support':
            ignored.update({'preamble.php'} & set(names))
        elif relative.as_posix() == 'src':
            ignored.update({'extensions'} & set(names))
        return ignored

    shutil.copytree(source, target, symlinks=True, ignore=ignore_deployment)
    (target / 'conf/local').mkdir(parents=True, exist_ok=True)
    (target / '.source-revision').write_text(identity['commit'] + '\n')
    for name in ['support/runtime/src/parser/xhpast/bin/xhpast',
                 'support/runtime/support/php-parser/lib']:
        built = image / name
        copied = target / name
        if not built.exists():
            raise ValueError('Required parser artifact is absent from built image: ' + name)
        if copied.is_symlink():
            copied.unlink()
        if built.is_dir():
            if copied.exists():
                shutil.rmtree(copied)
            shutil.copytree(built, copied)
        else:
            copied.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(built, copied)
    if runtime_identity(target / 'support/runtime/manifest.json') != source_runtime:
        raise ValueError('Execution snapshot runtime differs from supplied source')
    return source_identity(target)

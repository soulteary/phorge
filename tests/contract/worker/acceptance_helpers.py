"""Local TLS front end for a real disposable S3 service; no signing is mocked."""
import http.server
import ssl
import subprocess
import threading
import urllib.error
import urllib.request


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


def prepare_arcanist(source, work):
    # The pinned upstream assumes every macOS cURL uses SecureTransport. Modern
    # PHP uses OpenSSL and requires CURLOPT_CAINFO for a private fixture CA.
    # Patch only the disposable copy; never disable certificate verification.
    import shutil
    import sys
    target=work/'arcanist'
    if sys.platform!='darwin':
        target.symlink_to(source,target_is_directory=True)
        return target
    backend=subprocess.check_output(['php','-r','echo curl_version()["ssl_version"];'],text=True)
    if not backend.startswith('OpenSSL/'):
        raise RuntimeError('Private S3 fixture CA requires OpenSSL PHP cURL on macOS')
    shutil.copytree(source,target,ignore=shutil.ignore_patterns('.git'))
    file=target/'src/future/http/HTTPSFuture.php'
    text=file.read_text()
    old="if (version_compare($osx_version, '14', '>=')) {"
    if old not in text:raise RuntimeError('Pinned Arcanist CA workaround no longer matches')
    text=text.replace(old,"if (version_compare($osx_version, '14', '>=') && "+
      "strpos(curl_version()['ssl_version'], 'SecureTransport') !== false) {")
    file.write_text(text)
    return target

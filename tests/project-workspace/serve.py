#!/usr/bin/env python3
"""Serve and verify disposable native workspace HTTP flows; never an instance."""
import argparse
import hashlib
import http.client
import http.cookiejar
from html.parser import HTMLParser
import importlib.util
import json
import os
from pathlib import Path
import secrets
import signal
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('atomic_runner', ROOT/'tests/atomic/run.py')
GATE = importlib.util.module_from_spec(spec)
spec.loader.exec_module(GATE)


class Forms(HTMLParser):
    def __init__(self):
        super().__init__(); self.forms = []; self.current = None
        self.textarea = None; self.select = None; self.option = None
    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == 'form':
            self.current = {'action': a.get('action', ''), 'fields': {}}
            self.forms.append(self.current)
        if tag == 'input' and self.current is not None and a.get('name') and 'disabled' not in a:
            if a.get('type') in ('checkbox','radio') and 'checked' not in a: return
            self.current['fields'][a['name']] = a.get('value', '')
        if tag == 'textarea' and self.current is not None and a.get('name') and 'disabled' not in a:
            self.textarea = a['name']; self.current['fields'][self.textarea] = ''
        if tag == 'select' and self.current is not None and a.get('name') and 'disabled' not in a:
            self.select = a['name']
        if tag == 'option' and self.select:
            self.option = a.get('value','')
            if self.select not in self.current['fields'] or 'selected' in a:
                self.current['fields'][self.select] = self.option
    def handle_data(self, data):
        if self.textarea and self.current is not None:
            self.current['fields'][self.textarea] += data
    def handle_endtag(self, tag):
        if tag == 'form': self.current = None
        if tag == 'textarea': self.textarea = None
        if tag == 'select': self.select = None
        if tag == 'option': self.option = None


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', required=True, type=Path)
    parser.add_argument('--port', type=int, default=0)
    parser.add_argument('--exit-after-http', action='store_true')
    args = parser.parse_args()
    output = args.output.resolve(); output.mkdir(parents=True, exist_ok=False, mode=0o700)
    private = Path(tempfile.mkdtemp(prefix='phorge-workspace-')); private.chmod(0o700)
    config = GATE.configuration(os.environ)
    namespace = 'atomic_workspace_'+secrets.token_hex(8)
    config['storage.default-namespace'] = namespace
    config['phabricator.cache-namespace'] = namespace
    if not 0 <= args.port <= 65535: raise ValueError('Invalid loopback port.')
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', args.port)); port = probe.getsockname()[1]
    # A unique localhost hostname keeps synthetic phsid/phusr cookies separate
    # from any user-owned localhost application, even on a different port.
    hostname = 'phorge-atomic-'+secrets.token_hex(8)+'.localhost'
    base = f'http://{hostname}:{port}'
    # Preserve the production default for optional builtin composition. The
    # foundation gate separately probes actual Gorge image composition. No
    # fallback is added to the application to make this HTTP fixture pass.
    config.pop('gorge.image.builtin-mode', None)
    config.update({'phabricator.base-uri': base+'/', 'project.history-state': 'active',
                   'project.workspace-mode': 'unified', 'project.history-task-drafts': True})
    path, digest = GATE.reserve_configuration(ROOT, config)
    key = secrets.token_hex(32)
    owner = {'root': str(ROOT), 'namespace': namespace, 'key': key,
             'configurationHash': digest.hex()}
    owner_path = private/'owner.json'; owner_path.write_text(json.dumps(owner)); owner_path.chmod(0o600)
    env = dict(os.environ, ATOMIC_WORKSPACE_NAMESPACE=namespace,
               ATOMIC_WORKSPACE_PRIVATE=str(private))
    report = {'passed': False, 'checks': [], 'cleanup': False,
              'gitHead': subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip(),
              'worktreeDirty': bool(subprocess.check_output(['git','status','--porcelain'],cwd=ROOT,text=True).strip()),
              'pairedGorgeRevision': os.environ['GORGE_CONTRACT_REF'],
              'scope': 'Disposable native HTTP and persistence checks; browser layout is verified separately.'}
    server = None
    def command(name, argv, retain_stdout=True):
        result = subprocess.run(argv, cwd=ROOT, env=env, text=True, capture_output=True, timeout=300)
        text = (result.stdout if retain_stdout else '')+result.stderr
        for environment_name in ('GORGE_TEST_MYSQL_PASSWORD', 'GORGE_TEST_FILE_TOKEN',
                                 'GORGE_TEST_IMAGE_TOKEN', 'GORGE_TEST_RENDER_TOKEN'):
            text = text.replace(os.environ[environment_name], '[redacted]')
        text = text.replace(key, '[redacted]')
        (output/(name+'.log')).write_text(text)
        return result
    def fixture(mode):
        r = command('fixture-'+mode, ['php',str(HERE/'fixture.php'),mode])
        if r.returncode: raise RuntimeError('Fixture '+mode+' failed; inspect evidence.')
        return json.loads(r.stdout)
    def check(condition, label):
        report['checks'].append({'name':label,'passed':bool(condition)})
        if not condition: raise RuntimeError(label)
    def client():
        class LocalConnection(http.client.HTTPConnection):
            def connect(self):
                self.sock = socket.create_connection(('127.0.0.1', self.port), self.timeout)
        class LocalHandler(urllib.request.HTTPHandler):
            def http_open(self, req):
                if req.host != f'{hostname}:{port}':
                    raise ValueError('Refusing a request outside the disposable hostname.')
                return self.do_open(LocalConnection, req)
        return urllib.request.build_opener(urllib.request.ProxyHandler({}),LocalHandler(),
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def request(opener, uri, data=None):
        encoded = urllib.parse.urlencode(data).encode() if data is not None else None
        url = base+uri
        if not url.startswith(base+'/'): raise ValueError('Only this loopback app may be called.')
        req = urllib.request.Request(url, data=encoded, headers={'Origin':base} if encoded else {})
        try:
            with opener.open(req,timeout=30) as r: return r.status,r.read().decode(),r.geturl()
        except urllib.error.HTTPError as r: return r.code,r.read().decode(),r.geturl()
    def state(**kwargs):
        status,_,_ = request(client(),'/__atomic/state?key='+key,kwargs)
        check(status==200,'fixture state transition '+json.dumps(kwargs,sort_keys=True))
    def csrf(opener):
        status,body,_ = request(opener,'/login/refresh/?__ajax__=1')
        if status != 200 or not body.startswith('for (;;);'):
            raise RuntimeError('Native CSRF refresh failed.')
        return json.loads(body[len('for (;;);'):])['payload']['token']
    try:
        result = command('storage-upgrade',['php','bin/storage','upgrade','--force','--namespace',namespace])
        if result.returncode not in (0,2): raise RuntimeError('Fresh native upgrade failed.')
        schema = command('schema',['php',str(HERE/'fixture.php'),'schema'])
        if schema.returncode: raise RuntimeError('Native schema comparison failed.')
        # Seed stdout contains synthetic sessions. Never persist it, including
        # parse failures; ordinary artifacts retain only redacted diagnostics.
        seeded = command('seed',['php',str(HERE/'fixture.php'),'seed'],retain_stdout=False)
        if seeded.returncode: raise RuntimeError('Native fixture seed failed.')
        data = json.loads(seeded.stdout)
        fixture_path = private/'fixture.json'
        fixture_path.write_text(json.dumps(data)); fixture_path.chmod(0o600)
        log = (output/'server.log').open('w')
        server = subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(ROOT/'webroot'),str(HERE/'router.php')],
                                  cwd=ROOT,env=env,stdout=log,stderr=subprocess.STDOUT)
        for _ in range(50):
            try:
                if request(client(),'/__atomic/ready?key='+key)[0]==200: break
            except OSError: time.sleep(.1)
        else: raise RuntimeError('Disposable native server did not start.')
        history = data['projects']['main']['history']; board = data['projects']['main']['board']
        users = {}
        for role in ('owner','reader','observer','chinese'):
            users[role]=client()
            code,_,_=request(users[role],f'/__atomic/session/{role}/?key='+key)
            check(code==200,'native session '+role)
        code,html,_=request(users['owner'],history)
        check(code==200 and 'team-project-progress-publish' in html,'owner sees native publication form')
        parsed=Forms();parsed.feed(html)
        form=next(f for f in parsed.forms if 'kind' in f['fields'])
        fields=dict(form['fields'],title='Atomic HTTP Record',body='Published by native HTTP form.',kind='node',percent='45')
        initial=fixture('counts')
        code,draft,_=request(users['owner'],data['draft'])
        check(code==200 and 'Atomic Seed Record' in draft,'authorized unsaved task draft')
        check(fixture('counts')['tasks']==initial['tasks'],'draft GET creates zero tasks')
        draft_forms=Forms();draft_forms.feed(draft)
        task_form=next(f for f in draft_forms.forms if 'title' in f['fields'] and 'description' in f['fields'])
        saved_fields=dict(task_form['fields'],title='Atomic HTTP Saved Task')
        action=urllib.parse.urlsplit(task_form['action'])
        if action.netloc and action.netloc != f'{hostname}:{port}':
            raise RuntimeError('Native task form escaped the disposable application.')
        task_uri=action.path+('?' + action.query if action.query else '')
        code,saved,saved_url=request(users['owner'],task_uri,saved_fields)
        check(code==200 and 'Atomic HTTP Saved Task' in saved,'explicit native task draft Save')
        check(fixture('counts')['tasks']==initial['tasks']+1,'explicit Save creates exactly one task')
        report['createdTaskURI']=urllib.parse.urlsplit(saved_url).path
        initial=fixture('counts')
        code,old,_=request(users['owner'],history+'?entry='+str(data['node']['id']))
        check(code==200 and 'Atomic Seed Record' in old,'stable old record bypasses pagination')
        for role in ('reader','observer'):
            code,body,_=request(users[role],history)
            check(code==200 and 'team-project-progress-publish' not in body,'readable history without publication '+role)
            bad=dict(fields);bad['title']='Forbidden '+role
            bad['__csrf__']=csrf(users[role])
            code,_,_=request(users[role],history,bad)
            check(code==403,'direct publication with valid CSRF denied '+role)
            code,private_body,_=request(users[role],data['projects']['private']['history'])
            check('team-project-progress-history' not in private_body and
                  data['projects']['private']['name'] not in private_body,
                  'private project history hidden '+role)
        check(fixture('counts')['transactions']==initial['transactions'],'denied publication writes no project transaction')
        code,body,_=request(users['chinese'],history)
        check(code==200 and '发布记录' in body and '项目完成度' in body,'Chinese native publication labels')
        code,_,_=request(users['owner'],history,fields)
        check(code==200,'real form publication redirects to readable entry')
        after=fixture('counts')
        check(after['transactions']==initial['transactions']+1,'real form appends one transaction')
        state(history='read-only')
        code,body,_=request(users['owner'],history)
        check(code==200 and 'Atomic HTTP Record' in body and 'team-project-progress-publish' not in body,'readonly retains history and hides publisher')
        code,_,_=request(users['owner'],history,fields)
        check(code!=200 and fixture('counts')['transactions']==after['transactions'],'readonly rejects old form POST')
        state(history='active',drafts='0')
        code,body,_=request(users['owner'],data['draft'])
        check(code!=200 or 'Unable to Create Task from Project Record' in body,'disabled direct draft request denied')
        check(fixture('counts')['tasks']==initial['tasks'],'disabled drafts create zero tasks')
        state(workspace='native')
        code,body,_=request(users['owner'],board)
        check(code==200 and 'team-project-workspace' not in body,'native default board preserved')
        state(workspace='unified',drafts='1')
        code,body,_=request(users['owner'],board)
        check(code==200 and 'team-project-workspace' in body and 'team-project-card-status' in body,'unified board uses native task status badges')
        report['passed']=True
        public={'base':base,'projects':data['projects'],'columns':data['columns'],'tasks':data['tasks'],'node':data['node'],'draft':data['draft']}
        (output/'browser-fixture.json').write_text(json.dumps(public,indent=2))
        # Browser operators read these synthetic login links only from a private file.
        handoff={'base':base,'login':{r:f'{base}/__atomic/session/{r}/?key={key}' for r in users},
                 'private':str(private),'stateEndpoint':base+'/__atomic/state?key='+key,
                 'fixture':str(output/'browser-fixture.json'),
                 'curlResolve':f'{hostname}:{port}:127.0.0.1'}
        (private/'handoff.json').write_text(json.dumps(handoff)); (private/'handoff.json').chmod(0o600)
        print('HTTP checks passed. Browser handoff: '+str(private/'handoff.json'),flush=True)
        if not args.exit_after_http:
            print('Serving the disposable native app until interrupted.',flush=True)
            while server.poll() is None: time.sleep(.5)
    finally:
        if server is not None:
            server.terminate()
            try: server.wait(timeout=10)
            except subprocess.TimeoutExpired: server.kill();server.wait()
        cleanup=command('storage-destroy',['php','bin/storage','destroy','--force','--namespace',namespace])
        current=json.loads(owner_path.read_text())
        GATE.remove_configuration(path,bytes.fromhex(current['configurationHash']))
        for p in private.iterdir():p.unlink()
        private.rmdir()
        report['cleanup']=cleanup.returncode==0
        report['passed']=report['passed'] and report['cleanup']
        # Never retain synthetic session/auth keys in ordinary HTTP request logs.
        if (output/'server.log').exists():
            (output/'server.log').write_text((output/'server.log').read_text().replace(key,'[redacted]'))
        (output/'http-results.json').write_text(json.dumps(report,indent=2)+'\n')
    return 0 if report['passed'] else 1


if __name__=='__main__':
    def stop(_number,_frame): raise KeyboardInterrupt()
    signal.signal(signal.SIGTERM,stop)
    try: sys.exit(main())
    except KeyboardInterrupt: pass
    except (OSError,ValueError,RuntimeError,subprocess.TimeoutExpired) as error:
        print('Workspace HTTP acceptance failed: '+str(error),file=sys.stderr);sys.exit(1)

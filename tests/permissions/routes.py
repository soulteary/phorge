#!/usr/bin/env python3
"""Native HTTP permission acceptance on an owned disposable restored namespace."""
import argparse, hashlib, http.client, http.cookiejar, importlib.util, json, os
from pathlib import Path
import secrets, shutil, socket, subprocess, tempfile, time, re, datetime
import urllib.request, urllib.parse, urllib.error
from route_snapshot import remap_snapshot
import boundaries
from route_spec import PROJECTS, SUBJECTS, PHASES, ROUTES, expected, validate_receipt
ROOT = Path(__file__).resolve().parents[2]
HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('gate', ROOT/'tests/atomic/run.py')
GATE = importlib.util.module_from_spec(spec); spec.loader.exec_module(GATE)

def main():
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--output', required=True, type=Path)
    ap.add_argument('--require-clean-worktree', action='store_true')
    ap.add_argument('--port',type=int,default=0)
    ap.add_argument('--base-snapshot',type=Path)
    ap.add_argument('--base-manifest',type=Path)
    args=ap.parse_args()
    if any(name in os.environ for name in ('PHORGE_DEPLOYMENT_CONFIG','PHORGE_CONTROL_PLANE')):
        raise ValueError('Inherited deployment configuration selectors refused before PHP execution.')
    out=args.output.resolve(); out.mkdir(mode=0o700, parents=True, exist_ok=False)
    report={'passed':False,'baselineModel':'native-v1','candidateModelsRouteVerified':[],
            'phaseSnapshots':{},'migrationReady':False,'pairedGorgeRevision':os.environ.get('GORGE_CONTRACT_REF'),'matrix':[],'boundarySchemaVersion':1,'boundaryCoverage':{},'boundaryChecks':[],'checks':[], 'cleanup':False}
    before=GATE.capture_source(ROOT)
    if GATE.record_source_provenance(report,before,None,args.require_clean_worktree):
        GATE.publish_report(out/'route-results.json',report); return 1
    cfg=GATE.configuration(os.environ); cfg.pop('gorge.image.builtin-mode',None)
    ns='atomic_routes_'+secrets.token_hex(8)
    if not 0<=args.port<=65535: raise ValueError('Invalid private fixture port.')
    port=args.port
    if not port:
        with socket.socket() as s: s.bind(('127.0.0.1',0)); port=s.getsockname()[1]
    host='permission-'+secrets.token_hex(8)+'.localhost'; base=f'http://{host}:{port}'
    cfg.update({'storage.default-namespace':ns,'phabricator.cache-namespace':ns,
                'phabricator.base-uri':base+'/', 'policy.allow-public':False,
                'auth.require-approval':True,'projects.default-navigation':'flat',
                'project.history-state':'off','project.workspace-mode':'native',
                'project.history-task-drafts':False,
                'gorge.search.source-scan':False,'gorge.search.projection-shadow':False,
                'gorge.taskqueue.owner':'phorge','gorge.taskqueue.uri':None,
                'gorge.notification.outbox':False,'notification.servers':[]})
    search=os.environ.get('GORGE_TEST_SEARCH_URL')
    if not search: raise ValueError('Explicit loopback Gorge search required.')
    uri=urllib.parse.urlsplit(search)
    if uri.scheme!='http' or uri.hostname!='127.0.0.1' or not uri.port or uri.path or uri.query or uri.fragment or uri.username:
        raise ValueError('Only an explicit loopback Gorge search endpoint is supported.')
    if not os.environ.get('GORGE_TEST_SEARCH_TOKEN'): raise ValueError('Disposable search token required.')
    cfg.update({'cluster.search':[{'type':'gorge','protocol':'http','host':'127.0.0.1','port':uri.port,'roles':{'read':True,'write':True}}],
                'gorge.search.token':os.environ['GORGE_TEST_SEARCH_TOKEN']})
    report['searchBackend']='paired-gorge-meilisearch'
    private=Path(tempfile.mkdtemp(prefix='permission-routes-')); private.chmod(0o700)
    env=dict(os.environ,PERMISSION_ROUTES_PRIVATE=str(private),PERMISSION_ROUTES_NAMESPACE=ns,
             PHORGE_DEPLOYMENT_CONFIG=str(ROOT/'conf/local/deployment.json'),PHORGE_CONTROL_PLANE='deployment')
    owned=[]; server=None; collected=[]; reader=None; scoped=False
    def check(ok,label):
        report['checks'].append({'name':label,'passed':bool(ok)})
        if not ok: raise RuntimeError(label)
    def boundary_check(ok,label):
        report['boundaryChecks'].append({'name':label,'passed':bool(ok)})
        if not ok: raise RuntimeError(label)
    def run(argv):
        p=subprocess.run(argv,cwd=ROOT,env=env,capture_output=True,text=True,timeout=600)
        # Diagnostics may contain native object names or synthetic credentials.
        (private/('command-'+secrets.token_hex(4)+'.txt')).write_text(p.stdout+p.stderr)
        if p.returncode: raise RuntimeError('Disposable command failed: '+Path(argv[1]).name)
        return p.stdout
    def fixture(mode): return json.loads(run(['php',str(HERE/'route_fixture.php'),mode]))
    def snapshot(mode): return json.loads(run(['php',str(HERE/'route_snapshot.php'),mode]))
    def save(name,value):
        p=private/name; p.write_text(json.dumps(value)); p.chmod(0o600)
    def client():
        class Connection(http.client.HTTPConnection):
            def connect(self): self.sock=socket.create_connection(('127.0.0.1',self.port),self.timeout)
        class Handler(urllib.request.HTTPHandler):
            def http_open(self,req):
                if req.host!=f'{host}:{port}': raise ValueError('Foreign HTTP target refused.')
                return self.do_open(Connection,req)
        return urllib.request.build_opener(urllib.request.ProxyHandler({}),Handler(),
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def request(c,uri,data=None):
        if not uri.startswith('/') or uri.startswith('//'): raise ValueError('Foreign target.')
        req=urllib.request.Request(base+uri,data=None if data is None else urllib.parse.urlencode(data).encode())
        try:
            with c.open(req,timeout=30) as r: return r.status,r.read().decode()
        except urllib.error.HTTPError as e: return e.code,e.read().decode()
    def stop():
        nonlocal server
        if server:
            server.terminate()
            try: server.wait(timeout=10)
            except subprocess.TimeoutExpired: server.kill();server.wait()
            server=None
    def start():
        nonlocal server
        log=(private/'server.log').open('a')
        server=subprocess.Popen(['php','-d','opcache.jit=disable','-S',f'127.0.0.1:{port}',
            '-t',str(ROOT/'webroot'),str(HERE/'route_router.php')],cwd=ROOT,env=env,stdout=log,stderr=log)
        log.close()
        for _ in range(100):
            try:
                status,body=request(client(),'/__route/ready?key='+owner['key'])
                if status==200:
                    report['runtime']=json.loads(body)
                    check(report['runtime']['jit']=='disable','JIT explicitly disabled'); return
            except OSError: pass
            time.sleep(.1)
        raise RuntimeError('Native HTTP fixture did not start.')
    try:
        # Highest precedence overlay prevents any restored DB configuration from
        # selecting deployment endpoints. Both files are exclusively owned.
        if (ROOT/'conf/local').is_symlink(): raise ValueError('Symlinked configuration directory refused.')
        if any((ROOT/'conf/local'/name).exists() or (ROOT/'conf/local'/name).is_symlink()
               for name in ('local.json','deployment.json')):
            raise ValueError('Existing instance configuration refused before storage access.')
        for name in ('local.json','deployment.json'):
            p=ROOT/'conf/local'/name
            with p.open('x') as f: json.dump(cfg,f)
            p.chmod(0o600); owned.append((p,hashlib.sha256(p.read_bytes()).digest()))
        owner={'root':str(ROOT),'namespace':ns,'key':secrets.token_hex(32),
               'configurationHash':owned[0][1].hex(),'deploymentHash':owned[1][1].hex()}
        save('owner.json',owner); scoped=True
        run(['php','bin/storage','upgrade','--force','--namespace',ns])
        if args.base_snapshot or args.base_manifest:
            if not args.base_snapshot or not args.base_manifest: raise ValueError('Both private snapshot inputs required.')
            raw=args.base_snapshot.read_bytes(); manifest=json.loads(args.base_manifest.read_text())
            sql=remap_snapshot(raw,manifest,ns)
            (private/'base-remapped.sql').write_text(sql); (private/'base-remapped.sql').chmod(0o600)
            owner['baseSnapshotSHA256']=hashlib.sha256(sql.encode()).hexdigest(); save('owner.json',owner)
            snapshot('import-base')
            report['baseRestoration']={'snapshotSHA256':manifest['snapshotSHA256'],'syntheticEnrichment':True}
        data=fixture('seed'); save('fixture.json',data)
        initial=fixture('state'); receipt=snapshot('dump')
        owner['snapshotSHA256']=receipt['snapshotSHA256']; save('owner.json',owner)
        run(['php','bin/storage','destroy','--force','--namespace',ns]); snapshot('restore')
        check(fixture('state')==initial,'enriched snapshot round trip preserves ACL graph')
        report['restoration']=receipt
        reader=snapshot('reader'); save('reader.json',reader)
        sessions={role:client() for role in SUBJECTS};boundary_context={}
        for phase in PHASES:
            if phase=='revoked':
                stop(); report['revocation']=fixture('revoke')
            oracle=fixture('oracle')
            for role in SUBJECTS:
                check({k:set(v) for k,v in oracle[role].items()}==expected(role,phase),'native capability oracle '+phase+' '+role)
            fingerprint=fixture('state')
            phase_dump=receipt if phase=='restored' else snapshot('dump-revoked')
            report['phaseSnapshots'][phase]=dict(phase_dump,permissionStateSHA256=fingerprint['fingerprint'])
            collector=dict(reader,restoredSnapshot=True,host='127.0.0.1',port=int(os.environ['GORGE_TEST_MYSQL_PORT']),
                namespace=ns,snapshotSHA256=phase_dump['snapshotSHA256'],sourceCommit=before['gitHead'],
                snapshotWatermark=datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'),allowPublic=False)
            extracted=subprocess.run(['php',str(ROOT/'scripts/permissions/collect.php')],input=json.dumps(collector),
                text=True,capture_output=True,cwd=ROOT,env=env,timeout=60)
            if extracted.returncode: raise RuntimeError('Read-only collection failed.')
            save(phase+'-snapshot.json',json.loads(extracted.stdout));collected.append(phase)
            start()
            if phase=='restored':
                for role in SUBJECTS[:-1]:
                    check(request(sessions[role],f'/__route/session/{role}/?key='+owner['key'])[0]==200,'synthetic session '+role)
            for role in SUBJECTS:
                c=sessions[role]; allowed=expected(role,phase)['view']
                query='/project/?'+urllib.parse.urlencode({'name':'RouteCanary'+data['token'],'isMilestone':''})
                bulk={'query-list':request(c,query),
                      'feed-list':request(c,'/feed/'),
                      'search':request(c,'/search/?'+urllib.parse.urlencode({'query':'RouteCanary'+data['token'],'types':'PROJ'}))}
                params={'constraints':{'phids':[p['phid'] for p in data['projects'].values()]},'limit':100}
                if role!='anonymous': params['__conduit__']={'token':data['users'][role]['api']}
                auth={'params':json.dumps(params)}
                bulk['conduit']=request(client(),'/api/project.search',auth)
                for alias,p in data['projects'].items():
                    responses={k:v for k,v in bulk.items() if k!='feed-list'}
                    responses.update({'direct-url':request(c,f"/project/profile/{p['id']}/"),
                                      'board':request(c,f"/project/board/{p['id']}/"),
                                      'feed':request(c,f"/feed/{p['storyKey']}/?text=1")})
                    status,edit_body=request(c,f"/project/edit/{p['id']}/")
                    editable='name="name"' in edit_body and p['name'] in edit_body
                    check(editable==(alias in expected(role,phase)['edit']) and status<500,
                          'native edit route '+phase+' '+role+' '+alias)
                    for route,(status,body) in responses.items():
                        present=p['name'] in body or p['phid'] in body
                        want=alias in allowed
                        if route=='feed':
                            list_status,list_body=bulk['feed-list']
                            check((p['name'] in list_body)==want and list_status<500,
                                  'feed list '+phase+' '+role+' '+alias)
                        ok=present==want and (not want or status==200) and status<500
                        report['matrix'].append({'phase':phase,'subject':role,'project':alias,'route':route,
                                                'status':status,'expected':want,'observed':present,'passed':ok})
                        if not ok:
                            (private/'failed-response.txt').write_text(body)
                            raise RuntimeError('Route mismatch '+':'.join((phase,role,alias,route))+f' HTTP {status}')
            for role,alias in [('outsider','ordinary'),('parentEditor','privateSibling')]+([('parentEditor','ordinary')] if phase=='revoked' else []):
                params={'objectIdentifier':data['projects'][alias]['phid'],
                        'transactions':[{'type':'name','value':'Forbidden route fixture mutation'}],
                        '__conduit__':{'token':data['users'][role]['api']}}
                status,body=request(client(),'/api/project.edit',{'params':json.dumps(params)})
                response=json.loads(body)
                denied=response.get('error_code') is not None and response.get('result') is None
                check(status==200 and denied and 'permission' in response.get('error_info','').lower(),
                      'denied Conduit mutation '+phase+' '+role+' '+alias)
            report['boundaryCoverage'][phase]=boundaries.project_reads(data,phase,sessions,request,client,boundary_check)
            report['boundaryCoverage'][phase].update(boundaries.effective_writes(data,phase,request,client,fixture,boundary_check,sessions,boundary_context))
            stop(); check(fixture('state')==fingerprint,'route reads preserve permission state '+phase)
        extra=fixture('seed-boundaries');start()
        report['boundaryCoverage']['cross-object']=boundaries.cross_object(data,extra,sessions,request,client,boundary_check);stop()
        boundaries.validate_coverage(report)
        report['boundaryScope']='Native canonical/member/slug routes, paginated attachments, effective edits, private tasks, configured user rule and Space; adjunct fixtures excluded from offline comparison snapshots.'
        validate_receipt(report); report['passed']=True
    except Exception as e:
        report['failure']=str(e)
        # Private diagnostics never become a CI artifact.
        if os.environ.get('PERMISSION_ROUTES_DEBUG')=='1':
            print('Private diagnostics: '+str(private),flush=True)
        else: print('Permission route acceptance failed: '+str(e),flush=True)
    finally:
        stop()
        try:
            if scoped:
                if any(path.is_symlink() or hashlib.sha256(path.read_bytes()).digest()!=digest for path,digest in owned):
                    raise RuntimeError('Configuration ownership changed; storage cleanup refused.')
                if reader: snapshot('drop-reader')
                run(['php','bin/storage','destroy','--force','--namespace',ns])
            report['cleanup']=True
        except Exception: report['cleanup']=False
        for path,digest in reversed(owned): GATE.remove_configuration(path,digest)
        if report['passed']:
            save('key.json',{'hmacKey':secrets.token_hex(32)})
            report['candidateComparisons']={}
            for phase in collected:
                report['candidateComparisons'][phase]={}
                for model in ('independent-view-v1','explicit-edit-v1','self-descendants-v1','direct-members-v1','combined-v1'):
                    destination=private/(phase+'-'+model)
                    p=subprocess.run(['python3',str(ROOT/'scripts/permissions/audit.py'),'--snapshot',str(private/(phase+'-snapshot.json')),
                        '--key-file',str(private/'key.json'),'--candidate',model,'--output',str(destination)],cwd=ROOT,env=env,capture_output=True,text=True,timeout=120)
                    if p.returncode not in (0,2) or not (destination/'summary.json').is_file():
                        report['passed']=False;report['failure']='Candidate comparison failed.';break
                    summary=json.loads((destination/'summary.json').read_text())
                    report['candidateComparisons'][phase][model]=summary
        if os.environ.get('PERMISSION_ROUTES_DEBUG')!='1': shutil.rmtree(private)
        GATE.record_source_provenance(report,before,GATE.capture_source(ROOT),args.require_clean_worktree)
        report['passed']=report['passed'] and report['cleanup'] and not report.get('sourceErrors')
        GATE.publish_report(out/'route-results.json',report)
    return 0 if report['passed'] else 1

if __name__=='__main__': raise SystemExit(main())

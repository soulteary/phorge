"""Owned loopback HTTP XLSX, mid-export revocation and hierarchy characterization."""
import http.client,json,subprocess,time,urllib.parse,zipfile,io
import xml.etree.ElementTree as ET
from queue_boundaries import Fields


def workbook(raw):
    ns={'m':'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
    with zipfile.ZipFile(io.BytesIO(raw)) as z:
        if z.testzip() is not None:raise ValueError('Corrupt XLSX.')
        strings=[''.join(e.itertext()) for e in ET.fromstring(z.read('xl/sharedStrings.xml'))]
        sheet=ET.fromstring(z.read('xl/worksheets/sheet1.xml'))
        if sheet.findall('.//m:f',ns):raise ValueError('Unexpected executable formula.')
        rows=[]
        for row in sheet.findall('.//m:sheetData/m:row',ns):
            values=[]
            for cell in row.findall('m:c',ns):
                v=cell.find('m:v',ns)
                values.append(strings[int(v.text)] if v is not None and cell.attrib.get('t')=='s' else (v.text if v is not None else ''))
            rows.append(values)
        return rows


def execute(here,root,env,private,check):
    def call(mode,xlsx=False):
        cmd=['php']+(['-d','auto_prepend_file='+str(here/'xlsx/prepend.php')] if xlsx else [])+[str(here/'route_fixture.php'),mode]
        r=subprocess.run(cmd,cwd=root,env=env,capture_output=True,text=True,timeout=180)
        (private/(mode+'-private.log')).write_text(r.stdout+r.stderr)
        if r.returncode:raise RuntimeError('Owned export command failed: '+mode)
        return json.loads(r.stdout)
    seed=call('export-seed');cfg=json.loads((root/'conf/local/local.json').read_text());base=urllib.parse.urlsplit(cfg['phabricator.base-uri'])
    if not base.hostname.endswith('.localhost') or base.scheme!='http':raise ValueError('Owned loopback host required.')
    fixture=json.loads((private/'fixture.json').read_text())
    def request(role,path,data=None):
        if not path.startswith('/') or path.startswith('//'):raise ValueError('Foreign request refused.')
        headers={'Host':base.netloc}
        if role:
            u=fixture['users'][role];headers['Cookie']='phsid='+u['session']+'; phusr='+u['username']
        body=None
        if data is not None:body=urllib.parse.urlencode(data);headers['Content-Type']='application/x-www-form-urlencoded'
        conn=http.client.HTTPConnection('127.0.0.1',base.port,timeout=40)
        try:
            conn.request('GET' if data is None else 'POST',path,body,headers);r=conn.getresponse();return r.status,dict(r.getheaders()),r.read()
        finally:conn.close()
    log=(private/'xlsx-http-private.log').open('w')
    server=subprocess.Popen(['php','-d','opcache.jit=disable','-d','auto_prepend_file='+str(here/'xlsx/prepend.php'),'-S','127.0.0.1:'+str(base.port),'-t',str(root/'webroot'),str(here/'route_router.php')],cwd=root,env=env,stdout=log,stderr=log)
    try:
        for _ in range(100):
            try:
                if request(None,'/__route/ready?key='+json.loads((private/'owner.json').read_text())['key'])[0]==200:break
            except OSError:pass
            if server.poll() is not None:raise RuntimeError('Isolated XLSX HTTP server exited.')
            time.sleep(.1)
        else:raise RuntimeError('Isolated XLSX HTTP readiness failed.')
        for role,want in [('owner',2),('outsider',1)]:
            uri='/maniphest/query/'+seed['queries'][role]+'/export/'
            status,headers,raw=request(role,uri);(private/(role+'-confirmation.html')).write_bytes(raw);(private/(role+'-http.json')).write_text(json.dumps({'status':status,'headers':headers}));fields=Fields();fields.feed(raw.decode())
            check(status==200 and '__csrf__' in fields.values,'HTTP XLSX authenticated confirmation '+role)
            status,headers,raw=request(role,uri,dict(fields.values,format='excel'));(private/(role+'-download-response.bin')).write_bytes(raw);(private/(role+'-download-status.json')).write_text(json.dumps({'status':status,'headers':headers}))
            if status in (301,302,303):
                target=urllib.parse.urlsplit(headers.get('Location',''))
                if target.netloc and target.netloc!=base.netloc:raise ValueError('Foreign download redirect refused.')
                if target.scheme and target.scheme!='http':raise ValueError('Foreign download scheme refused.')
                download=target.path+('?' + target.query if target.query else '')
                status,headers,raw=request(role,download)
                if status==200 and not raw.startswith(b'PK'):
                    confirmation=Fields();confirmation.feed(raw.decode())
                    if '__csrf__' not in confirmation.values:raise ValueError('Native file confirmation missing.')
                    status,headers,raw=request(role,download,confirmation.values)
            check(status==200 and raw.startswith(b'PK'),'HTTP XLSX actual binary response '+role)
            check('spreadsheetml' in headers.get('Content-Type',''),'HTTP XLSX MIME '+role)
            check('attachment' in headers.get('Content-Disposition','').lower() and '.xlsx' in headers.get('Content-Disposition',''),'HTTP XLSX attachment filename '+role)
            rows=workbook(raw);text=json.dumps(rows)
            check(len(rows)==want+1 and 'HTTP XLSX visible canary' in text,'HTTP XLSX parsed visible rows '+role)
            check(('HTTP XLSX private canary' in text)==(role=='owner'),'HTTP XLSX private title boundary '+role)
            status,_,raw=request(role,uri,dict(fields.values,format='excel',__csrf__='invalid'))
            (private/'csrf-status.json').write_text(json.dumps({'status':status}));(private/'csrf-body.html').write_bytes(raw)
            # Malformed requests are client errors; authorization behavior is unchanged.
            check(not raw.startswith(b'PK') and status==400 and b'AphrontMalformedRequestException' in raw and b'csrf' in raw.lower(),'HTTP XLSX invalid CSRF never returns workbook '+role)
        status,_,raw=request(None,uri,{'format':'excel'})
        check(not raw.startswith(b'PK') and status in (200,302,403),'HTTP XLSX anonymous denial')
    finally:
        server.terminate()
        try:server.wait(timeout=10)
        except subprocess.TimeoutExpired:server.kill();server.wait()
        log.close()
    check(server.poll() is not None,'isolated XLSX HTTP server stopped')
    for name in ('export-ready.json','export-release'):(private/name).unlink(missing_ok=True)
    with (private/'export-held.json').open('w') as out,(private/'export-held.log').open('w') as err:
        child=subprocess.Popen(['php','-d','auto_prepend_file='+str(here/'xlsx/prepend.php'),str(here/'route_fixture.php'),'export-hold'],cwd=root,env=env,stdout=out,stderr=err)
        try:
            limit=time.monotonic()+40
            while not (private/'export-ready.json').exists():
                if child.poll() is not None or time.monotonic()>limit:raise RuntimeError('Mid-export barrier failed.')
                time.sleep(.02)
            check(call('export-revoke')['independentRevocation'] and child.poll() is None,'independent revoke after first page render begins')
            (private/'export-release').touch();check(child.wait(timeout=120)==0,'in-flight export completes after independent revoke');out.flush()
            check(json.loads((private/'export-held.json').read_text())['privateFile'],'in-flight XLSX private author file')
        finally:
            (private/'export-release').touch()
            if child.poll() is None:child.kill();child.wait()
    for key,value in call('export-fresh',xlsx=True).items():check(value is True,'fresh export '+key)
    state=json.loads((private/'export-state.json').read_text());loaded,unloaded=state['revoked']
    title=lambda task_id:'Mid-export row '+str(state['rows'].index(task_id))
    mid=workbook((private/'mid-export.xlsx').read_bytes());fresh=workbook((private/'fresh-export.xlsx').read_bytes())
    for name,rows,want in [('in-flight',mid,1001),('fresh',fresh,1000)]:
        titles=[v for row in rows[1:] for v in row if v.startswith('Mid-export row ')]
        expected={title(t) for t in state['rows'] if t not in ([unloaded] if name=='in-flight' else [loaded,unloaded])}
        check(len(rows)==want+1 and len(titles)==want and set(titles)==expected,'exact workbook row set after revocation '+name)
    checks=call('export-hierarchy')
    for key,value in checks.items():check(value is True,'native hierarchy '+key)
    for key,value in call('recovery-baseline').items():check(value is True,'post-export native baseline '+key)
    return {'invalidCSRFStatus':400,'httpWorkbooks':2,'httpAssertions':15,'midExportRows':1001,'freshExportRows':1000,'alreadyLoadedRowRetained':True,'nextPageRevokedRowOmitted':True,'completedSnapshotRetainedByAuthor':True,'hierarchyAssertions':len(checks),'dependencyScope':'isolated-explicit-preload-only','productionConfigurationChanged':False,'httpServerStopped':True}


def validate(report):
    expected={'invalidCSRFStatus':400,'httpWorkbooks':2,'httpAssertions':15,'midExportRows':1001,'freshExportRows':1000,'alreadyLoadedRowRetained':True,'nextPageRevokedRowOmitted':True,'completedSnapshotRetainedByAuthor':True,'hierarchyAssertions':21,'dependencyScope':'isolated-explicit-preload-only','productionConfigurationChanged':False,'httpServerStopped':True}
    if report.get('boundarySchemaVersion')!=6 or report.get('exportCoverage')!=expected:raise ValueError('Complete isolated HTTP/revocation/hierarchy v6 evidence required.')
    return True

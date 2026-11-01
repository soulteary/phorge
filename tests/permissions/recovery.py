"""Explicit optional dependencies, real SIGKILL and loopback Gorge queue tests."""
import hashlib,json,os,socket,subprocess,time,urllib.request,zipfile
from pathlib import Path
import xml.etree.ElementTree as ET


def execute(here,root,env,private,check):
    auto=Path(env['PERMISSION_XLSX_AUTOLOAD']).resolve();binary=Path(env['GORGE_TEST_TASKQUEUE_BINARY']).resolve()
    if not auto.is_file() or not binary.is_file() or root in auto.parents or root in binary.parents:raise ValueError('External isolated dependency and queue binary required.')
    def call(mode,*args,xlsx=False):
        command=['php']
        if xlsx:command+=['-d','auto_prepend_file='+str(here/'xlsx/prepend.php')]
        command +=[str(here/'route_fixture.php'),mode,*args]
        result=subprocess.run(command,cwd=root,env=env,capture_output=True,text=True,timeout=180)
        (private/(mode+'-private.log')).write_text(result.stdout+result.stderr)
        if result.returncode:raise RuntimeError('Owned recovery command failed: '+mode)
        return json.loads(result.stdout)
    values=call('recovery-xlsx',xlsx=True)
    for key,value in values.items():check(value is True,'positive XLSX '+key)
    ns={'m':'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
    for role,want in [('owner',2),('outsider',1)]:
        with zipfile.ZipFile(private/(role+'.xlsx')) as z:
            check(z.testzip() is None,'XLSX ZIP integrity '+role)
            strings=ET.fromstring(z.read('xl/sharedStrings.xml'));text=[''.join(s.itertext()) for s in strings]
            sheet=ET.fromstring(z.read('xl/worksheets/sheet1.xml'));rows=sheet.findall('.//m:sheetData/m:row',ns)
            check(len(rows)==want+1,'XLSX viewer row count '+role)
            check(not sheet.findall('.//m:f',ns),'XLSX formula-like input remains text '+role)
            check(any('=1+2 public canary' in t for t in text),'XLSX positive text '+role)
            check(any('Private XLSX canary' in t for t in text)==(role=='owner'),'XLSX private title exclusion '+role)
            # XLSX may omit empty cells; explicit coordinates must not shift.
            keys=json.loads((private/(role+'-columns.json')).read_text())
            def column(index):
                result='';index+=1
                while index:index,rest=divmod(index-1,26);result=chr(65+rest)+result
                return result
            def value(cell):
                if cell is None:return ''
                v=cell.find('m:v',ns)
                if v is None:return ''
                return text[int(v.text)] if cell.attrib.get('t')=='s' else (v.text or '')
            maps=[{c.attrib['r'].rstrip('0123456789'):c for c in row.findall('m:c',ns)} for row in rows]
            check(len(maps[0])==len(keys) and all('canary' in value(row.get(column(keys.index('title')))) for row in maps[1:]),'XLSX explicit title column '+role)
            check(all(value(row.get(column(keys.index('ownerPHID'))))=='' and value(row.get(column(keys.index('status'))))!='' for row in maps[1:]),'XLSX blank columns retain later coordinates '+role)
            check(all(row[column(keys.index('dateCreated'))].attrib.get('t','n')=='n' for row in maps[1:]),'XLSX native date numeric typing '+role)
    call('recovery-concurrency-seed');outcomes={}
    def wait(predicate,process=None):
        limit=time.monotonic()+30
        while not predicate():
            if time.monotonic()>limit or (process is not None and process.poll() is not None):raise RuntimeError('Recovery barrier failed.')
            time.sleep(.02)
    for kind in ('member','space'):
        for f in ('permission-ready','permission-release'):(private/f).unlink(missing_ok=True)
        with (private/('permission-'+kind+'.json')).open('w') as out,(private/('permission-'+kind+'.log')).open('w') as err:
            child=subprocess.Popen(['php',str(here/'route_fixture.php'),'recovery-permission-hold',kind],cwd=root,env=env,stdout=out,stderr=err)
            try:
                wait(lambda:(private/'permission-ready').exists(),child)
                check(call('recovery-permission-revoke',kind)['revoked'] and child.poll() is None,'independent permission revocation while paused '+kind)
                (private/'permission-release').touch();check(child.wait(timeout=40)==0,'permission edit process completed '+kind);out.flush()
                outcomes[kind]=json.loads((private/('permission-'+kind+'.json')).read_text())['committed']
                check(outcomes[kind] is (kind=='space'),'native in-flight permission outcome '+kind)
                for key,value in call('recovery-permission-verify',kind).items():check(value is True,'fresh permission boundary '+kind+' '+key)
            finally:
                (private/'permission-release').touch()
                if child.poll() is None:child.kill();child.wait()
    with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
    uri=f'http://127.0.0.1:{port}';env['PERMISSION_RECOVERY_QUEUE_URI']=uri
    service_env=dict(env,GORGE_LISTEN_ADDR=f'127.0.0.1:{port}',GORGE_SERVICE_TOKEN='recovery-queue-only',GORGE_TASKQUEUE_BACKEND='mysql',GORGE_TASKQUEUE_MYSQL_HOST='127.0.0.1',GORGE_TASKQUEUE_MYSQL_PORT=env['GORGE_TEST_MYSQL_PORT'],GORGE_TASKQUEUE_MYSQL_USER='root',GORGE_TASKQUEUE_MYSQL_PASS=env['GORGE_TEST_MYSQL_PASSWORD'],GORGE_TASKQUEUE_NAMESPACE=env['PERMISSION_ROUTES_NAMESPACE'],GORGE_TASKQUEUE_SCHEDULER_ENABLED='false',GORGE_TASKQUEUE_LEASE_DURATION='30')
    opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
    log=(private/'gorge-queue-private.log').open('w');service=subprocess.Popen([str(binary)],env=service_env,stdout=log,stderr=log)
    crashes=0
    try:
        def ready():
            try:
                with opener.open(uri+'/readyz',timeout=1) as r:return r.status==200
            except OSError:return False
        wait(ready,service)
        try:
            opener.open(uri+'/api/queue/stats',timeout=2)
            check(False,'Gorge queue rejects missing authentication')
        except urllib.error.HTTPError as ex:check(ex.code==401,'Gorge queue rejects missing authentication')
        for owner in ('native','gorge'):
            for phase in ('creator-before','creator-after','task-before','task-after'):
                check(call('recovery-crash-seed',owner,phase)['seeded'],'crash boundary seeded '+owner+' '+phase)
                with (private/'crashed-worker.json').open('w') as out,(private/'crashed-worker.log').open('w') as err:
                    child=subprocess.Popen(['php',str(here/'route_fixture.php'),'recovery-crash-run'],cwd=root,env=env,stdout=out,stderr=err)
                    try:
                        wait(lambda:call('recovery-crash-observe')['atBarrier'],child)
                        child.kill();check(child.wait(timeout=10)==-9,'actual SIGKILL at database boundary '+owner+' '+phase)
                        for key,value in call('recovery-crash-clean').items():check(value is True,'post-crash state '+owner+' '+phase+' '+key)
                        for key,value in call('recovery-crash-resume').items():check(value is True,'recovered '+owner+' '+phase+' '+key)
                        crashes+=1
                    finally:
                        if child.poll() is None:child.kill();child.wait()
        check(call('recovery-crash-seed','gorge','retry')['seeded'],'Gorge transient retry seeded')
        for key,value in call('recovery-gorge-retry').items():check(value is True,'Gorge transient retry '+key)
        check(service.poll() is None,'paired Gorge queue remained available')
    finally:
        service.terminate()
        try:service.wait(timeout=10)
        except subprocess.TimeoutExpired:service.kill();service.wait()
        log.close()
    check(service.poll() is not None,'paired Gorge queue stopped after isolated tests')
    for key,value in call('recovery-legacy-running').items():check(value is True,'legacy interrupted item '+key)
    for key,value in call('recovery-baseline').items():check(value is True,'isolated override cleanup '+key)
    return {'xlsxWorkbooks':2,'dependencyVersion':'5.10.0','dependencyLockSHA256':hashlib.sha256((here/'xlsx/composer.lock').read_bytes()).hexdigest(),'permissionInFlight':outcomes,'crashBoundaries':crashes,'queueOwners':['native','gorge'],'gorgeTransientRetryCases':1,'serviceStopped':True,'productionConfigurationChanged':False,'legacyRunningPolicy':'manual-reconciliation-no-write','creatorReplay':'acknowledge-versioned-atomic-batch'}


def validate(report):
    c=report.get('recoveryCoverage',{})
    if report.get('boundarySchemaVersion')!=6:raise ValueError('Recovery receipt v6 required.')
    if c.get('xlsxWorkbooks')!=2 or c.get('dependencyVersion')!='5.10.0':raise ValueError('Positive XLSX evidence missing.')
    expected=hashlib.sha256((Path(__file__).parent/'xlsx/composer.lock').read_bytes()).hexdigest()
    if c.get('dependencyLockSHA256')!=expected:raise ValueError('Dependency lock mismatch.')
    if c.get('permissionInFlight')!={'member':False,'space':True}:raise ValueError('Native in-flight boundaries differ.')
    if c.get('gorgeTransientRetryCases')!=1:raise ValueError('Gorge transient retry missing.')
    if c.get('crashBoundaries')!=8 or c.get('queueOwners')!=['native','gorge']:raise ValueError('Crash/queue family missing.')
    if c.get('legacyRunningPolicy')!='manual-reconciliation-no-write' or c.get('creatorReplay')!='acknowledge-versioned-atomic-batch':raise ValueError('Recovery protocol scope missing.')
    if c.get('serviceStopped') is not True or c.get('productionConfigurationChanged') is not False:raise ValueError('Scope/cleanup invalid.')
    return True

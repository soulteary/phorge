"""Native cross-object/queue checks: only owned fixture worker IDs execute."""
from html.parser import HTMLParser
import json
import subprocess
import time
import urllib.parse
from boundaries import object_visible
from route_spec import expected


class Fields(HTMLParser):
    def __init__(self):super().__init__();self.values={}
    def handle_starttag(self,tag,attrs):
        attrs=dict(attrs)
        if tag=='input' and attrs.get('name') and 'disabled' not in attrs:self.values[attrs['name']]=attrs.get('value','')


def web(data,sessions,request,fixture,check):
    selectors=0
    sources={'PhabricatorProjectMembersDatasource':lambda p:'members('+p+')',
             'PhabricatorProjectOrUserDatasource':lambda p:p,
             'PhabricatorProjectLogicalAncestorDatasource':lambda p:p}
    for source,token in sources.items():
        for role in ('owner','outsider'):
            for alias in ('ordinary','privateSibling'):
                p=data['projects'][alias];want=alias in expected(role,'revoked')['view']
                for action in ('class','browse','select'):
                    q={'q':'RouteCanary'+data['token'],'format':'html'}
                    if action=='select':q={'select':json.dumps({'q':q['q'],'offset':0,'phid':token(p['phid'])})}
                    uri='/typeahead/'+('browse' if action=='select' else action)+'/'+source+'/?'+urllib.parse.urlencode(q)
                    status,body=request(sessions[role],uri)
                    check(object_visible(status,body,p['name'],p['phid'],want),'additional selector '+source+' '+role+' '+alias+' '+action)
                    selectors+=1
    formats=fixture('queue-formats')
    for key,value in formats.items():check(value is True,'native additional format '+key)
    seed=fixture('queue-large-seed');uri='/maniphest/query/'+seed['queryKey']+'/export/'
    status,body=request(sessions['outsider'],uri);fields=Fields();fields.feed(body)
    check(status==200 and '__csrf__' in fields.values,'large export native authenticated form')
    # Unavailable Excel follows the native error path; never install a dependency.
    status,error=request(sessions['outsider'],uri,dict(fields.values,format='excel'))
    check(status==200 and ('not installed' in error or '未安装' in error or 'Not Available' in error),'unavailable Excel rejected without enabling it')
    status,body=request(sessions['outsider'],uri,dict(fields.values,format='json'))
    check(status==200,'large export HTTP submission completed')
    result=fixture('queue-large-run')
    for key,value in result.items():check(value is True,'native asynchronous export '+key)
    return {'additionalSelectorCells':selectors,'formatAssertions':len(formats),'largeExportRows':1003,'largeExportAssertions':len(result),'httpAssertions':3}


def workers(here,root,env,private,fixture,check):
    fixture('cross-seed');cases=0;token_controls=0;space_controls=0
    for kind in ('ordinary','milestone'):
        for name in ('cross-ready','cross-release','cross-revoker'):(private/name).unlink(missing_ok=True)
        resources=[];processes=[]
        def spawn(mode):
            out=open(private/(mode+'-'+kind+'.json'),'w');err=open(private/(mode+'-'+kind+'.log'),'w');resources.extend([out,err])
            p=subprocess.Popen(['php',str(here/'route_fixture.php'),mode,kind],cwd=root,env=env,stdout=out,stderr=err);processes.append(p);return p
        def wait_for(predicate):
            limit=time.monotonic()+30
            while not predicate():
                if time.monotonic()>limit or any(p.poll() not in (None,0) for p in processes):raise RuntimeError('Cross-object barrier/worker failed.')
                time.sleep(.02)
        held=spawn('cross-hold')
        try:
            wait_for(lambda:(private/'cross-ready').exists());revoke=spawn('cross-revoke')
            wait_for(lambda:(private/'cross-revoker').exists())
            if kind=='ordinary':
                wait_for(lambda:revoke.poll() is not None)
                check(revoke.returncode==0 and held.poll() is None,'ordinary ancestor revocation commits while child paused')
            else:
                wait_for(lambda:fixture('cross-lock-wait',kind)['waiting'])
                check(held.poll() is None and revoke.poll() is None,'milestone ancestor revocation observed waiting in database')
            (private/'cross-release').write_text('release')
            check(held.wait(timeout=40)==0,'cross-object native edit worker completed '+kind)
            check(revoke.wait(timeout=40)==0,'cross-object native revoker completed '+kind)
            for stream in resources:stream.flush()
            result=json.loads((private/('cross-hold-'+kind+'.json')).read_text())
            check(result['inFlightCommitted'] is True,'native in-flight ancestor boundary '+kind)
            for key,value in fixture('cross-verify',kind).items():
                check(value is True,'fresh cross-object policy '+kind+' '+key)
                if key in ('policyOnlyTokensUnchanged','renameTokensUpdated'):token_controls+=1
                if key=='parentSpaceStillSynchronizes':space_controls+=1
            cases+=1
        finally:
            (private/'cross-release').touch()
            for process in processes:
                if process.poll() is None:
                    process.terminate()
                    try:process.wait(timeout=5)
                    except subprocess.TimeoutExpired:process.kill();process.wait()
            for stream in resources:stream.close()
    retry=fixture('queue-retry')
    for key,value in retry.items():check(value is True,'native leased queue retry '+key)
    return {'ancestorCases':cases,'tokenControls':token_controls,'spaceSynchronizationControls':space_controls,'retryAssertions':len(retry),'queueOwner':'native-sql','genericDaemonsStarted':0,'inFlightAncestorSemantics':'ordinary-loaded-parent/milestone-sync-waits-child;fresh-denies'}


def validate(report):
    if report.get("boundarySchemaVersion")!=6:raise ValueError("Queue evidence version required.")
    if report.get('queueWebCoverage')!={'additionalSelectorCells':36,'formatAssertions':25,'largeExportRows':1003,'largeExportAssertions':10,'httpAssertions':3}:raise ValueError('Incomplete queue/export route evidence.')
    if report.get('queueWorkerCoverage')!={'ancestorCases':2,'tokenControls':4,'spaceSynchronizationControls':1,'retryAssertions':24,'queueOwner':'native-sql','genericDaemonsStarted':0,'inFlightAncestorSemantics':'ordinary-loaded-parent/milestone-sync-waits-child;fresh-denies'}:raise ValueError('Incomplete cross-object/leased worker evidence.')
    return True

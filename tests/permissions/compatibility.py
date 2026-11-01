"""Bounded native warning/legacy slug routes; never run the conversion editor."""
import copy
from html.parser import HTMLParser
import urllib.error
import urllib.parse
import urllib.request
from route_spec import SUBJECTS,PHASES,expected

ALIASES=('ordinary','privateSibling','hiddenParent','openChild')

class NoRedirect(urllib.request.HTTPRedirectHandler):
    handler_order=1
    def redirect_request(self,*args):return None
    def http_error_302(self,request,response,code,message,headers):
        # The session opener retains its default redirect handler. Raising
        # stops the handler chain instead of falling through to that handler.
        raise urllib.error.HTTPError(request.full_url,code,message,headers,response)
    http_error_301=http_error_303=http_error_307=http_error_308=http_error_302

class Fields(HTMLParser):
    def __init__(self):super().__init__();self.fields={}
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if tag=='input' and a.get('name') and 'disabled' not in a:self.fields[a['name']]=a.get('value','')

def expected_coverage(phase):
    if phase not in PHASES:raise ValueError('Unknown native compatibility phase.')
    posts=sum(a in expected(role,phase)['edit'] for role in SUBJECTS for a in ALIASES)
    return {'warningGET':40,'warningPOST':posts,'invalidCSRF':posts,'legacyAliasGET':40,
            'noncanonicalGET':80,'unknownGET':20,'stateUnchanged':True}

def validate(report):
    if report.get('compatibilitySchemaVersion')!=1:raise ValueError('Compatibility receipt version required.')
    if report.get('compatibilityCoverage')!={p:expected_coverage(p) for p in PHASES}:raise ValueError('Incomplete native compatibility routes.')
    checks=report.get('compatibilityChecks',[])
    # GETs: 180/phase; authorized POST and CSRF: 3/post; final state: 1/phase.
    count=sum(181+3*expected_coverage(p)['warningPOST'] for p in PHASES)
    if len(checks)!=count or len({c.get('name') for c in checks})!=count or any(c.get('passed') is not True for c in checks):raise ValueError('Missing, duplicate or failed native compatibility assertions.')
    return True

def execute(data,aliases,phase,sessions,client,base,fixture,check):
    coverage=dict.fromkeys(('warningGET','warningPOST','invalidCSRF','legacyAliasGET','noncanonicalGET','unknownGET'),0)
    before=fixture('compatibility-state')
    for role in SUBJECTS:
        c=client();c.add_handler(NoRedirect())
        old=next(h.cookiejar for h in sessions[role].handlers if isinstance(h,urllib.request.HTTPCookieProcessor))
        jar=next(h.cookiejar for h in c.handlers if isinstance(h,urllib.request.HTTPCookieProcessor))
        for cookie in old:jar.set_cookie(copy.copy(cookie))
        def request(uri,fields=None):
            if not uri.startswith('/') or uri.startswith('//'):raise ValueError('Foreign compatibility target.')
            req=urllib.request.Request(base+uri,data=None if fields is None else urllib.parse.urlencode(fields).encode())
            try:r=c.open(req,timeout=30)
            except urllib.error.HTTPError as e:r=e
            with r:return r.status,r.headers,r.read().decode()
        allowed=expected(role,phase)
        for alias in ALIASES:
            p=data['projects'][alias];uri=f"/project/warning/{p['id']}/"
            status,headers,body=request(uri);want=alias in allowed['edit']
            check(status<500 and ('Convert to Parent Project' in body)==want and (alias in allowed['view'] or (p['name'] not in body and p['phid'] not in body)),f'warning GET {phase} {role} {alias}');coverage['warningGET']+=1
            if want:
                fields=Fields();fields.feed(body);valid=fields.fields
                check(bool(valid.get('__csrf__')),f'warning CSRF present {phase} {role} {alias}')
                status,headers,body=request(uri,dict(valid,__csrf__='invalid'))
                check(status==400 and 'AphrontMalformedRequestException' in body,f'warning invalid CSRF {phase} {role} {alias}');coverage['invalidCSRF']+=1
                status,headers,body=request(uri,valid)
                check(status==302 and headers.get('Location') in (f"/project/edit/?parent={p['id']}",base+f"/project/edit/?parent={p['id']}"),f'warning POST redirect only {phase} {role} {alias}');coverage['warningPOST']+=1
            item=aliases[alias]
            # Colon is a valid path character in these historical aliases.
            # Native routing keeps percent-encoded URI data literal, so an
            # encoded spelling is not interchangeable with the stored slug.
            paths=[('legacyAliasGET','/tag/'+urllib.parse.quote(item['raw'],safe=':')+'/')]
            paths.extend(('noncanonicalGET','/tag/'+urllib.parse.quote(p['slug'].upper(),safe='')+'/'+suffix) for suffix in ('','board/'))
            for kind,path in paths:
                status,headers,body=request(path);visible=alias in allowed['view']
                # Native hidden noncanonical requests may redirect to the
                # normalization of caller-supplied text, then deny/404. That
                # does not disclose the stored primary slug.
                target='/tag/'+(item['canonical'] if visible else (item['normalized'] if kind=='legacyAliasGET' else p['slug']))+'/'
                location=headers.get('Location','')
                if visible:ok=status==302 and location in (target,base+target)
                else:ok=status<500 and p['name'] not in body and p['phid'] not in body and (status!=302 or location in (target,base+target))
                check(ok,f'{kind} {phase} {role} {alias} {path.endswith("board/")}');coverage[kind]+=1
        for suffix in ('','board/'):
            status,headers,body=request('/tag/missingcompat'+data['token']+'/'+suffix)
            check(status<500 and all(p['name'] not in body and p['phid'] not in body for p in data['projects'].values()),f'unknown slug {phase} {role} {suffix}');coverage['unknownGET']+=1
    coverage['stateUnchanged']=fixture('compatibility-state')==before
    check(coverage['stateUnchanged'],'compatibility no project/edge/slug/transaction mutation '+phase)
    if coverage!=expected_coverage(phase):raise ValueError('Native compatibility route count mismatch.')
    return coverage

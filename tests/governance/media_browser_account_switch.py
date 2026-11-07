"""Native second-account browser slice, composed after reviewed ten-check flow.

Parent captures/pins both this module and its existing reviewed baseline flow.
No launcher, trust changes, cookie setters, HTTP identity or session fabrication.
"""
import hashlib
import hmac
import os
import re
from urllib.parse import urlsplit, parse_qsl

CHECKS = ('original-native-ten-check-flow-passed',
          'same-context-second-native-password-login',
          'second-session-differs-from-author-native-session',
          'original-media-cookies-actually-sent-and-denied-after-switch',
          'second-account-new-one-time-exchange-own-private-file-positive',
          'second-account-media-cannot-read-original-author-private-file')

class Unproved(RuntimeError):
    pass

def need(v):
    if v is not True:
        raise Unproved('native_media_account_switch_unproved')

def private_account(manifest, app, files):
    x = manifest.get('account_switch')
    need(type(x) is dict and set(x) == {'schema_version','scope','username','password',
        'user_phid','author_phid','author_is_admin','native_password_verified','is_admin','is_file_author',
        'browser_session_preseeded','own_file_uri','own_authorize_uri','own_content_sha256'})
    need(type(x['schema_version']) is int and x['schema_version'] == 2 and
         x['scope'] == 'owned-native-media-nonauthor-account-switch-v2')
    need(x['author_is_admin'] is False and x['native_password_verified'] is True and x['is_admin'] is False and
         x['is_file_author'] is False and x['browser_session_preseeded'] is False)
    need(all(type(x[k]) is str and re.fullmatch(r'PHID-USER-[a-z0-9]{20}', x[k])
             for k in ('user_phid','author_phid')) and x['user_phid'] != x['author_phid'])
    need(type(x['username']) is str and re.fullmatch(r'mediaswitch[a-f0-9]{12}',x['username']) is not None)
    need(type(x['password']) is str and 24 <= len(x['password']) <= 128)
    need(type(x['own_content_sha256']) is str and re.fullmatch('[a-f0-9]{64}',x['own_content_sha256']) is not None)
    for k,origin,path in [('own_file_uri',files,'/file/'),('own_authorize_uri',app,'/auth/media/authorize/')]:
        u=urlsplit(x[k]);need(u.scheme+'://'+u.netloc == origin and not u.fragment and
                             u.username is None and u.password is None and u.path.startswith(path))
    need(not urlsplit(x['own_file_uri']).query and
         parse_qsl(urlsplit(x['own_authorize_uri']).query,keep_blank_values=True)==[('path',urlsplit(x['own_file_uri']).path)])
    need(x['own_file_uri'] != manifest['private_file_uri'])
    return x

def run(context, manifest, baseline_flow, *, approved_profile):
    """Real BrowserContext and captured reviewed flow module supplied by parent.

    Host tests replace browser transport only. No returned result by itself
    proves TLS, source installation, SQL/process cleanup or parent authority.
    """
    app,files=baseline_flow.validate_manifest(manifest,approved_profile)
    account=private_account(manifest,app,files)
    need(context.cookies()==[])
    key=os.urandom(32)
    digest=lambda v:hmac.new(key,v.encode(),hashlib.sha256).digest()
    author_session=None
    ordinary_login_post=False
    error=False
    phase='baseline'
    sent=set()
    old_media=None
    ordinary_observer=None
    ordinary_observe=None
    def observe(request):
        nonlocal author_session,error,ordinary_login_post
        try:
            u=urlsplit(request.url)
            headers=request.all_headers()
            cookies=baseline_flow.cookie_values(headers.get('cookie',''))
            if phase=='ordinary-login' and request.url==manifest['login_uri'] and request.method=='POST':
                ordinary_login_post=True
            if request.url==manifest['manual_authorize_uri'] and phase=='baseline':
                need('phsid' in cookies and type(cookies['phsid']) is str and bool(cookies['phsid']))
                value=digest(cookies['phsid'])
                need(author_session is None or hmac.compare_digest(author_session,value))
                author_session=value
            if request.url==manifest['private_file_uri'] and phase=='switched-old':
                old_media.assert_header(headers.get('cookie',''))
                sent.add('switched-old')
            if u.scheme+'://'+u.netloc==files:
                need(not (set(cookies)&{'phsid','phusr','phcid','phreg','next_uri','jump_to_hisec','invite','phfrm'}))
        except BaseException:
            error=True
    context.on('request',observe)
    try:
        receipt=baseline_flow.run(context,manifest,approved_profile=approved_profile)
        need(receipt.get('passed') is True and receipt.get('real_browser_flow_executed') is True and tuple(receipt.get('checks',()))==baseline_flow.EXPECTED_CHECKS)
        need(author_session is not None and error is False)
        old_media=baseline_flow.OldMediaCookies(context.cookies(),approved_profile)
        need(not any(c.get('name')=='phsid' and c.get('domain')==urlsplit(app).hostname for c in context.cookies()))
        checks=[CHECKS[0]]
        phase='ordinary-login'
        page=context.new_page()
        page.goto(manifest['login_uri'],wait_until='domcontentloaded')
        page.locator('input[name="username"]').fill(account['username'])
        page.locator('input[name="password"]').fill(account['password'])
        page.get_by_role('button',name='Log In',exact=True).click()
        page.wait_for_load_state('domcontentloaded')
        page.goto(app+'/',wait_until='domcontentloaded')
        need(ordinary_login_post is True and error is False)
        cookies=[c for c in context.cookies()if c.get('name')=='phsid' and c.get('domain')==urlsplit(app).hostname]
        need(len(cookies)==1 and type(cookies[0].get('value')) is str and bool(cookies[0]['value']))
        need(not hmac.compare_digest(digest(cookies[0]['value']),author_session))
        old_media.assert_jar(context.cookies())
        checks.extend(CHECKS[1:3])
        probe=context.new_page();probe.goto(files+'/media/session/bridge/',wait_until='domcontentloaded')
        fetch='''async input => {const r=await fetch(input.url,{method:'GET',credentials:'include',cache:'no-store',redirect:'error'});return {status:r.status,body:await r.text()};}'''
        phase='switched-old'
        r=probe.evaluate(fetch,{'url':manifest['private_file_uri']})
        need(r.get('status')==403 and manifest['private_content_marker'] not in r.get('body',''))
        need(sent=={'switched-old'} and error is False)
        old_media.assert_jar(context.cookies());checks.append(CHECKS[3])
        phase='ordinary-exchange'
        ordinary_observer=baseline_flow.Observer(app,files)
        ordinary_observer.allow_file_probe_top_level=True
        def ordinary_observe(request):
            ordinary_observer.request(request.url,request.method,request.all_headers())
        context.on('request',ordinary_observe)
        with page.expect_response(lambda r:r.url==account['own_file_uri'] and r.request.method=='GET')as info:
            page.goto(account['own_authorize_uri'],wait_until='domcontentloaded')
            page.wait_for_url(account['own_file_uri'])
        response=info.value
        need(response.status==200 and hashlib.sha256(response.body()).hexdigest()==account['own_content_sha256'])
        need('no-store' in response.all_headers().get('cache-control',''))
        ordinary_observer.assert_complete()
        baseline_flow.verify_cookies(context.cookies(),approved_profile)
        current=baseline_flow.OldMediaCookies(context.cookies(),approved_profile)
        # The nonauthor received working new media credentials, so denial is not
        # explained by an absent/broken exchange. Prove these exact values sent.
        phase='ordinary-policy'
        policy_sent=[]
        def policy_request(request):
            if request.url==manifest['private_file_uri']:
                try:current.assert_header(request.all_headers().get('cookie',''));policy_sent.append(True)
                except BaseException:policy_sent.append(False)
        context.on('request',policy_request)
        try:r=probe.evaluate(fetch,{'url':manifest['private_file_uri']})
        finally:context.remove_listener('request',policy_request)
        need(r.get('status')==403 and manifest['private_content_marker'] not in r.get('body','') and policy_sent==[True] and error is False)
        checks.extend(CHECKS[4:]);need(tuple(checks)==CHECKS)
        return {'scope':'owned-native-media-account-switch-browser-slice','passed':True,
                'checks':checks,'check_count':len(checks),'cookie_injection':False,
                'TLS_provenance_proved':False,'SQL_process_cleanup_proved':False,'deployment':False,
                'baseline_flow':receipt}
    finally:
        context.remove_listener('request',observe)
        if ordinary_observe is not None:context.remove_listener('request',ordinary_observe)

"""Concrete normal-system-TLS guest driver, never private-CA positive trust.

Executed only in the separately owned immutable Linux browser container. Root
pins all profiles/assets independently; no caller URL/hash provides authority.
"""
from contextlib import contextmanager
import hashlib
import importlib.metadata
import os
import platform
from pathlib import Path
import re
import sys
from . import approved_origins as origins,entry,launcher,flow,trust_controls

RUNTIME_ANCHOR_SHA256 = None
TLS_ENDPOINT_PROFILE_SHA256 = None
TLS_ENDPOINT_PROFILE = None
DEPENDENCIES={'playwright':'1.64.1','pyee':'14.0.0','greenlet':'3.5.6','typing-extensions':'4.15.0'}

class NormalTLSUnproved(RuntimeError):
    def __init__(self,primary=None,cleanup=None):
        super().__init__('owned_normal_TLS_browser_unproved')
        self.primary,self.cleanup=primary,cleanup

def need(value):
    if value is not True:raise NormalTLSUnproved()

def endpoint_profile(profile,native_tuple):
    # Before platform, anchor, imports, filesystem or browser/network I/O.
    need(origins.digest(RUNTIME_ANCHOR_SHA256) and origins.digest(TLS_ENDPOINT_PROFILE_SHA256) and type(TLS_ENDPOINT_PROFILE) is bytes)
    need(hashlib.sha256(TLS_ENDPOINT_PROFILE).hexdigest()==TLS_ENDPOINT_PROFILE_SHA256)
    value=origins.decode(TLS_ENDPOINT_PROFILE)
    need(type(value) is dict and set(value)=={'scope','origin_review_sha256','native_tuple','negatives','normal_system_trust','global_trust_changes','certificate_bypass','endpoint_assets_review_sha256'})
    need(value['scope']=='owned-controlled-normal-TLS-endpoints-v1' and value['origin_review_sha256']==origins.ROOT_APPROVED_ORIGIN_REVIEW_SHA256 and value['native_tuple']==native_tuple and value['normal_system_trust'] is True and value['global_trust_changes'] is False and value['certificate_bypass'] is False and origins.digest(value['endpoint_assets_review_sha256']))
    need(type(native_tuple) is dict and set(native_tuple)=={'source_head','source_tree','app_origin','file_origin','transport_proof_sha256'})
    need(all(type(native_tuple[k]) is str and re.fullmatch('[a-f0-9]{40}',native_tuple[k]) is not None for k in ('source_head','source_tree')) and origins.digest(native_tuple['transport_proof_sha256']))
    app,files=origins.verified(profile)
    need(native_tuple['app_origin']==app and native_tuple['file_origin']==files)
    negatives=value['negatives'];need(type(negatives) is dict and set(negatives)=={'untrusted-chain','wrong-hostname'})
    hosts=[]
    for phase in negatives:
        record=negatives[phase]
        need(type(record) is dict and set(record)=={'origin','certificate_sha256','server_asset_sha256'})
        _,host=origins.canonical_origin(record['origin']);need(origins.digest(record['certificate_sha256']) and origins.digest(record['server_asset_sha256']))
        hosts.append(host)
    positive_hosts=[origins.canonical_origin(v)[1] for v in (app,files)]
    need(len(set(hosts+positive_hosts))==4)
    return value

def negative(context,origin,phase):
    need(phase in ('untrusted-chain','wrong-hostname'))
    code='net::ERR_CERT_AUTHORITY_INVALID' if phase=='untrusted-chain' else 'net::ERR_CERT_COMMON_NAME_INVALID'
    observer=trust_controls.FailureObservation(origin+'/',code)
    page=context.new_page();session=None;primary=None;cleanup=[]
    try:
        session=context.new_cdp_session(page);session.send('Network.enable')
        for event,kind in [('Network.requestWillBeSent','request'),('Network.loadingFailed','failure'),('Network.responseReceived','response')]:
            session.on(event,lambda v,kind=kind:observer.event(kind,v))
        try:page.goto(origin+'/',wait_until='domcontentloaded',timeout=10000)
        except Exception:pass # only actual exact CDP Document events are proof
        observer.verify()
    except BaseException as e:primary=e
    finally:
        if session is not None:
            try:session.detach()
            except BaseException as e:cleanup.append(e)
        try:page.close()
        except BaseException as e:cleanup.append(e)
    if primary is not None or cleanup:raise NormalTLSUnproved(primary,tuple(cleanup)) from None
    return {'phase':phase,'actual_document_certificate_failure_observed':True}

@contextmanager
def open_owned_context(profile,native_tuple,*,nonce,owner):
    endpoints=endpoint_profile(profile,native_tuple)
    need(sys.platform=='linux' and os.geteuid()==1000 and sys.version_info[:2]==(3,12) and platform.machine()=='aarch64')
    need(all(importlib.metadata.version(name)==version for name,version in DEPENDENCIES.items()))
    need(not any(name in os.environ for name in ('SSL_CERT_FILE','SSL_CERT_DIR','NODE_EXTRA_CA_CERTS','NODE_OPTIONS','PLAYWRIGHT_NODEJS_PATH','CURL_CA_BUNDLE','REQUESTS_CA_BUNDLE')))
    # Reuse the immutable reviewed anchor and actual fixed executable verifier.
    entry.ANCHOR_SHA256=RUNTIME_ANCHOR_SHA256
    anchor=entry.read_anchor();launcher.BROWSER_SHA256=anchor['browser_sha256'];launcher.executable()
    home,identity=entry.private_home(nonce,owner) # no certificate passed/imported
    parent_fd=os.open(home,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
    try:
        launcher.home_identity(home,identity);os.mkdir('normal-profile',0o700,dir_fd=parent_fd)
        launcher.home_identity(home,identity)
    finally:os.close(parent_fd)
    driver=None;browser=None;context=None;primary=None;cleanup=[]
    try:
        from playwright.sync_api import sync_playwright
        driver=sync_playwright().start()
        browser=driver.chromium.launch(executable_path=launcher.BROWSER,headless=True,chromium_sandbox=True,
            env={'HOME':str(home),'PATH':'/usr/bin:/bin','LANG':'en_US.UTF-8','LC_ALL':'en_US.UTF-8'},
            args=['--no-proxy-server','--disable-background-networking'])
        # Nonpersistent context is empty, independently closed and never receives
        # caller cookies/storage/header overrides or resolver/certificate flags.
        context=browser.new_context(ignore_https_errors=False,accept_downloads=False)
        need(context.cookies()==[])
        for phase,record in endpoints['negatives'].items():negative(context,record['origin'],phase)
        need(context.cookies()==[])
        launcher.home_identity(home,identity)
        yield context
    except BaseException as e:primary=e
    finally:
        for obj in (context,browser,driver):
            if obj is None:continue
            try:obj.stop() if obj is driver else obj.close()
            except BaseException as e:cleanup.append(e)
        try:launcher.home_identity(home,identity)
        except BaseException as e:cleanup.append(e)
    if primary is not None or cleanup:raise NormalTLSUnproved(primary,tuple(cleanup)) from None

def execute_guest(raw):
    # Own pinned endpoint/anchor roles must exist before even decoding payload.
    need(origins.digest(RUNTIME_ANCHOR_SHA256) and origins.digest(TLS_ENDPOINT_PROFILE_SHA256) and type(TLS_ENDPOINT_PROFILE) is bytes)
    need(type(raw) is bytes and len(raw)<=1048576)
    data=origins.decode(raw)
    need(type(data) is dict and set(data)=={'native_tuple','manifest','origin_review','nonce','owner'})
    need(type(data['origin_review']) is str and re.fullmatch('[a-f0-9]{24}',data['nonce']) is not None and re.fullmatch('[a-f0-9]{64}',data['owner']) is not None)
    profile=origins.approve(data['origin_review'].encode(),data['manifest'].get('remote_origin_profile'))
    with open_owned_context(profile,data['native_tuple'],nonce=data['nonce'],owner=data['owner']) as context:
        result=flow.run(context,data['manifest'],approved_profile=profile)
    return {'scope':'owned-normal-TLS-native-flow-v2','passed':True,'flow':result,'normal_TLS_negatives_observed':True,
            'context_browser_driver_closed':True,'global_trust_changed':False,'public_route_authorized':False,'deployment':False}

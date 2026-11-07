"""Versioned normal-TLS guest entry and strict six-obligation public consumer.

The legacy ten-obligation consumer is called unchanged. Root must capture/pin
this entry, the slice and all native/normal-TLS dependencies as one package.
"""
import hashlib
import json
import re
import os
import stat
from pathlib import Path
try:
    from . import media_browser_account_switch as switch, media_browser_legacy_obligations as obligations
except ImportError:
    import media_browser_account_switch as switch
    import media_browser_legacy_obligations as obligations

ROOT_REVIEW_BYTES = None
ROOT_REVIEW_SHA256 = None
SOURCE_PIN_KEYS = {'source_revision','fixture_sha256','switch_sha256',
                   'baseline_flow_sha256','legacy_consumer_sha256',
                   'normal_TLS_provider_sha256','legacy_obligations_sha256'}
SCOPE = 'owned-normal-TLS-native-media-account-switch-v4-measured'

class Unproved(RuntimeError):
    pass

def need(v):
    if v is not True:raise Unproved('native_media_account_switch_v3_unproved')

def decode(raw):
    def unique(rows):
        d={}
        for k,v in rows:
            need(k not in d);d[k]=v
        return d
    return json.loads(raw,object_pairs_hook=unique)

def _review():
    # Authority bytes are captured/bound by Root, not accepted from guest input.
    need(type(ROOT_REVIEW_BYTES)is bytes and 0<len(ROOT_REVIEW_BYTES)<=16384 and
         type(ROOT_REVIEW_SHA256)is str and re.fullmatch('[a-f0-9]{64}',ROOT_REVIEW_SHA256)is not None)
    need(hashlib.sha256(ROOT_REVIEW_BYTES).hexdigest()==ROOT_REVIEW_SHA256)
    r=decode(ROOT_REVIEW_BYTES)
    need(type(r)is dict and set(r)=={'scope','approved','source_pins','normal_TLS_required','public_route_authorized','deployment','native_source'})
    need(r['scope']==SCOPE and r['approved']is True and r['normal_TLS_required']is True and r['public_route_authorized']is False and r['deployment']is False)
    pins=r['source_pins'];need(type(pins)is dict and set(pins)==SOURCE_PIN_KEYS)
    need(type(pins['source_revision'])is str and re.fullmatch('[a-f0-9]{40}',pins['source_revision'])is not None)
    need(all(type(v)is str and re.fullmatch('[a-f0-9]{64}',v)is not None for k,v in pins.items()if k!='source_revision'))
    n=r['native_source']
    need(type(n)is dict and set(n)=={'files','aliases','manifest_sha256','aliases_sha256','source_root_identity','native_tuple','local_forward_port','receiver_sha256'})
    need(type(n['files'])is int and 0<n['files']<=10000 and type(n['aliases'])is int and 0<=n['aliases']<=n['files'])
    for k in ('manifest_sha256','aliases_sha256','receiver_sha256'):need(type(n[k])is str and re.fullmatch('[a-f0-9]{64}',n[k])is not None)
    ident=n['source_root_identity'];need(type(ident)is dict and set(ident)=={'dev','ino'} and all(type(v)is int and v>0 for v in ident.values()))
    t=n['native_tuple'];need(type(t)is dict and set(t)=={'source_head','source_tree','app_origin','file_origin','transport_proof_sha256'} and all(type(v)is str for v in t.values())and t['source_head']==pins['source_revision'])
    need(re.fullmatch('[a-f0-9]{40}',t['source_tree'])is not None and re.fullmatch('[a-f0-9]{64}',t['transport_proof_sha256'])is not None)
    need(type(n['local_forward_port'])is int and 1024<=n['local_forward_port']<=65535)
    return r

def source_review():return _review()['source_pins']
def native_expectation():return _review()['native_source']

def validate_native_measurement(v,contract):
    pins=source_review();n=native_expectation()
    need(type(v)is dict and set(v)=={'scope','passed','files','aliases','manifest_sha256','aliases_sha256','source_root_identity','actual_fixture_sha256','measurement_nonce','native_tuple','local_forward_port','native_owner_sha256','receiver_sha256','guest_receipt_sha256'})
    need(v['scope']=='owned-native-media-full-source-measurement-v1'and v['passed']is True)
    need(type(v['files'])is int and v['files']==n['files']and type(v['aliases'])is int and v['aliases']==n['aliases'])
    need(type(v['source_root_identity'])is dict and set(v['source_root_identity'])=={'dev','ino'}and all(type(x)is int and x>0 for x in v['source_root_identity'].values())and v['source_root_identity']==n['source_root_identity'])
    for k in ('manifest_sha256','aliases_sha256','receiver_sha256'):need(type(v[k])is str and v[k]==n[k])
    need(type(v['actual_fixture_sha256'])is str and v['actual_fixture_sha256']==pins['fixture_sha256'])
    need(type(v['native_tuple'])is dict and all(type(x)is str for x in v['native_tuple'].values())and v['native_tuple']==contract==n['native_tuple'])
    need(type(v['local_forward_port'])is int and v['local_forward_port']==n['local_forward_port'])
    for k in ('measurement_nonce','native_owner_sha256','guest_receipt_sha256'):need(type(v[k])is str and re.fullmatch('[a-f0-9]{64}',v[k])is not None)
    return v

def module_pin(module,pin):
    # Captured module location must still agree with the actual fixed source.
    p=Path(module.__file__);need(p.is_absolute()and p.resolve()==p)
    parents=[(x,os.lstat(x))for x in p.parents]
    need(all(stat.S_ISDIR(s.st_mode)for _,s in parents))
    fd=os.open(p,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    stamp=lambda s:(s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid,s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
    try:
        a=os.fstat(fd);need(stat.S_ISREG(a.st_mode)and a.st_uid==os.getuid()and a.st_nlink==1 and not(a.st_mode&0o022)and 0<a.st_size<=1048576)
        raw=b''
        while len(raw)<a.st_size:
            part=os.read(fd,min(65536,a.st_size-len(raw)));need(bool(part));raw+=part
        need(stamp(a)==stamp(os.fstat(fd))==stamp(os.lstat(p))and hashlib.sha256(raw).hexdigest()==pin)
        need(all((s.st_dev,s.st_ino,s.st_mode)==(os.lstat(x).st_dev,os.lstat(x).st_ino,os.lstat(x).st_mode)for x,s in parents))
    finally:os.close(fd)

def consume(value,legacy_consumer):
    pins=source_review()
    module_pin(obligations,pins['legacy_obligations_sha256'])
    module_pin(legacy_consumer,pins['legacy_consumer_sha256'])
    need(type(value)is dict and set(value)=={'scope','passed','baseline','account_switch','source_pins','normal_TLS_negatives_observed','context_browser_driver_closed','global_trust_changed','public_route_authorized','deployment','native_measurement'})
    need(value['scope']==SCOPE and type(value['source_pins'])is dict and value['source_pins']==pins and value['passed']is True)
    need(all(value[k]is True for k in ('normal_TLS_negatives_observed','context_browser_driver_closed')) and all(value[k]is False for k in ('global_trust_changed','public_route_authorized','deployment')))
    measured=validate_native_measurement(value['native_measurement'],native_expectation()['native_tuple'])
    # Exact old consumer receives its old shape, never six new checks mixed in.
    baseline=legacy_consumer.guest_receipt(dict(scope='owned-normal-TLS-native-flow-v2',passed=True,
        flow=value['baseline'],normal_TLS_negatives_observed=True,context_browser_driver_closed=True,
        global_trust_changed=False,public_route_authorized=False,deployment=False))
    need(type(baseline['flow'].get('check_count'))is int and baseline['flow']['check_count']==10 and baseline['flow'].get('real_browser_flow_executed')is True)
    v=value['account_switch']
    need(type(v)is dict and set(v)=={'scope','passed','checks','check_count','cookie_injection','TLS_provenance_proved','SQL_process_cleanup_proved','deployment'})
    need(v['scope']=='owned-native-media-account-switch-browser-slice'and v['passed']is True and type(v['checks'])is list and tuple(v['checks'])==switch.CHECKS and type(v['check_count'])is int and v['check_count']==6)
    need(all(v[k]is False for k in ('cookie_injection','TLS_provenance_proved','SQL_process_cleanup_proved','deployment')))
    # Do not copy arbitrary legacy receipt fields into a public record.
    return {'scope':SCOPE,'passed':True,'baseline_check_count':len(baseline['flow']['checks']),
            'account_switch_checks':list(switch.CHECKS),'account_switch_check_count':6,
            'source_pins':dict(pins),'normal_TLS_negatives_observed':True,
            'context_browser_driver_closed':True,'cookie_injection':False,
            'SQL_process_cleanup_proved':False,'public_route_authorized':False,'deployment':False,
            'native_source_verified':True,'actual_fixture_sha256':measured['actual_fixture_sha256'],
            'full_source_manifest_sha256':measured['manifest_sha256'],
            'native_tuple_sha256':hashlib.sha256(json.dumps(measured['native_tuple'],sort_keys=True,separators=(',',':')).encode()).hexdigest()}

def execute_guest(raw,*,provider,baseline_flow,legacy_consumer):
    pins=source_review() # before module/file/input/browser operations
    need(type(raw)is bytes and 0<len(raw)<=1048576)
    d=decode(raw);need(type(d)is dict and set(d)=={'native_tuple','manifest','origin_review','nonce','owner','native_measurement'})
    validate_native_measurement(d['native_measurement'],d['native_tuple'])
    for module,key in [(switch,'switch_sha256'),(baseline_flow,'baseline_flow_sha256'),
                       (legacy_consumer,'legacy_consumer_sha256'),(provider,'normal_TLS_provider_sha256')]:module_pin(module,pins[key])
    need(type(d['native_tuple'])is dict and d['native_tuple'].get('source_head')==pins['source_revision'])
    need(type(d['nonce'])is str and re.fullmatch('[a-f0-9]{24}',d['nonce'])is not None and type(d['owner'])is str and re.fullmatch('[a-f0-9]{64}',d['owner'])is not None)
    need(type(d['origin_review'])is str)
    profile=provider.origins.approve(d['origin_review'].encode(),d['manifest'].get('remote_origin_profile'))
    # Existing provider runs real normal-system trust and both CDP negatives.
    with provider.open_owned_context(profile,d['native_tuple'],nonce=d['nonce'],owner=d['owner'])as context:
        result=switch.run(context,d['manifest'],baseline_flow,approved_profile=profile)
    baseline=result.pop('baseline_flow')
    envelope=dict(scope=SCOPE,passed=True,baseline=baseline,account_switch=result,source_pins=pins,
        normal_TLS_negatives_observed=True,context_browser_driver_closed=True,
        global_trust_changed=False,public_route_authorized=False,deployment=False,native_measurement=d['native_measurement'])
    return consume(envelope,legacy_consumer)


def execute_bound_guest(raw):
    """Fixed remote guest dispatch; no caller-selected provider or flow."""
    source_review() # before input/dependency imports or browser I/O
    need(type(raw)is bytes and 0<len(raw)<=1048576)
    data=decode(raw);need(type(data)is dict and 'native_measurement'in data and 'native_tuple'in data)
    validate_native_measurement(data['native_measurement'],data['native_tuple'])
    from . import normal_tls_provider, flow, media_browser_legacy_consumer
    return execute_guest(raw,provider=normal_tls_provider,baseline_flow=flow,
                         legacy_consumer=media_browser_legacy_consumer)


def validate_public(v):
    pins=source_review();n=native_expectation()
    need(type(v)is dict and set(v)=={'scope','passed','baseline_check_count','account_switch_checks','account_switch_check_count','source_pins','normal_TLS_negatives_observed','context_browser_driver_closed','cookie_injection','SQL_process_cleanup_proved','public_route_authorized','deployment','native_source_verified','actual_fixture_sha256','full_source_manifest_sha256','native_tuple_sha256'})
    need(v['scope']==SCOPE and v['passed']is True and type(v['baseline_check_count'])is int and v['baseline_check_count']==10 and type(v['account_switch_check_count'])is int and v['account_switch_check_count']==6 and type(v['account_switch_checks'])is list and tuple(v['account_switch_checks'])==switch.CHECKS)
    need(v['source_pins']==pins and v['native_source_verified']is True and v['normal_TLS_negatives_observed']is True and v['context_browser_driver_closed']is True)
    need(all(v[k]is False for k in ('cookie_injection','SQL_process_cleanup_proved','public_route_authorized','deployment')))
    need(v['actual_fixture_sha256']==pins['fixture_sha256']and v['full_source_manifest_sha256']==n['manifest_sha256']and v['native_tuple_sha256']==hashlib.sha256(json.dumps(n['native_tuple'],sort_keys=True,separators=(',',':')).encode()).hexdigest())
    return v

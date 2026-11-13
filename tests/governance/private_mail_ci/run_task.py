#!/usr/bin/env python3
"""Owned standalone Gorge worker -> native Task -> authenticated SMTP capture.

Run only after committed source and fixture profile admission. No execution is
performed by import, and no deployment/container APIs are used. Output is bounded
and sanitized. Raw captures stay inside the owned private runtime directory.
"""
import argparse
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import time
from urllib.parse import quote
from runtime import OwnedRuntime, digest, load_config, private_file, validate_config, loopback_request
from run_pending import preflight, wait_until
from task_source import PARENT_FILES, HISTORY_SOURCE_FILES, validate_manifest, load_manifest, validate_parent_modules, pure_source_files, declared_files, admitted_source, operation_module

TASK_FILES = PARENT_FILES | HISTORY_SOURCE_FILES | frozenset({
    'support/governance/notification/task-wire-producer.php',
    'support/governance/notification/task-wire-protocol.php',
    'support/governance/notification/task-wire-hold.php',
    'support/governance/notification/task-wire-router.php',
    'support/governance/notification/task-wire-acceptance.py',
    'support/governance/notification/calendar-wire-producer.php',
    'support/governance/notification/calendar-wire-hold.php',
    'support/governance/notification/business-queue-config.php',
    'support/governance/completion/pipeline-gorge.php',
    'tests/governance/private_mail_ci/run_task.py',
})
BROWSER_FILES = frozenset({'tests/governance/private_mail_ci/task_browser.py',
    'support/governance/notification/task-wire-browser.php',
    'support/governance/notification/notice-history-browser.php'})
CASES = ('allow', 'revoke-before-publication', 'revoke-after-prepare')


def validate_task_source(config, browser_hold=False):
    if type(browser_hold) is not bool:raise ValueError('task_browser_mode_refused')
    validate_config(config)
    required = TASK_FILES | BROWSER_FILES if browser_hold else TASK_FILES
    if not required <= set(config['source_sha256']):
        raise ValueError('task_source_closure_missing')
    status=subprocess.check_output(['git','-C',config['source_root'],'status','--porcelain','--untracked-files=all'],stderr=subprocess.PIPE)
    if status: raise ValueError('task_clean_complete_source_required')
    required=declared_files(Path(config['source_root']),TASK_FILES,BROWSER_FILES if browser_hold else frozenset())
    if not required <= set(config['source_sha256']):raise ValueError('task_pure_root_source_closure_missing')
    # Each file must be tracked at the actual recipe revision, not an overlay.
    for name in required:
        raw = subprocess.check_output(['git','-C',config['source_root'],'show',config['source_revision']+':'+name],stderr=subprocess.PIPE)
        import hashlib
        if hashlib.sha256(raw).hexdigest() != config['source_sha256'][name]:
            raise ValueError('task_committed_source_mismatch')


def validate_worker(value, config):
    if type(value) is not dict or set(value) != {'path','sha256','source_revision'}:
        raise ValueError('worker_manifest_shape')
    p=Path(value['path'])
    if not p.is_absolute() or p.is_symlink() or not p.is_file() or not os.access(p,os.X_OK) or not re.fullmatch('[a-f0-9]{64}',value['sha256']) or digest(p)!=value['sha256'] or value['source_revision']!=config['gorge_revision']:
        raise ValueError('worker_binary_revision_mismatch')
    return value


def worker_environment(ready, token, mailer_origin, policy_path, worker_port, classes, relay=False):
    if classes not in (('PhabricatorApplicationTransactionPublishWorker',),('GorgeMailDeliveryWorker',),('GorgeMailSubmitWorker',)):
        raise ValueError('task_worker_class_profile')
    env={'GORGE_LISTEN_ADDR':'127.0.0.1:'+str(worker_port),
         'GORGE_WORKER_TASK_QUEUE_URL':ready['queue_uri'],'GORGE_WORKER_TASK_QUEUE_TOKEN':ready['queue_token'],
         'GORGE_WORKER_CONDUIT_URL':ready['callback_origin'],'GORGE_WORKER_CONDUIT_TOKEN':token,
         'GORGE_WORKER_MAX_WORKERS':'1','GORGE_WORKER_LEASE_LIMIT':'1','GORGE_WORKER_POLL_INTERVAL_MS':'100',
         'GORGE_WORKER_DRAIN_TIMEOUT_SEC':'10','GORGE_WORKER_TASK_CLASS_FILTER':','.join(classes),
         'GORGE_WORKER_MAILER_URL':mailer_origin,'GORGE_WORKER_MAILER_TOKEN':ready['mailer_token'],
         'GORGE_WORKER_FEED_POLICY_FILE':str(policy_path)}
    if relay:
        db=ready['relay_database']
        env['GORGE_WORKER_MAIL_OUTBOX_DSN']=db['user']+':'+db['password']+'@tcp(127.0.0.1:'+str(db['port'])+')/'+db['database']+'?parseTime=true'
    return env


def browser_cleanup_admission(runtime, config):
    """Existing keeper-owned queue stop, then independent exact-writer SQL0.

    No cleanup permit follows a missing/unknown ACK or observer failure.
    """
    runtime.assert_owned()
    runtime.write('history-queue-quiesce',runtime.owner.encode())
    wait_until(lambda:(runtime.directory/'history-queue-quiesced.json').is_file(),seconds=15)
    ack=runtime.read_json('history-queue-quiesced.json');proof=ack.get('queue_cleanup',{})
    if (ack.get('scope')!='owned-notice-history-queue-quiescence' or ack.get('owner')!=runtime.owner or
        ack.get('source_revision')!=config['source_revision'] or ack.get('independent_SQL_zero_proved') is not False or
        proof.get('kind')!='taskqueue' or proof.get('binary_sha256')!=config['binaries']['taskqueue']['sha256'] or
        proof.get('process_stopped') is not True or proof.get('private_storage_removed') is not True):
        raise RuntimeError('task_browser_queue_quiescence_refused')
    router=runtime.read_json('router.json');writer=router.get('writer',{})
    if router.get('owner')!=runtime.owner or not re.fullmatch('gov_c_writer_[a-f0-9]{12}',writer.get('user','')):
        raise RuntimeError('task_browser_writer_binding_refused')
    program="""$p=json_decode(file_get_contents('php://stdin'),true,8,JSON_THROW_ON_ERROR);$d=$p['database'];
$c=new mysqli('127.0.0.1',$d['user'],$p['password'],'governance_fixture_bootstrap',$d['port']);
$s=$c->prepare('SELECT COUNT(*) n FROM information_schema.PROCESSLIST WHERE USER=?');$u=$p['writer'];$s->bind_param('s',$u);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();$c->close();
if(!is_array($r)||!in_array($r['n'],array(0,'0'),true)){throw new RuntimeException('task_browser_writer_SQL_not_zero');}echo '{"writer_sessions":0}';"""
    observed=json.loads(runtime.php(program,{'database':config['database'],'writer':writer['user'],'password':os.environ[config['database']['password_env']]}))
    if observed!={'writer_sessions':0}:raise RuntimeError('task_browser_writer_SQL_unknown')
    return True


def run_task(config, worker, case, *, browser_hold=False, browser_ready_output=None, browser_seconds=300, source_manifest=None):
    # Full provenance and prepared-role entry/exit surround all owned resources.
    validate_task_source(config,browser_hold)
    validate_worker(worker,config)
    validate_manifest(source_manifest,config,declared_files(Path(config['source_root']),TASK_FILES,BROWSER_FILES if browser_hold else frozenset()))
    result=None;owned=None;pending=False
    try:
        with admitted_source(config) as operation:
            result,owned,pending=_run_task_with_source_operation(config,worker,case,browser_hold=browser_hold,browser_ready_output=browser_ready_output,browser_seconds=browser_seconds,source_manifest=source_manifest,_source_operation=operation)
    except Exception:
        if result is None:raise ValueError('task_source_admission_refused')from None
        result['failure']={'class':'SourceAdmissionExitRefused','phase':'source-admission-exit'}
        if browser_hold:result['recovery_required']=True
        return result
    result['source_exit_proved']=True
    if pending:
        try:
            final=owned.remove_closed_directory()
            result['cleanup'].update(final)
            if final!={'live_children':0,'private_directory_removed':True}:raise ValueError('task_final_removal_refused')
            result.update(passed=True,actual_queue_worker_proved=True,SMTP_case_proved=True)
        except Exception:
            result['failure']={'class':'OwnedFinalRemovalRefused','phase':'owned-final-removal'}
            if browser_hold:result['recovery_required']=True
    return result


def _run_task_with_source_operation(config, worker, case, *, browser_hold=False, browser_ready_output=None, browser_seconds=300, source_manifest=None, _source_operation=None):
    module=operation_module(config)
    module.require_operation(_source_operation,Path(config['source_root']))
    if case not in CASES: raise ValueError('task_case_refused')
    if type(browser_hold) is not bool or (browser_hold and (browser_ready_output is None or type(browser_seconds) is not int or not 120<=browser_seconds<=600)):
        raise ValueError('task_browser_hold_profile_refused')
    if not browser_hold and (browser_ready_output is not None or browser_seconds!=300):raise ValueError('task_browser_options_without_mode')
    # Validate all admission before creating even a private runtime directory.
    validate_task_source(config,browser_hold);validate_worker(worker,config);preflight(config)
    validate_manifest(source_manifest,config,declared_files(Path(config['source_root']),TASK_FILES,BROWSER_FILES if browser_hold else frozenset()))
    if browser_hold:__import__('task_browser')
    validate_parent_modules(config,browser_hold)
    runtime=OwnedRuntime(config);source=Path(config['source_root']);directory=runtime.directory
    php=config['php'];binary={key:value['path'] for key,value in config['binaries'].items()}
    http,mailer_port,worker_port,_=runtime.ports();mailer_token=os.urandom(32).hex();callback_token=os.urandom(32).hex()
    fixture=None;prepared=False;passed=False;native_clean=False;cleanup=None;failure=None;checks=[];sequence=0
    ready=None;last=None;mime_digest=None
    browser_attempted=False;browser_state='disabled';browser_cleanup_admitted=False;consumer_stop_proved=True;browser_hold_identity=None
    def check(value,name):
        if not value or name in checks: raise RuntimeError('task_'+name)
        checks.append(name)
    def request(url,method='GET',payload=None,headers=None): return loopback_request(url,method,payload,headers)
    def observe(action='inspect'):
        nonlocal sequence
        sequence+=1
        if sequence>64: raise RuntimeError('task_observation_budget')
        runtime.write('task-command-'+str(sequence)+'.json',json.dumps({'owner':runtime.owner,'sequence':sequence,'action':action}).encode())
        name='task-observation-'+str(sequence)+'.json'
        wait_until(lambda:(directory/name).is_file(),fixture,10)
        value=runtime.read_json(name)
        check(value['owner']==runtime.owner and value['sequence']==sequence and value['publish_task_id']==ready['publish_task_id'] and value['manual_native_publication'] is False,'observation-'+str(sequence))
        return value
    def poll_observation(predicate,seconds=20):
        deadline=time.monotonic()+seconds
        while True:
            value=observe()
            if predicate(value):return value
            if fixture.poll() is not None or time.monotonic()>deadline:raise RuntimeError('task_native_phase_timeout')
            time.sleep(1)
    def start_worker(classes,relay=False):
        return runtime.spawn([worker['path']],worker_environment(ready,callback_token,origin,directory/'task-policy.json',worker_port,classes,relay),'worker-'+classes[0]+'.log')
    def stop_worker(process):
        runtime._stop_group(process)
        check(process.poll() is not None and not runtime._members(process.pid),'worker-stopped-'+str(runtime.children.index(process)))
    try:
        # Same full pure runtime contract as the existing physical wire parent.
        unit=subprocess.run([php,str(source/'tests/governance/run.php')],cwd=source,env=runtime.env(),capture_output=True,timeout=180)
        runtime.write('pure.json',unit.stdout);pure=json.loads(unit.stdout)
        from pure_inventory import inventory,validate_pure
        check(unit.returncode==0 and validate_pure(pure,inventory(source),config['expected_pure_methods']),'supported-pure-suite')
        db=config['database'];env={'PHORGE_GOVERNANCE_FIXTURE_DSN':'mysql:host=127.0.0.1;port='+str(db['port'])+';dbname=governance_fixture_bootstrap;charset=utf8mb4',
             'PHORGE_GOVERNANCE_FIXTURE_USER':db['user'],'PHORGE_GOVERNANCE_FIXTURE_PASSWORD':os.environ[db['password_env']],
             'PHORGE_GOVERNANCE_PRIVATE_MAIL_HTTP_PORT':str(http),'PHORGE_GOVERNANCE_PRIVATE_MAIL_MAILER_PORT':str(mailer_port),
             'PHORGE_GOVERNANCE_PRIVATE_MAIL_MAILER_TOKEN':mailer_token,'PHORGE_GOVERNANCE_PRIVATE_MAIL_CALLBACK_TOKEN':callback_token,
             'PHORGE_GOVERNANCE_PRIVATE_MAIL_HOLD_SECONDS':'1800' if browser_hold else '600'}
        if browser_hold:env['PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION']=config['source_revision']
        for role,key in (('file','GORGE_FILE'),('taskqueue','GORGE_TASKQUEUE'),('owlmail','OWLMAIL')):
            env['PHORGE_GOVERNANCE_'+key+'_BINARY']=binary[role];env['PHORGE_GOVERNANCE_'+key+'_SHA256']=config['binaries'][role]['sha256']
        fixture=runtime.spawn([php,str(source/'support/governance/native-organization/test-native-organization-mysql.php'),'--task-mail-browser-wire-hold' if browser_hold else '--task-mail-wire-hold'],env,'fixture.log')
        wait_until(lambda:(directory/'ready.json').is_file(),fixture,240);ready=runtime.read_json('ready.json')
        check(ready['owner']==runtime.owner and ready['scope']=='owned-native-queued-task-mail' and ready['manual_native_publication'] is False,'owned-queued-fixture')
        prepared=True;ready['mailer_token']=mailer_token
        server=runtime.spawn([php,'-S','127.0.0.1:'+str(http),str(source/'support/governance/notification/task-wire-router.php')],
            {'PHORGE_GOVERNANCE_TASK_BROWSER_MODE':'1','PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION':config['source_revision']} if browser_hold else None,log='http.log')
        db=ready['mailer_database'];dsn=db['user']+':'+db['password']+'@tcp(127.0.0.1:'+str(db['port'])+')/'+db['database']+'?parseTime=true'
        origin='http://127.0.0.1:'+str(mailer_port)
        mailer=runtime.spawn([binary['mailer']],{'GORGE_LISTEN_ADDR':'127.0.0.1:'+str(mailer_port),'GORGE_SERVICE_TOKEN':mailer_token,
            'GORGE_MAILER_CONFIG':json.dumps([{'key':'capture','type':'smtp','options':{'host':'127.0.0.1','port':str(ready['smtp_port']),'protocol':''}}]),
            'GORGE_MAILER_DELIVERY_DSN':dsn,'GORGE_MAILER_AUTHORIZATION_CONDUIT_URI':ready['callback_origin'],'GORGE_MAILER_AUTHORIZATION_CONDUIT_TOKEN':callback_token},'mailer.log')
        def mailer_ready():
            try:return request(origin+'/readyz')[0]==200
            except OSError:return False
        wait_until(mailer_ready,mailer,20)
        headers={'X-Service-Token':mailer_token,'Content-Type':'application/json'}
        status,raw=request(origin+'/api/mailer/delivery-capabilities',headers=headers)
        check(status==200 and json.loads(raw)['data']['perPhysicalAttemptAuthorization'] is True,'physical-callback-capability')
        capture_headers={'Authorization':ready['capture_authorization']}
        def captured():
            status,raw=request(ready['capture_uri']+'/api/v1/emails?limit=100',headers=capture_headers)
            check(status==200,'capture-read-'+str(len(checks)));return json.loads(raw)
        check(request(ready['capture_uri']+'/api/v1/emails?limit=1')[0]==401,'anonymous-capture-denied')
        check(captured()['total']==0,'empty-sink')
        runtime.write('task-policy.json',b'{"silent":false,"uris":[]}')
        if case=='revoke-before-publication':check(observe('revoke')['revoked'] is True,'native-pre-publication-revoke')
        publication=start_worker(('PhabricatorApplicationTransactionPublishWorker',))
        last=poll_observation(lambda v:v['publish_archive_result']==0)
        stop_worker(publication)
        check(last['publish_task_id']==ready['publish_task_id'],'same-publication-task-archived')
        if case=='revoke-before-publication':
            check(last['mails']==[] and captured()['total']==0,'revoked-before-publication-zero-native-recipient-mail')
        else:
            check(len(last['mails'])==1,'unique-native-recipient-mail')
            delivery=start_worker(('GorgeMailDeliveryWorker',),relay=True)
            last=poll_observation(lambda v:len(v['mails'])==1 and isinstance(v['mails'][0]['snapshot'],dict) and v['mails'][0]['worker_lineage']['prepare_archive_success'] is True and type(v['mails'][0]['worker_lineage']['submit_task_id']) is int)
            stop_worker(delivery)
            record=last['mails'][0];snapshot=record['snapshot'];binding=record['binding']
            check(binding['task_phid']==ready['task_phid'] and binding['recipient_phid']==ready['recipient_phid'] and binding['task_path']==ready['task_path'] and record['outbox_queue_task_id'] is not None,'native-producer-outbox-prepare-binding')
            if case=='revoke-after-prepare':check(observe('revoke')['revoked'] is True,'native-post-prepare-revoke')
            submit=start_worker(('GorgeMailSubmitWorker',))
            def terminal():
                status,raw=request(origin+'/api/mailer/delivery?deliveryID='+quote(snapshot['deliveryID'],safe=''),headers=headers)
                if status!=200:return None
                value=json.loads(raw)['data'];return value if value.get('state') in ('accepted','failed','unknown') else None
            outcome=wait_until(terminal,submit,30)
            last=poll_observation(lambda v:len(v['mails'])==1 and v['mails'][0]['worker_lineage']['submit_archive_success'] is True)
            stop_worker(submit)
            check(outcome['state']==('accepted' if case=='allow' else 'failed'),'physical-terminal-state')
            received=captured();check(received['total']==(1 if case=='allow' else 0),'physical-sink-count')
            if case=='allow':
                mail_id=received['emails'][0]['id']
                status,raw=request(ready['capture_uri']+'/api/v1/emails/'+quote(mail_id,safe='')+'/raw',headers=capture_headers)
                check(status==200,'authenticated-raw-mime');runtime.write('task-raw.mime',raw)
                spec=importlib.util.spec_from_file_location('task_wire_acceptance',source/'support/governance/notification/task-wire-acceptance.py');validator=importlib.util.module_from_spec(spec);spec.loader.exec_module(validator)
                content=validator.validate_mime(raw,recipient=ready['recipient_address'],origin=ready['callback_origin'],task_phid=ready['task_phid'],task_id=int(ready['task_path'][2:]),void_address=ready['void_address'],forbidden=(b'SYNTHETIC_TASK_PRIVATE_TITLE',b'SYNTHETIC_TASK_PRIVATE_COMMENT',b'SYNTHETIC_TASK_ATTACHMENT_BYTES',b'SYNTHETIC_TASK_ATTACHMENT_NAME'))
                mime_digest=content['mime_sha256']
                status,raw=request(origin+'/api/mailer/execute','POST',{'deliveryID':snapshot['deliveryID'],'allowSend':True},headers)
                check(status==200 and json.loads(raw)['data']['state']=='accepted' and captured()['total']==1,'acknowledged-duplicate-no-second-mime')
        history=last.get('history',{})
        retained=case!='revoke-before-publication'
        check(history.get('retained') is retained and type(history.get('current_visible')) is bool and
              ((retained and type(history.get('key')) is str and re.fullmatch('[1-9][0-9]{0,19}',history['key']) is not None) or
               (not retained and history.get('key') is None)),'owned-native-notification-history')
        check(history['current_visible'] is (case=='allow'),'history-current-recipient-policy')
        if browser_hold:
            # Keep only the held fixture and bounded native application server.
            for child in list(runtime.children):
                if child not in (fixture,server):
                    runtime._stop_group(child)
                    check(child.poll() is not None and not runtime._members(child.pid),'browser-consumer-stopped-'+str(runtime.children.index(child)))
            browser_attempted=True
            check(observe('browser-prepare').get('browser_prepared') is True,'native-browser-prepared')
            from task_browser import BrowserHold
            browser=runtime.read_json('task-browser-native.json')
            check(browser.get('notification_key')==history['key'],'browser-exact-owned-notification-key')
            hold=BrowserHold(runtime,ready,browser,config['source_revision'],browser_ready_output,browser_seconds)
            browser_hold_identity=hold.hold_id
            browser_state=hold.wait(fixture,server)
            if browser_state!='finalize-requested':raise RuntimeError('task_browser_hold_timed_out')
        validate_task_source(config,browser_hold);validate_manifest(source_manifest,config,declared_files(Path(config['source_root']),TASK_FILES,BROWSER_FILES if browser_hold else frozenset()));validate_parent_modules(config,browser_hold);validate_worker(worker,config);preflight(config);passed=True
    except Exception as error:
        # No raw HTTP, SQL, filenames, PHIDs, address, exception text or secrets.
        failure={'class':type(error).__name__,'stage':str(error) if isinstance(error,RuntimeError) and re.fullmatch('[a-z0-9_-]{1,128}',str(error)) else 'bounded-task-wire-failure'}
    finally:
        # Stop all consumers/HTTP/mailers before the fixture removes SQL schemas.
        for child in reversed(runtime.children):
            if child is not fixture:
                try:
                    runtime._stop_group(child)
                    if browser_hold and (child.poll() is None or runtime._members(child.pid)):consumer_stop_proved=False
                except Exception:passed=False;consumer_stop_proved=False
        if fixture is not None and fixture.poll() is None:
            try:
                if browser_hold:
                    if not consumer_stop_proved:raise RuntimeError('task_browser_consumers_unknown')
                    browser_cleanup_admitted=browser_cleanup_admission(runtime,config)
                runtime.write('release',runtime.owner.encode());fixture.wait(timeout=90)
            except Exception:passed=False
        if fixture is not None and fixture.poll() is not None:
            try:
                native=json.loads(private_file(directory/'fixture.log',4*1024*1024).read_text())
                services=native['native']['owned_service_cleanup']
                native_clean=(fixture.returncode==0 and native['passed'] is True and native['cleanup_failures']==[] and
                    native['cleanup']=={'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':4,'readerRemoved':True} and
                    set(services)=={'queue','capture'} and all(v.get('process_stopped') is True and v.get('private_storage_removed') is True for v in services.values()) and
                    native['file_service_cleanup'].get('process_stopped') is True and native['file_service_cleanup'].get('private_storage_removed') is True)
            except Exception:native_clean=False
        passed=passed and native_clean
        try:cleanup=runtime.close(remove=False)
        except Exception as error:cleanup={'verified':False,'failure_class':type(error).__name__,'private_directory_removed':False};passed=False
        passed=passed and cleanup.get('live_children')==0 and cleanup.get('private_directory_removed') is False
    result={'schema':1,'scenario':'queued-native-task-smtp','case':case,'source_exit_proved':False,'passed':False,'checks':checks,
            'check_count':len(checks),'source_revision':config['source_revision'],'gorge_revision':config['gorge_revision'],
            'worker_sha256':worker['sha256'],'mime_sha256':mime_digest,'failure':failure,'cleanup':cleanup,
            'native_cleanup_proved':native_clean,'manual_native_publication':False,'actual_queue_worker_proved':False,
            'SMTP_case_proved':False,'browser_proved':False,'N09_media_boundary_proved':False,'deployment':False}
    if browser_hold:result.update({'browser_hold_state':browser_state,'browser_hold_id':browser_hold_identity,'browser_hold_cleanup_admitted':browser_cleanup_admitted,
        'recovery_required':not(consumer_stop_proved and native_clean and cleanup and cleanup.get('live_children')==0),
        'parent_crash_cleanup_not_proved':True})
    return result,runtime,passed and prepared


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--task-source-manifest',required=True);parser.add_argument('--config',required=True);parser.add_argument('--worker-manifest',required=True)
    parser.add_argument('--case',choices=CASES);parser.add_argument('--output',required=True)
    parser.add_argument('--browser-hold',action='store_true');parser.add_argument('--browser-ready-output')
    parser.add_argument('--browser-seconds',type=int,default=300)
    parser.add_argument('--finalize-browser');parser.add_argument('--owner-file')
    a=parser.parse_args();config=load_config(a.config);worker=validate_worker(json.loads(private_file(a.worker_manifest).read_text()),config)
    if a.finalize_browser:
        if a.case or a.browser_hold or not a.owner_file:parser.error('finalize requires owner-file and no case/browser-hold')
        validate_task_source(config,True);validate_manifest(load_manifest(a.task_source_manifest),config,declared_files(Path(config['source_root']),TASK_FILES,BROWSER_FILES));__import__('task_browser');validate_parent_modules(config,True);preflight(config)
        from task_browser import request_finalize
        with admitted_source(config):
            value=request_finalize(a.finalize_browser,a.owner_file,config['source_revision'])
    else:
        if not a.case:parser.error('--case is required')
        value=run_task(config,worker,a.case,browser_hold=a.browser_hold,browser_ready_output=a.browser_ready_output,browser_seconds=a.browser_seconds,source_manifest=load_manifest(a.task_source_manifest))
    fd=os.open(a.output,os.O_CREAT|os.O_EXCL|os.O_WRONLY,0o600)
    with os.fdopen(fd,'w')as stream:json.dump(value,stream,indent=2);stream.write('\n')
    return 0 if value.get('passed') is True or value.get('state')=='finalize_requested' else 1

if __name__=='__main__':raise SystemExit(main())

#!/usr/bin/env python3
"""Real Go intake/replicas -> HTTP -> real PHP business receipt -> kill/restore/replay."""
import base64
import concurrent.futures
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import urllib.request

HERE = Path(__file__).resolve().parent
GORGE = Path(os.environ['GORGE_TEST_GORGE_DIR'])
ENV = dict(os.environ)
DB = HERE / 'recovery_database.php'


def database(action, *args, sql=None):
    out = subprocess.check_output(['php', str(DB), action, *map(str,args)],
                                  input=sql.encode() if sql else None, env=ENV)
    return json.loads(out) if out else None


def port():
    with socket.socket() as s:
        s.bind(('127.0.0.1',0)); return s.getsockname()[1]


def request(url, path, body=None):
    req = urllib.request.Request(url+path, None if body is None else json.dumps(body).encode(),
        {'X-Service-Token':'paired-intake-only','Content-Type':'application/json'})
    with urllib.request.urlopen(req,timeout=10) as r:
        return json.load(r).get('data',{})


def wait(predicate, description):
    until = time.monotonic()+30
    while time.monotonic()<until:
        try:
            result = predicate()
            if result: return result
        except (OSError, urllib.error.URLError): pass
        time.sleep(.1)
    raise RuntimeError('Timeout: '+description)


with tempfile.TemporaryDirectory(prefix='gorge-paired-recovery-') as directory:
    work=Path(directory); processes=[]; logs=[]; initialized=False
    settings={'mysql.host':'127.0.0.1','mysql.port':ENV['GORGE_TEST_MYSQL_PORT'],
              'mysql.user':'root','mysql.pass':ENV['GORGE_TEST_MYSQL_PASSWORD'],
              'cluster.databases':[],'cluster.instance':None,'storage.default-namespace':'gorge_paired_test',
              'gorge.conduit.token':'paired-source-only','gorge.integrations':{'inbound':['mailgun']}}
    encoded=base64.b64encode(json.dumps(settings).encode()).decode()
    config=work/'paired.conf.php';config.write_text("<?php return json_decode(base64_decode('"+encoded+"'),true);");config.chmod(0o600)
    ENV['PHABRICATOR_ENV']=os.path.relpath(config,HERE.parents[2]/'conf')
    try:
        database('init'); initialized=True
        binary=work/'integrations'
        subprocess.run(['go','build','-o',str(binary),'./cmd/gorge-integrations'],cwd=GORGE/'go',env=ENV,check=True)
        marker=work/'committed'; ENV['GORGE_TEST_RESPONSE_MARKER']=str(marker)
        source_port=port(); urls=[f'http://127.0.0.1:{port()}' for _ in range(2)]
        def start():
            log=(work/f'php-{len(logs)}.log').open('w');logs.append(log)
            processes.append(subprocess.Popen(['php','-S',f'127.0.0.1:{source_port}',str(HERE/'recovery_router.php')],env=ENV,stdout=log,stderr=log))
            wait(lambda: urllib.request.urlopen(f'http://127.0.0.1:{source_port}/readyz',timeout=1),'PHP source')
            for i,url in enumerate(urls):
                config=work/f'config-{i}.json'
                config.write_text(json.dumps({'listen':url.split('//')[1],'token':'paired-intake-only',
                  'dsn':f"root:{ENV['GORGE_TEST_MYSQL_PASSWORD']}@tcp(127.0.0.1:{ENV['GORGE_TEST_MYSQL_PORT']})/gorge_paired_test",
                  'conduitURI':f'http://127.0.0.1:{source_port}','conduitToken':'paired-source-only',
                  'inbound':['mailgun'],'relayWorkers':1,'targets':{}}));config.chmod(0o600)
                log=(work/f'go-{len(logs)}.log').open('w');logs.append(log)
                processes.append(subprocess.Popen([str(binary)],env=dict(ENV,GORGE_INTEGRATIONS_CONFIG_FILE=str(config)),stdout=log,stderr=log))
                wait(lambda: request(url,'/readyz') is not None,'Go replica')
        def kill():
            for p in processes:
                if p.poll() is None:p.kill()
                p.wait(timeout=10)
            processes.clear()
        start()
        intake={'provider':'mailgun','fields':{'message-headers':json.dumps([
          ['from','sender@example.test'],['to','receiver@example.test'],['message-id','<paired-restore>'],
          ['x-phabricator-sent-this-message','yes']]),'recipient':'receiver@example.test','from':'sender@example.test','stripped-text':'paired restore fixture'}}
        with concurrent.futures.ThreadPoolExecutor(max_workers=8) as executor:
            receipts=list(executor.map(lambda i:request(urls[i%2],'/api/integrations/inbound',intake),range(8)))
        ids={r['id'] for r in receipts}
        if len(ids)!=1:raise RuntimeError('Replicas assigned different identities')
        event=ids.pop();wait(marker.exists,'actual PHP business commit')
        kill()  # Neither process has delivered the business response to Go.
        count=database('sql','gorge_paired_test_metamta',sql='SELECT COUNT(*) AS n FROM metamta_receivedmail')[0]['n']
        if int(count)!=1:raise RuntimeError('Duplicate business mail before restore')
        database('backup',work/'backup.json')
        database('restore',work/'backup.json')  # Both SQL stores restored at one quiesced point.
        database('sql','gorge_paired_test',sql='UPDATE gorge_integration_inbox SET due=0')
        start()
        wait(lambda: database('sql','gorge_paired_test',sql='SELECT state FROM gorge_integration_inbox')[0]['state']=='done','restored source receipt replay')
        for url in urls:
            if request(url,'/api/integrations/inbound',intake)['id']!=event:raise RuntimeError('Restore changed identity')
        # Unknown resolution traverses the real Go -> PHP reconciliation path.
        database('sql','gorge_paired_test_metamta',sql="UPDATE metamta_gorgeinboundreceipt SET state='processing',dateModified=1")
        database('sql','gorge_paired_test',sql="UPDATE gorge_integration_inbox SET state='unknown'")
        digest=database('sql','gorge_paired_test',sql='SELECT digest FROM gorge_integration_inbox')[0]['digest']
        request(urls[0],'/api/integrations/resolve',{'domain':'inbound','id':event,'digest':digest,
           'state':'done','operator':'acceptance-fixture','evidence':'Verified the single committed PHP business receipt after restore'})
        kill()
        database('backup',work/'resolved.json');database('restore',work/'resolved.json')
        start()
        request(urls[1],'/api/integrations/inbound',intake)
        count=database('sql','gorge_paired_test_metamta',sql='SELECT COUNT(*) AS n FROM metamta_receivedmail')[0]['n']
        audit=database('sql','gorge_paired_test',sql='SELECT COUNT(*) AS n FROM gorge_integration_resolution')[0]['n']
        if int(count)!=1 or int(audit)!=1:raise RuntimeError('Restore lost business or resolution deduplication')
        print('Real paired replicas, SIGKILL after PHP commit, SQL restore, late replay and reconciliation passed.')
    except Exception:
        for path in work.glob('*.log'):
            print(path.name+':\n'+path.read_text()[-3000:])
        raise
    finally:
        for p in processes:
            if p.poll() is None:p.kill()
            p.wait(timeout=10)
        for log in logs:log.close()
        if initialized:database('drop')

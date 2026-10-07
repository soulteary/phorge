#!/usr/bin/env python3
"""Container entry point. All writes and backend fixtures are disposable."""
import json
import os
from pathlib import Path
import subprocess
import sys
import time
import urllib.request

ROOT = Path('/work/phorge')
sys.path.insert(0, str(ROOT / 'tests/contract/worker'))
from acceptance_manifest import source_identity
result = {'schemaVersion': 1, 'result': 'running', 'stages': [],
          'phorge': source_identity(ROOT),
          'gorge': source_identity(Path('/work/gorge')),
          'arcanist': source_identity(Path('/opt/phorge/arcanist')),
          'buildLock': json.loads((ROOT / 'deploy/acceptance/build-lock.json').read_text()),
          'scope': 'paired PHP/Go contracts, real MySQL/S3/image, interruption/recovery fixtures',
          'notCovered': ['authenticated browser journeys', 'external provider delivery', 'production performance', 'real Elasticsearch/Meilisearch backend matrix']}
out = Path('/results/result.json')
def save():
    tmp = out.with_suffix('.tmp')
    tmp.write_text(json.dumps(result, indent=2))
    tmp.replace(out)
def run(name, args, cwd=ROOT):
    stage = {'name': name, 'result': 'running'}
    result['stages'].append(stage); save()
    if args[:2] == ['go', 'test']:
        process = subprocess.Popen(args[:2]+['-json']+args[2:],cwd=cwd,env=os.environ,stdout=subprocess.PIPE,text=True)
        skipped=[]
        with Path('/results/go-tests.jsonl').open('w') as log:
            for line in process.stdout:
                log.write(line)
                try:
                    event=json.loads(line)
                    if event.get('Action')=='skip': skipped.append({'package':event.get('Package'),'test':event.get('Test')})
                    if event.get('Action')=='fail': print(line,flush=True)
                except ValueError: print(line,flush=True)
        rc=process.wait()
        stage['skippedTests']=skipped
    else:
        rc = subprocess.run(args, cwd=cwd, env=os.environ).returncode
    stage.update(result='passed' if rc == 0 else 'failed', exitCode=rc); save()
    if rc: raise RuntimeError(name + ' failed')
try:
    save()
    for url in ['http://127.0.0.1:8190/readyz', 'http://127.0.0.1:9000/minio/health/live']:
        deadline = time.monotonic() + 120
        while True:
            try:
                with urllib.request.urlopen(url, timeout=2) as r:
                    if r.status == 200: break
            except OSError:
                pass
            if time.monotonic() > deadline: raise RuntimeError('Fixture readiness timeout')
            time.sleep(1)
    for script in ['worker/startup.php', 'worker/installation.php', 'worker/execution.php', 'download/runtime.php']:
        run(script, ['php', 'tests/contract/' + script])
    run('paired-acceptance', ['python3', 'tests/contract/worker/acceptance.py'])
    os.environ.update(PHORGE_FORK_DIR='/work/phorge',
        GORGE_TEST_MYSQL_DSN='root:acceptance-only@tcp(127.0.0.1:3306)/',
        GORGE_TEST_REDIS_ADDR='127.0.0.1:6379',
        GORGE_TEST_FILE_LIFECYCLE_DSN='root:acceptance-only@tcp(127.0.0.1:3306)/gorge_lifecycle_test',
        GORGE_TEST_INTEGRATIONS_DSN='root:acceptance-only@tcp(127.0.0.1:3306)/gorge_integrations_test',
        GORGE_TEST_SEARCH_MYSQL_DSN='root:acceptance-only@tcp(127.0.0.1:3306)/')
    run('create-test-schemas', ['php', '-r', '$db=new mysqli("127.0.0.1","root","acceptance-only","",3306);foreach(["gorge_lifecycle_test","gorge_integrations_test"] as $n){$db->query("CREATE DATABASE ".$n);}'])
    run('go-unit-contracts' , ['go', 'test', '-count=1', './...'], Path('/work/gorge/go'))
    result['result'] = 'passed'
except Exception as ex:
    result['result'] = 'failed'
    result['failure'] = str(ex)  # Stage names only; never exception bodies from services.
finally:
    save()
sys.exit(0 if result['result'] == 'passed' else 1)

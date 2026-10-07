#!/usr/bin/env python3
"""Container entry point. All writes and backend fixtures are disposable."""
import json
import os
from pathlib import Path
import subprocess
import sys
import time
import urllib.request

SOURCE_ROOT = Path('/work/phorge')
ROOT = Path('/tmp/acceptance-phorge')
sys.path.insert(0, str(SOURCE_ROOT / 'tests/contract/worker'))
from acceptance_helpers import prepare_phorge_snapshot
from acceptance_manifest import runtime_identity, source_identity
result = {'schemaVersion': 2, 'result': 'running', 'stages': [],
          'phorge': source_identity(SOURCE_ROOT),
          'gorge': source_identity(Path('/work/gorge')),
          'buildLock': json.loads((SOURCE_ROOT / 'deploy/acceptance/build-lock.json').read_text()),
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
    result['executionSnapshot'] = prepare_phorge_snapshot(SOURCE_ROOT, Path('/opt/phorge/phorge'), ROOT)
    # Preserve the documented sibling layout for cross-repository link checks.
    # The target remains the read-only Gorge source mount.
    (ROOT.parent / 'gorge').symlink_to('/work/gorge', target_is_directory=True)
    os.environ['GORGE_TEST_PHORGE_SOURCE_IDENTITY_DIR'] = str(SOURCE_ROOT)
    result['runtime'] = runtime_identity(ROOT / 'support/runtime/manifest.json')
    save()
    run('bundled-runtime-integrity', ['php', 'scripts/runtime/verify.php'])
    for script in ['compatibility', 'diff', 'integrity', 'maintenance']:
        run('runtime/' + script, ['php', 'tests/contract/runtime/' + script + '.php'])
    for fixture, url in [('image', 'http://127.0.0.1:8190/readyz'),
                         ('s3', 'http://127.0.0.1:9000/otterio/health/ready'),
                         ('render', 'http://127.0.0.1:8140/readyz')]:
        deadline = time.monotonic() + 120
        while True:
            try:
                with urllib.request.urlopen(url, timeout=2) as r:
                    if r.status == 200: break
            except OSError:
                pass
            if time.monotonic() > deadline: raise RuntimeError(fixture + ' fixture readiness timeout')
            time.sleep(1)
    run('runtime/products', ['php', 'tests/contract/runtime/products.php'])
    for script in ['worker/bundled_runtime.php', 'worker/startup.php', 'worker/installation.php', 'worker/execution.php', 'download/runtime.php']:
        run(script, ['php', 'tests/contract/' + script])
    run('paired-acceptance', ['python3', 'tests/contract/worker/acceptance.py'])
    os.environ.update(GORGE_TEST_CLEANUP_EXPORT='/results/cleanup-export.json',
                      GORGE_TEST_SEARCH_SCAN_PAGE='/results/search-source-page.json',
                      GORGE_TEST_SEARCH_EVENT_FILE='/results/search-events.json')
    for script in ['worker/cutover.php', 'worker/startup_http.php',
                   'cleanup/runtime.php', 'feed/outbox.php', 'mail/outbox.php',
                   'search/projection.php', 'search/outbox.php',
                   'search/deletion.php', 'search/source.php',
                   'image/geometry.php', 'image/runtime.php']:
        run(script, ['php', 'tests/contract/' + script])
    os.environ.update(PHORGE_FORK_DIR=str(ROOT),
        GORGE_TEST_MYSQL_DSN='root:acceptance-only@tcp(127.0.0.1:3306)/',
        GORGE_TEST_REDIS_ADDR='127.0.0.1:6379',
        GORGE_TEST_FILE_LIFECYCLE_DSN='root:acceptance-only@tcp(127.0.0.1:3306)/gorge_lifecycle_test',
        GORGE_TEST_INTEGRATIONS_DSN='root:acceptance-only@tcp(127.0.0.1:3306)/gorge_integrations_test',
        GORGE_TEST_SEARCH_MYSQL_DSN='root:acceptance-only@tcp(127.0.0.1:3306)/')
    run('create-test-schemas', ['php', '-r', '$db=new mysqli("127.0.0.1","root","acceptance-only","",3306);foreach(["gorge_lifecycle_test","gorge_integrations_test"] as $n){$db->query("CREATE DATABASE ".$n);}'])
    run('go-unit-contracts' , ['go', 'test', '-count=1', './...'], Path('/work/gorge/go'))
    run('bundled-runtime-integrity-after-contracts', ['php', 'scripts/runtime/verify.php'])
    result['result'] = 'passed'
except Exception as ex:
    result['result'] = 'failed'
    result['failure'] = str(ex)  # Stage names only; never exception bodies from services.
finally:
    save()
sys.exit(0 if result['result'] == 'passed' else 1)

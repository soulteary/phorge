#!/usr/bin/env python3
"""Paired acceptance using an explicitly supplied disposable MySQL server.

Creates only gorge_acceptance_file and random Phorge fixture/replay namespaces. The
Go integration suites separately refuse non-test lifecycle/integration schemas.
No deployment configs, business volumes or application services are modified.
"""
import base64
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import urllib.request
import uuid
import sys
sys.dont_write_bytecode = True
from acceptance_helpers import S3TLS
from acceptance_manifest import runtime_identity, source_identity

if len(sys.argv) == 3 and sys.argv[1] == '--environment':
    raw=Path(sys.argv[2]).read_bytes()
    if len(raw)>65536:raise RuntimeError('Test environment too large')
    values=json.loads(raw)
    if not isinstance(values,dict) or any(not k.startswith('GORGE_TEST_') or not isinstance(v,str) for k,v in values.items()):
        raise RuntimeError('Only explicit GORGE_TEST_ string options are accepted')
    os.environ.update(values)
elif len(sys.argv)!=1:
    raise RuntimeError('Use acceptance.py [--environment restricted-test.json]')

ROOT = Path(__file__).resolve().parents[3]
GORGE = Path(os.environ["GORGE_TEST_GORGE_DIR"]).resolve()
PORT = int(os.environ["GORGE_TEST_MYSQL_PORT"])
PASSWORD = os.environ["GORGE_TEST_MYSQL_PASSWORD"]


def run(args, env, cwd=ROOT):
    subprocess.run(args, env=env, cwd=cwd, check=True)


with tempfile.TemporaryDirectory(prefix="gorge-acceptance-") as directory:
    work = Path(directory)
    source_root = Path(os.environ.get('GORGE_TEST_PHORGE_SOURCE_IDENTITY_DIR', ROOT)).resolve()
    if runtime_identity(source_root / 'support/runtime/manifest.json') != runtime_identity(ROOT / 'support/runtime/manifest.json'):
        raise ValueError('Execution runtime does not match source identity directory')
    manifest={'schemaVersion':2,'phorge':source_identity(source_root),'gorge':source_identity(GORGE),
              'runtime':runtime_identity(ROOT / 'support/runtime/manifest.json'),'result':'running'}
    if source_root != ROOT:
        manifest['executionSnapshot'] = source_identity(ROOT)
    if os.environ.get('GORGE_TEST_ACCEPTANCE_MANIFEST'):
        Path(os.environ['GORGE_TEST_ACCEPTANCE_MANIFEST']).write_text(json.dumps(manifest,indent=2))
    run(['php', str(ROOT / 'scripts/runtime/verify.php')], os.environ)
    config = work / "isolated.conf.php"
    settings = {"mysql.host": "127.0.0.1", "mysql.port": str(PORT),
                "mysql.user": "root", "mysql.pass": PASSWORD,
                "cluster.databases": [], "cluster.instance": None,
                "storage.default-namespace": "gorge_acceptance",
                "phabricator.base-uri": "http://phorge.example.test"}
    encoded = base64.b64encode(json.dumps(settings).encode()).decode()
    config.write_text("<?php return json_decode(base64_decode('" + encoded + "'), true);\n")
    config.chmod(0o600)
    env = dict(os.environ, PHABRICATOR_ENV=os.path.relpath(config, ROOT / "conf"),
               PHORGE_CONTROL_PLANE="legacy", PHORGE_FORK_DIR=str(ROOT),
               GORGE_TEST_INTEGRATIONS_MYSQL_PORT=str(PORT),
               GORGE_TEST_INTEGRATIONS_MYSQL_PASSWORD=PASSWORD)
    # Destructive scope is a dedicated fixed test database, never an existing
    # namespace or a DSN inferred from application configuration.
    init = work / "database.php"
    init.write_text("<?php $db=new mysqli('127.0.0.1','root',getenv('GORGE_TEST_MYSQL_PASSWORD'),'',"
                    "(int)getenv('GORGE_TEST_MYSQL_PORT')); "
                    "$mode=$db->query('SELECT @@GLOBAL.sql_mode AS mode')->fetch_assoc()['mode']; "
                    "if($mode!=='STRICT_ALL_TABLES'){throw new Exception('Disposable MySQL must use STRICT_ALL_TABLES for historical migration replay.');} "
                    "$db->query('CREATE DATABASE gorge_acceptance_file'); "
                    "$db->select_db('gorge_acceptance_file'); "
                    "$db->query('CREATE TABLE file(id INT PRIMARY KEY,storageEngine VARBINARY(32),"
                    "storageHandle VARBINARY(255),KEY target(storageEngine,storageHandle(64))) ENGINE=InnoDB'); "
                    "$sql=file_get_contents($argv[1]); $sql=str_replace(array('{$NAMESPACE}_file.',"
                    "'{$COLLATE_TEXT}'),array('', 'utf8mb4_bin'),$sql); $db->query($sql);")
    drop = work / "drop.php"
    drop.write_text("<?php $db=new mysqli('127.0.0.1','root',getenv('GORGE_TEST_MYSQL_PASSWORD'),'',"
                    "(int)getenv('GORGE_TEST_MYSQL_PORT'));$db->query('DROP DATABASE gorge_acceptance_file');")
    run(["php", str(init), str(ROOT / "resources/sql/autopatches/20261007.file.01.gorgedeletion.sql")], env)
    service = None
    proxy = None
    try:
        # Replay every historical PHP/SQL migration with the bundled library.
        # A dedicated random namespace cannot collide with the file fixtures.
        replay_namespace = 'gorge_runtime_replay_' + uuid.uuid4().hex[:12]
        replay_settings = dict(settings, **{
            'storage.default-namespace': replay_namespace})
        replay_config = work / 'replay.conf.php'
        replay_encoded = base64.b64encode(json.dumps(replay_settings).encode()).decode()
        replay_config.write_text("<?php return json_decode(base64_decode('" + replay_encoded + "'), true);\n")
        replay_config.chmod(0o600)
        replay_env = dict(env, PHABRICATOR_ENV=os.path.relpath(replay_config, ROOT / 'conf'))
        try:
            run(['php', str(ROOT / 'bin/storage'), 'upgrade', '--force',
                 '--namespace', replay_namespace, '--no-quickstart', '--allow-surplus'], replay_env)
        finally:
            run(['php', str(ROOT / 'bin/storage'), 'destroy', '--force',
                 '--namespace', replay_namespace], replay_env)
        if not env.get('GORGE_TEST_S3_URL') or not env.get('GORGE_TEST_IMAGE_URL'):
            raise RuntimeError('Full acceptance requires real S3 and current image service URLs')
        proxy = S3TLS(env['GORGE_TEST_S3_URL'], work)
        env.update(GORGE_TEST_S3_ENDPOINT=proxy.host,GORGE_TEST_S3_CA=str(proxy.cert),
                   GORGE_TEST_S3_BUCKET='gorge-acceptance-'+uuid.uuid4().hex)
        binary = work / "file-service"
        run(["go", "build", "-o", str(binary), "./cmd/gorge-file-storage"], env, GORGE / "go")
        with socket.socket() as listener:
            listener.bind(("127.0.0.1", 0))
            file_port = listener.getsockname()[1]
        service_env = dict(env, GORGE_LISTEN_ADDR=f"127.0.0.1:{file_port}",
                           GORGE_SERVICE_TOKEN="acceptance-file-only",
                           GORGE_FILE_NAMESPACE="gorge_acceptance",
                           GORGE_FILE_LOCAL_DISK_PATH=str(work / "bytes"),
                           GORGE_FILE_UPLOAD_ROOT=str(work / "uploads"),
                           GORGE_FILE_MYSQL_HOST="", GORGE_FILE_S3_BUCKET="",
                           GORGE_FILE_DELETION_DSN=f"root:{PASSWORD}@tcp(127.0.0.1:{PORT})/gorge_acceptance_file")
        with (work / "service.log").open("w") as log:
            service = subprocess.Popen([str(binary)], env=service_env, stdout=log, stderr=log)
        url = f"http://127.0.0.1:{file_port}"
        for _ in range(100):
            if service.poll() is not None:
                raise RuntimeError("File service exited during acceptance startup")
            try:
                with urllib.request.urlopen(url + "/readyz", timeout=1):
                    break
            except OSError:
                time.sleep(0.1)
        else:
            raise RuntimeError("File service readiness timeout")
        env.update(GORGE_TEST_FILE_URL=url, GORGE_TEST_FILE_TOKEN="acceptance-file-only")
        # User-password tests build avatar files through the current write engine.
        # Configure only this disposable service, after its readiness check.
        settings.update({'gorge.file.uri': url,
                         'gorge.file.token': 'acceptance-file-only'})
        encoded = base64.b64encode(json.dumps(settings).encode()).decode()
        config.write_text("<?php return json_decode(base64_decode('" + encoded + "'), true);\n")
        config.chmod(0o600)
        # Preserve the existing module selection and ancestor fixtures.
        run(['php', str(ROOT / 'bin/unit'), '--no-coverage',
             'src/infrastructure/cluster/',
             'src/applications/metamta/storage/__tests__/PhabricatorMetaMTAMailTestCase.php',
             'src/applications/metamta/storage/__tests__/PhabricatorMailUnknownOutcomeTestCase.php',
             'src/applications/search/index/__tests__/PhabricatorSearchRetryTestCase.php',
             'src/applications/auth/__tests__/PhabricatorAuthPasswordTestCase.php'], env)
        for script in ["worker/status.php", "scheduler/runtime.php", "integrations/runtime.php",
                       "integrations/inbound_mysql.php", "worker/mailer_fence.php",
                       "worker/retirement.php", "files/runtime.php"]:
            run(["php", "-d", "curl.cainfo="+str(proxy.cert), str(ROOT / "tests/contract" / script)], env,
                work if script == "files/runtime.php" else ROOT)
        run(["python3", str(ROOT / "tests/contract/integrations/paired_recovery.py")], env)
        run(['php', str(ROOT / 'scripts/runtime/verify.php')], env)
        manifest['result']='passed'
        manifest['requiredBackends']=['mysql','real-s3','current-image','real-php-http','go-replicas']
        if env.get('GORGE_TEST_ACCEPTANCE_MANIFEST'):
            Path(env['GORGE_TEST_ACCEPTANCE_MANIFEST']).write_text(json.dumps(manifest,indent=2))
        print('Complete paired acceptance passed; exact source identities recorded.')
    except Exception:
        manifest['result'] = 'failed'
        if env.get('GORGE_TEST_ACCEPTANCE_MANIFEST'):
            Path(env['GORGE_TEST_ACCEPTANCE_MANIFEST']).write_text(json.dumps(manifest,indent=2))
        raise
    finally:
        if service is not None:
            service.terminate()
            try:
                service.wait(timeout=15)
            except subprocess.TimeoutExpired:
                service.kill()
                service.wait()
        if proxy is not None: proxy.close()
        run(["php", str(drop)], env)

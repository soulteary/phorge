#!/usr/bin/env python3
"""Create and remove only randomly named disposable Compose fixtures."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import time
import uuid
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('ops',ROOT/'scripts/operations/manage.py')
ops=importlib.util.module_from_spec(spec);spec.loader.exec_module(ops)
os.umask(0o077)
project='ops-fixture-'+uuid.uuid4().hex[:10]
image=os.environ.get('GORGE_TEST_MYSQL_IMAGE','mysql:8.0.46')
with tempfile.TemporaryDirectory(prefix='phorge-ops-fixture-') as tmp:
    work=Path(tmp);compose=work/'compose.yml'
    compose.write_text('''name: PROJECT
services:
  mysql:
    image: IMAGE
    environment:
      MYSQL_ROOT_PASSWORD: ops-disposable-only
    volumes:
      - db:/var/lib/mysql
  writer:
    image: IMAGE
    entrypoint: [sh, -c]
    command: ["mkdir -p /fixture/nested; printf 'keep-volume-identity' > /fixture/.gorge-volume-id; printf 'binary-data' > /fixture/nested/data; trap 'exit 0' TERM; while :; do sleep 1; done"]
    volumes:
      - files:/fixture
  phorge:
    image: IMAGE
    entrypoint: [sh, -c]
    command: ["printf '{\\\"mysql.host\\\":\\\"mysql\\\"}' > /opt/phorge/phorge/conf/local/local.json; trap 'exit 0' TERM; while :; do sleep 1; done"]
    volumes:
      - conf:/opt/phorge/phorge/conf/local
volumes:
  db: {}
  files: {}
  conf: {}
'''.replace('PROJECT',project).replace('IMAGE',image))
    base=['docker','compose','-f',str(compose)]
    def call(args):return subprocess.check_output(args,stderr=subprocess.PIPE).decode()
    staged=None
    try:
        call(base+['up','-d'])
        mysql=call(base+['ps','-q','mysql']).strip()
        ops.wait_mysql(mysql)
        ops.sql(mysql,"CREATE DATABASE ops_fixture; CREATE TABLE ops_fixture.receipt(id VARCHAR(64) PRIMARY KEY, digest VARCHAR(64)); INSERT INTO ops_fixture.receipt VALUES ('keep-original-ID','keep-original-digest')")
        web=call(base+['ps','-q','phorge']).strip()
        # An originally stopped Phorge must be readable and remain stopped.
        call(['docker','stop','--time','10',web])
        bundle=work/'backup'
        failed=work/'failed-backup'
        original_command=ops.command
        def fail_dump(command, **kwargs):
            if any('mysqldump' in arg for arg in command):
                raise RuntimeError('Injected SQL dump failure')
            return original_command(command, **kwargs)
        with patch.object(ops,'command',side_effect=fail_dump):
            try:
                ops.backup(type('Args',(),{'exclusive_access':True,'compose':[str(compose)],'directory':str(failed)})())
                raise AssertionError('Injected backup failure was not detected')
            except RuntimeError as error:
                assert 'Injected' in str(error)
        assert json.loads((failed/'manifest.json').read_text())['state']=='incomplete'
        writer=call(base+['ps','-q','writer']).strip()
        assert json.loads(call(['docker','inspect',writer]))[0]['State']['Running']
        assert not json.loads(call(['docker','inspect',web]))[0]['State']['Running']
        args=type('Args',(),{'exclusive_access':True,'compose':[str(compose)],'directory':str(bundle)})()
        ops.backup(args)
        manifest=ops.verify(bundle)
        assert manifest['databases']==['ops_fixture']
        config=json.loads((bundle/'container-config.json').read_text())
        assert len(config)==3 and all('config' in c and 'mounts' in c for c in config)
        assert 'container-config.json' in manifest['files']
        assert 'keep-original-ID' in (bundle/'database.sql').read_text()
        assert manifest['tablesPerDatabase']=={'ops_fixture':1}
        writer=call(base+['ps','-q','writer']).strip()
        assert json.loads(call(['docker','inspect',writer]))[0]['State']['Running']
        with patch.object(ops,'helper_run',side_effect=KeyboardInterrupt):
            try:
                ops.restore_test(type('Args',(),{'directory':str(bundle)})())
                raise AssertionError('Injected interrupt was not detected')
            except KeyboardInterrupt:
                pass
        assert not list(bundle.glob('.phorge-restore-*-resources.json'))
        ops.restore_test(type('Args',(),{'directory':str(bundle)})())
        receipt=json.loads((bundle/'restore-test.json').read_text())
        assert receipt['state']=='restore-tested'
        ops.restore_test(type('Args',(),{'directory':str(bundle),'destination':str(work/'recovery')})())
        staged=json.loads((work/'recovery'/'restore.json').read_text())
        assert staged['network']=='none' and not staged['consumersStarted']
        assert ops.sql(staged['mysqlContainer'],"SELECT digest FROM ops_fixture.receipt WHERE id='keep-original-ID'").strip()=='keep-original-digest'
        assert (work/'recovery'/'credentials.json').stat().st_mode & 0o777 == 0o600
        ops.archive_bundle(bundle,work/'archive')
        ops.verify(work/'archive')
        assert (bundle/'database.sql').exists()
        # Source mutation after backup cannot change the verified immutable copy.
        ops.sql(mysql,"INSERT INTO ops_fixture.receipt VALUES ('after-snapshot','new')")
        assert 'after-snapshot' not in (bundle/'database.sql').read_text()
        print('PASS: stop/snapshot/resume, stopped Phorge config, injected dump failure and interrupt cleanup, SQL+volume restore, archive integrity')
    finally:
        try:
            if staged:
                call(['docker','rm','-f',staged['mysqlContainer']])
                for v in staged['applicationVolumes']:
                    call(['docker','volume','rm',v['restoredVolume']])
                call(['docker','volume','rm',staged['mysqlDataVolume']])
        finally:
            call(base+['down','-v'])

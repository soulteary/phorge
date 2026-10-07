#!/usr/bin/env python3
"""Native snapshot and restore on a randomly named disposable Elasticsearch."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import time
import uuid

ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('snapshot',ROOT/'scripts/operations/search_backup.py')
snapshot=importlib.util.module_from_spec(spec);spec.loader.exec_module(snapshot)
name='gorge-snapshot-fixture-'+uuid.uuid4().hex[:12]
image=os.environ.get('GORGE_TEST_ES_IMAGE','docker.elastic.co/elasticsearch/elasticsearch:8.17.3')
def docker(args):return subprocess.check_output(['docker',*args],text=True).strip()
try:
    docker(['run','-d','--rm','--name',name,'--memory','2g',
            '--publish','127.0.0.1::9200','--tmpfs','/usr/share/elasticsearch/data:mode=1777',
            '--tmpfs','/snapshots:mode=1777','-e','discovery.type=single-node',
            '-e','xpack.security.enabled=false','-e','path.repo=/snapshots',
            '-e','ES_JAVA_OPTS=-Xms512m -Xmx512m',image])
    info=json.loads(docker(['inspect',name]))[0]
    port=info['NetworkSettings']['Ports']['9200/tcp'][0]['HostPort']
    adapter=snapshot.ElasticsearchSnapshot('http://127.0.0.1:'+port,'backup')
    deadline=time.monotonic()+120
    while True:
        try:adapter.request('GET','/');break
        except ValueError:
            if time.monotonic()>deadline:raise
            time.sleep(1)
    adapter.validate_environment({'ES_HOST':adapter.endpoint,'ES_VERSION':'8','ES_INDEX':'phorge_fixture'})
    adapter.request('PUT','/_snapshot/backup',{'type':'fs','settings':{'location':'/snapshots'}})
    adapter.request('PUT','/phorge_fixture',{'settings':{'number_of_shards':1,'number_of_replicas':0}})
    payload={'phid':'PHID-TASK-backup-preserved','title':'snapshot preserves source identity'}
    adapter.request('PUT','/phorge_fixture/_doc/PHID-TASK-backup-preserved?refresh=true',payload)
    # The full SQL/volume interruption fixture also exercises this live native
    # snapshot while its captured Gorge search and application writers stop.
    subprocess.run(['python3',str(ROOT/'tests/operations/docker_roundtrip.py')],
                   env=dict(os.environ,GORGE_TEST_SNAPSHOT_ES_URL=adapter.endpoint,
                            PYTHONDONTWRITEBYTECODE='1'),check=True)
    receipt=adapter.capture()
    assert receipt['storage']=='external-repository-reference' and receipt['restore']=='not_verified'
    adapter.request('DELETE','/phorge_fixture')
    adapter.request('POST','/_snapshot/backup/'+receipt['snapshot']+'/_restore?wait_for_completion=true',
                    {'indices':'phorge_fixture','include_global_state':False})
    restored=adapter.request('GET','/phorge_fixture/_doc/PHID-TASK-backup-preserved')
    assert restored['_source']==payload
    metadata=adapter.request('GET','/_snapshot/backup/'+receipt['snapshot'])['snapshots'][0]
    assert metadata['uuid']==receipt['snapshotUUID']
    print('PASS: Elasticsearch 8 native snapshot and isolated restore preserve document identity and content; external production restore remains a separate gate')
finally:
    subprocess.run(['docker','rm','-f',name],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)

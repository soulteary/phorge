"""Native Elasticsearch snapshot coordination for one explicit external cluster.

The repository remains external: this captures a snapshot reference, never an
unsupported copy of Elasticsearch data directories or a standalone archive.
"""
import datetime
import json
import os
import re
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

class ElasticsearchSnapshot:
    def __init__(self, endpoint, repository, timeout=300):
        parsed=urllib.parse.urlsplit(endpoint)
        if parsed.scheme not in ('http','https') or not parsed.hostname or parsed.username or parsed.password or parsed.query or parsed.fragment:
            raise ValueError('Snapshot endpoint must be an explicit HTTP(S) URL without credentials')
        if not re.fullmatch(r'[a-z0-9][a-z0-9_-]{0,127}',repository) or not 1 <= timeout <= 3600:
            raise ValueError('Invalid snapshot repository or timeout')
        self.endpoint=endpoint.rstrip('/')
        self.repository=repository
        self.timeout=timeout
        self.indices=None

    @classmethod
    def from_args(cls, args):
        endpoint=getattr(args,'search_es_endpoint',None)
        repository=getattr(args,'search_es_repository',None)
        if bool(endpoint) != bool(repository):
            raise ValueError('Both Elasticsearch snapshot endpoint and repository are required')
        return cls(endpoint,repository,getattr(args,'search_snapshot_timeout',300)) if endpoint else None

    def validate_environment(self, env):
        if env.get('GORGE_SEARCH_CONFIG_FILE'):
            raise ValueError('Mounted search configuration needs a separate snapshot adapter')
        try:
            definitions=json.loads(env['GORGE_SEARCH_BACKENDS']) if env.get('GORGE_SEARCH_BACKENDS') else [{
                'type':env.get('GORGE_SEARCH_ENGINE') or 'elasticsearch',
                'hosts':(env.get('ES_HOST') or '').split(','),
                'protocol':env.get('ES_PROTOCOL') or 'http',
                'version':int(env.get('ES_VERSION') or '5'),
                'index':env.get('ES_INDEX') or 'phabricator'}]
            projection=json.loads(env['GORGE_SEARCH_PROJECTION']) if env.get('GORGE_SEARCH_PROJECTION') else {}
            definitions += [item['backend'] for item in projection.get('deliveries',[])]
            indices=set()
            if not isinstance(definitions,list) or not definitions: raise ValueError()
            for definition in definitions:
                if definition.get('type') != 'elasticsearch' or definition.get('version') != 8:
                    raise ValueError()
                hosts=definition.get('hosts',[])
                if not hosts: raise ValueError()
                for host in hosts:
                    host=host.strip()
                    if '://' not in host: host=(definition.get('protocol') or 'http')+'://'+host
                    if host.rstrip('/') != self.endpoint: raise ValueError()
                index=definition.get('index') or 'phabricator'
                if not re.fullmatch(r'[a-z0-9][a-z0-9_-]{0,254}',index): raise ValueError()
                indices.add(index)
        except (ValueError,KeyError,TypeError,AttributeError):
            raise ValueError('Snapshot adapter must cover every search backend and projection on the exact Elasticsearch 8 endpoint') from None
        self.indices=sorted(indices)

    def request(self, method, path, payload=None):
        headers={'Content-Type':'application/json'}
        key=os.environ.get('GORGE_BACKUP_ES_API_KEY')
        if key:
            parsed=urllib.parse.urlsplit(self.endpoint)
            if parsed.scheme != 'https' and parsed.hostname not in ('localhost','127.0.0.1','::1'):
                raise ValueError('Snapshot API credentials require HTTPS')
            headers['Authorization']='ApiKey '+key
        data=json.dumps(payload).encode() if payload is not None else None
        try:
            opener=urllib.request.build_opener(NoRedirect())
            with opener.open(urllib.request.Request(self.endpoint+path,data=data,headers=headers,method=method),timeout=min(self.timeout,30)) as response:
                raw=response.read(1048577)
                if len(raw)>1048576: raise ValueError()
                value=json.loads(raw)
                if not isinstance(value,dict): raise ValueError()
                return value
        except (OSError,ValueError,urllib.error.URLError):
            raise ValueError('Native Elasticsearch snapshot request failed; no application backup was accepted') from None

    def capture(self):
        if not self.indices: raise ValueError('Snapshot topology was not validated')
        cluster=self.request('GET','/')
        if not isinstance(cluster.get('version'),dict) or not isinstance(cluster['version'].get('number'),str) or cluster['version']['number'].split('.')[0] != '8' or not cluster.get('cluster_uuid'):
            raise ValueError('Native snapshot requires the verified Elasticsearch 8 cluster')
        encoded_repo=urllib.parse.quote(self.repository,safe='')
        repo=self.request('GET','/_snapshot/'+encoded_repo)
        if not isinstance(repo.get(self.repository),dict): raise ValueError('Snapshot repository is missing')
        name='gorge-backup-'+uuid.uuid4().hex
        path='/_snapshot/'+encoded_repo+'/'+name
        started=time.monotonic()
        self.request('PUT',path+'?wait_for_completion=false',{
            'indices':','.join(self.indices),'ignore_unavailable':False,
            'include_global_state':False,'feature_states':['none'],'partial':False})
        while time.monotonic()-started < self.timeout:
            snapshots=self.request('GET',path).get('snapshots',[])
            if len(snapshots)!=1: raise ValueError('Unexpected native snapshot result')
            snapshot=snapshots[0]
            if snapshot.get('state') == 'SUCCESS':
                shards=snapshot.get('shards',{})
                if not snapshot.get('uuid') or snapshot.get('failures') or shards.get('failed') != 0 or set(snapshot.get('indices',[])) != set(self.indices):
                    raise ValueError('Native snapshot is partial or incomplete')
                return {'format':1,'state':'native-snapshot-captured','engine':'elasticsearch',
                        'majorVersion':8,'clusterUUID':cluster['cluster_uuid'],
                        'repository':self.repository,'repositoryType':repo[self.repository].get('type'),
                        'snapshot':name,'snapshotUUID':snapshot['uuid'],'indices':self.indices,
                        'capturedAt':datetime.datetime.now(datetime.timezone.utc).isoformat(),
                        'storage':'external-repository-reference','restore':'not_verified'}
            if snapshot.get('state') not in ('IN_PROGRESS','STARTED'):
                raise ValueError('Native Elasticsearch snapshot did not succeed')
            time.sleep(min(1,self.timeout))
        raise ValueError('Native snapshot timed out; application backup remains incomplete')

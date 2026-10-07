import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec=importlib.util.spec_from_file_location('ops_search_test',Path(__file__).resolve().parents[2]/'scripts/operations/manage.py')
ops=importlib.util.module_from_spec(spec);spec.loader.exec_module(ops)
ES=ops.ElasticsearchSnapshot

class NativeSnapshot(unittest.TestCase):
    def test_production_profiles_are_preserved_in_all_inventory_checks(self):
        stack=ops.Stack(['docker-compose.yml','docker-compose.production.yml'],profiles=['mailer','search','maintenance'])
        self.assertEqual(stack.base[-6:],['--profile','mailer','--profile','search','--profile','maintenance'])
        with self.assertRaises(ValueError):ops.Stack(['docker-compose.yml'],profiles=['--other-flag'])

    def adapter(self):
        adapter=ES('http://localhost:9200','backup')
        adapter.validate_environment({'ES_HOST':'localhost:9200','ES_VERSION':'8'})
        return adapter

    def test_mismatch_test_backend_and_uncovered_projection_refused(self):
        for env in [dict(ES_HOST='other:9200',ES_VERSION='8'),dict(ES_HOST='localhost:9200',ES_VERSION='7'),
                    dict(GORGE_SEARCH_BACKENDS='[{"type":"test","hosts":["localhost:9200"],"version":8}]'),
                    dict(ES_HOST='localhost:9200',ES_VERSION='8',GORGE_SEARCH_PROJECTION='{"deliveries":[{"backend":{"type":"elasticsearch","version":8,"hosts":["other:9200"]}}]}')]:
            with self.assertRaises(ValueError):ES('http://localhost:9200','backup').validate_environment(env)

    def test_partial_failed_and_missing_snapshot_refused(self):
        base=[{'version':{'number':'8.17.3'},'cluster_uuid':'cluster'}, {'backup':{'type':'fs'}}, {'accepted':True}]
        for value in [{'snapshots':[]}, {'snapshots':[{'state':'FAILED'}]},
                      {'snapshots':[{'state':'SUCCESS','uuid':'snapshot','indices':['phabricator'],'shards':{'failed':1}}]},
                      {'snapshots':[{'state':'SUCCESS','uuid':'snapshot','indices':['wrong'],'shards':{'failed':0}}]}]:
            with patch.object(ES,'request',side_effect=base+[value]),self.assertRaises(ValueError):self.adapter().capture()

    def test_complete_snapshot_is_an_external_reference(self):
        responses=[{'version':{'number':'8.17.3'},'cluster_uuid':'cluster'}, {'backup':{'type':'fs'}}, {'accepted':True},
                   {'snapshots':[{'state':'SUCCESS','uuid':'snapshot','indices':['phabricator'],'shards':{'failed':0}}]}]
        with patch.object(ES,'request',side_effect=responses):
            result=self.adapter().capture()
        self.assertEqual(result['storage'],'external-repository-reference')
        self.assertEqual(result['restore'],'not_verified')

    def test_snapshot_failure_keeps_bundle_incomplete_and_restarts_writers(self):
        mysql={'Id':'database','Image':'sha256:'+'a'*64}
        containers=[dict(mysql,State={'Running':True}),dict(mysql,Id='search',State={'Running':True})]
        calls=[]
        def docker(args,**kwargs):
            calls.append(args)
            if args[1]=='inspect':return json.dumps([dict(containers[1],State={'Running':False})]).encode()
            return b''
        with tempfile.TemporaryDirectory() as tmp,patch.object(ops.Stack,'inspect',return_value=(containers,mysql,{})),patch.object(ops,'sql',side_effect=['event_scheduler\tOFF','0']),patch.object(ops,'assert_quiescent'),patch.object(ES,'capture',side_effect=ValueError('snapshot failed')),patch.object(ops,'command',side_effect=docker):
            directory=Path(tmp)/'backup'
            args=type('Args',(),dict(exclusive_access=True,compose=['unused'],directory=str(directory),search_es_endpoint='http://localhost:9200',search_es_repository='backup'))()
            with self.assertRaises(ValueError):ops.backup_locked(args)
            self.assertEqual(json.loads((directory/'manifest.json').read_text())['state'],'incomplete')
            self.assertIn(['docker','start','search'],calls)
            self.assertFalse((directory/'database.sql').exists())

if __name__=='__main__':unittest.main()

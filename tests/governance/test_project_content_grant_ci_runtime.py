import argparse,copy,json,os,tempfile,types,unittest
from pathlib import Path
from unittest.mock import Mock,patch
import importlib.util
ROOT=Path(__file__).resolve().parents[2]
_spec=importlib.util.spec_from_file_location('N05_reviewed_ci',ROOT/'support/governance/project-content-grants-ci/ci.py')
ci=importlib.util.module_from_spec(_spec);_spec.loader.exec_module(ci)

class RuntimeTests(unittest.TestCase):
    def state(self):
        return dict(php_version='8.5.12',mysql_version='8.4.13',server_uuid='aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',opcache_jit='disable',opcache_jit_buffer_size='0',opcache_enable_cli='0')
    def test_actual_version_and_default_JIT_required(self):
        good=self.state();self.assertEqual(ci.runtime(good),good)
        for key,value in [('php_version','8.5.8'),('mysql_version','8.0.44'),('opcache_jit','tracing'),('opcache_jit_buffer_size','128M'),('opcache_enable_cli','1'),('server_uuid','unknown'),('server_uuid','-'*36)]:
            bad=dict(good);bad[key]=value
            with self.subTest(key=key),self.assertRaises(ValueError):ci.runtime(bad)
        with self.assertRaises(ValueError):ci.runtime({**good,'authority':True})
    def test_only_one_exact_published_loopback_service(self):
        cid='a'*64
        good=[dict(Id=cid,State={'Running':True},Config={'Image':ci.MYSQL},HostConfig={'PortBindings':{'3306/tcp':[{'HostIp':'127.0.0.1','HostPort':'3306'}]}},NetworkSettings={'Ports':{'3306/tcp':[{'HostIp':'127.0.0.1','HostPort':'3306'}],'33060/tcp':None}})]
        self.assertEqual(ci.select_service(good,cid),3306)
        mutations=[lambda x:x[0]['State'].update(Running=False),lambda x:x[0]['Config'].update(Image='mysql:latest'),lambda x:x[0]['HostConfig']['PortBindings']['3306/tcp'][0].update(HostIp='0.0.0.0'),lambda x:x[0]['HostConfig']['PortBindings'].update({'33060/tcp':[]}),lambda x:x.append(x[0]),lambda x:x[0].update(Id='b'*64),lambda x:x[0]['NetworkSettings']['Ports']['3306/tcp'][0].update(HostIp='0.0.0.0'),lambda x:x[0]['NetworkSettings']['Ports'].update({'33060/tcp':[{'HostIp':'127.0.0.1','HostPort':'33060'}]})]
        for mutate in mutations:
            bad=copy.deepcopy(good);mutate(bad)
            with self.assertRaises(ValueError):ci.select_service(bad,cid)
    def test_local_host_is_refused_before_any_process_or_input(self):
        with patch.dict(os.environ,{},clear=True),patch.object(ci.subprocess,'Popen')as child:
            with self.assertRaises(ValueError):ci.execute(None)
            child.assert_not_called()
    def test_probe_uses_private_credential_file_and_actual_defaults(self):
        self.assertIn("file_get_contents($argv[1])",ci.PROBE)
        self.assertNotIn('getenv',ci.PROBE)
        self.assertIn("ini_get('opcache.jit')",ci.PROBE)
    def test_wrong_actual_runtime_stops_before_native_migration(self):
        with tempfile.TemporaryDirectory()as td:
            root=Path(td).resolve();out=root/'new-run';cid='a'*64
            a=argparse.Namespace(mysql_container=cid,prerequisites=root,qualification=root,base=root,target=root,current=root,output=out,php=Path('/usr/bin/php'),prerequisite_template=root/'p',qualification_template=root/'q')
            rows=[dict(Id=cid,State={'Running':True},Config={'Image':ci.MYSQL},HostConfig={'PortBindings':{'3306/tcp':[{'HostIp':'127.0.0.1','HostPort':'3306'}]}},NetworkSettings={'Ports':{'3306/tcp':[{'HostIp':'127.0.0.1','HostPort':'3306'}],'33060/tcp':None}})]
            bad=self.state();bad['php_version']='8.5.8'
            j=Mock();j.run.side_effect=[json.dumps(rows).encode(),json.dumps(bad).encode()]
            pm=types.SimpleNamespace(Journal=Mock(return_value=j),execute=Mock());qm=types.SimpleNamespace(execute=Mock())
            with patch.dict(os.environ,{'GITHUB_ACTIONS':'true','RUNNER_OS':'Linux','GITHUB_SHA':'c'*40,'N05_MYSQL_PASSWORD':'synthetic-unit-test-only'},clear=True),patch.object(ci,'source',return_value=ci.TREE),patch.object(ci,'current_source',return_value='c'*40),patch.object(ci,'bind_manifest',return_value={'source_sha256':{}}),patch.object(ci,'module',side_effect=[pm,qm]),patch.object(ci.subprocess,'check_output',return_value=('c'*40).encode()),patch.object(ci.subprocess,'run'):
                with self.assertRaises(ValueError):ci.execute(a)
            pm.execute.assert_not_called();qm.execute.assert_not_called()
            self.assertEqual(list((out/'public').iterdir()),[])
    def test_self_authored_matching_helper_manifest_is_not_reviewed_source(self):
        with tempfile.TemporaryDirectory()as td:
            folder=Path(td).resolve();helper=folder/'provision.py';raw=b'MARKER=True\n';helper.write_bytes(raw)
            f={'schema':'N05-CI-reviewed-source-template-v1','kind':'prerequisites','source_sha256':{'provision.py':{'sha256':ci.sha(raw),'bytes':len(raw)}},'native_public_source_dependencies':{}}
            template=folder/'self-authored.json';template.write_bytes(ci.canon(f))
            with self.assertRaises(ValueError):ci.bind_manifest(template,folder,'prerequisites',folder)
            self.assertNotIn('MARKER',globals())
    def test_native_inventory_has_independent_anchor(self):
        with tempfile.TemporaryDirectory()as td:
            folder=Path(td).resolve();f={'schema':'N05-CI-reviewed-source-template-v1','kind':'qualification','source_sha256':ci.REVIEWED_PINS['qualification'],'native_source_inventory':{}}
            template=folder/'changed-inventory.json';template.write_bytes(ci.canon(f))
            with self.assertRaises(ValueError):ci.bind_manifest(template,folder,'qualification',folder)

if __name__=='__main__':unittest.main()

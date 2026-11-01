import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
from types import SimpleNamespace
import routes


class RouteIsolationTests(unittest.TestCase):
    def test_inherited_selectors_refused_before_any_php(self):
        for selector,value in [('PHORGE_DEPLOYMENT_CONFIG','/external/config.json'),
                               ('PHORGE_DEPLOYMENT_CONFIG',''),
                               ('PHORGE_CONTROL_PLANE','legacy'),
                               ('PHORGE_CONTROL_PLANE','deployment')]:
            with self.subTest(selector=selector,value=value), \
                 patch.dict(os.environ,{selector:value}), \
                 patch('sys.argv',['routes.py','--output','unused']), \
                 patch.object(routes.subprocess,'run') as run, \
                 patch.object(routes.GATE,'configuration') as config:
                with self.assertRaisesRegex(ValueError,'Inherited deployment'):
                    routes.main()
                run.assert_not_called();config.assert_not_called()

    def test_owned_selectors_pinned_for_storage_and_cleanup(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);(root/'conf/local').mkdir(parents=True)
            source={'gitHead':'a'*40,'worktreeDirty':False,'worktreeStatus':''}
            with patch.object(routes,'ROOT',root), patch.object(routes.GATE,'capture_source',return_value=source), \
                 patch.object(routes.GATE,'configuration',return_value={}), \
                 patch.dict(os.environ,{'GORGE_TEST_SEARCH_URL':'http://127.0.0.1:8120','GORGE_TEST_SEARCH_TOKEN':'fixture','PERMISSION_ROUTES_DEBUG':'0'}), \
                 patch('sys.argv',['routes.py','--port','18551','--output',str(root/'evidence')]), \
                 patch.object(routes.subprocess,'run',return_value=SimpleNamespace(returncode=1,stdout='',stderr='')) as run:
                self.assertEqual(routes.main(),1)
                self.assertEqual(run.call_count,2)
                for call in run.call_args_list:
                    self.assertEqual(call.kwargs['env']['PHORGE_CONTROL_PLANE'],'deployment')
                    self.assertEqual(call.kwargs['env']['PHORGE_DEPLOYMENT_CONFIG'],str(root/'conf/local/deployment.json'))
            self.assertEqual(list((root/'conf/local').iterdir()),[])

    def test_existing_high_precedence_configuration_never_reaches_storage(self):
        for name in ('local.json','deployment.json'):
            with self.subTest(name=name), tempfile.TemporaryDirectory() as tmp:
                root=Path(tmp); config=root/'conf/local';config.mkdir(parents=True)
                existing=config/name;existing.write_text('private instance configuration')
                source={'gitHead':'a'*40,'worktreeDirty':False,'worktreeStatus':''}
                with patch.object(routes,'ROOT',root), patch.object(routes.GATE,'capture_source',return_value=source), \
                     patch.object(routes.GATE,'configuration',return_value={}), \
                     patch.dict(os.environ,{'GORGE_TEST_SEARCH_URL':'http://127.0.0.1:8120','GORGE_TEST_SEARCH_TOKEN':'fixture','PERMISSION_ROUTES_DEBUG':'0'}), \
                     patch('sys.argv',['routes.py','--port','18551','--output',str(root/'evidence')]), \
                     patch.object(routes.subprocess,'run',side_effect=AssertionError('Storage must not be contacted.')) as run:
                    self.assertEqual(routes.main(),1)
                    run.assert_not_called()
                self.assertEqual(existing.read_text(),'private instance configuration')
                self.assertEqual(sorted(p.name for p in config.iterdir()),[name])
                receipt=json.loads((root/'evidence/route-results.json').read_text())
                self.assertTrue(receipt['cleanup'])
                self.assertFalse(receipt['passed'])

if __name__=='__main__': unittest.main()

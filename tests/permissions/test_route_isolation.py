import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import routes


class RouteIsolationTests(unittest.TestCase):
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

import copy,importlib.util,subprocess,sys,unittest
from pathlib import Path
spec=importlib.util.spec_from_file_location('project_handoff_runner',Path(__file__).with_name('run.py'))
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)

class OwnedCleanup(unittest.TestCase):
    def test_current_caller_inventory_is_exact_and_stale_snapshot_refuses(self):
        root=Path(__file__).resolve().parents[3]
        sys.path.insert(0,str(root/'scripts/governance'))
        import check_callers
        data=check_callers.load(root/'docs/governance/batch-2-callers.json')
        actual=check_callers.scan_sources(root)
        result=check_callers.validate(data,root,actual)
        self.assertTrue(result['activation_blocked'])
        self.assertFalse(result['runtime_certified'])
        stale=copy.deepcopy(data)
        stale['scan']['source_snapshot_sha256']='0'*64
        with self.assertRaisesRegex(ValueError,'runtime source/candidate drift'):
            check_callers.validate(stale,root,actual)

    def test_foreign_label_never_removes_container(self):
        calls=[]
        def runner(args,**kw):
            calls.append(args)
            return subprocess.CompletedProcess(args,0,b'foreign-owner\n',b'')
        with self.assertRaisesRegex(RuntimeError,'ownership refused'):
            m.cleanup_owned(['a'*64],'this-owner',runner)
        self.assertEqual(1,len(calls));self.assertEqual('inspect',calls[0][1])

    def test_nonexact_id_never_contacts_docker(self):
        for cid in ('a'*12,'--all','a'*64+'\n',''):
            with self.assertRaises(ValueError):
                m.cleanup_owned([cid],'owner',lambda *a,**k:self.fail('Docker contacted'))

    def test_owned_resources_removed_in_reverse_order(self):
        calls=[]
        def runner(args,**kw):
            calls.append(args)
            return subprocess.CompletedProcess(args,0,b'owner\n'if args[1]=='inspect'else b'',b'')
        m.cleanup_owned(['a'*64,'b'*64],'owner',runner)
        self.assertEqual([['docker','rm','-f','b'*64],['docker','rm','-f','a'*64]],
                         [a for a in calls if a[1]=='rm'])

    def test_failed_inspection_never_removes_container(self):
        calls=[]
        def runner(args,**kw):
            calls.append(args)
            return subprocess.CompletedProcess(args,1,b'',b'')
        with self.assertRaises(RuntimeError):m.cleanup_owned(['a'*64],'owner',runner)
        self.assertEqual(1,len(calls))

    def test_relay_rejects_unbounded_targets_before_listening(self):
        import sys
        sys.path.insert(0,str(Path(__file__).parent))
        sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'directory_product'))
        from docker_relay import Relay
        for cid,port in [('a'*12,1234),('a'*64,0),('a'*64,65536),('a'*64,True)]:
            with self.assertRaises(ValueError):Relay(cid,port)

if __name__=='__main__':unittest.main()

import importlib.util,subprocess,unittest
from pathlib import Path
spec=importlib.util.spec_from_file_location('directory_product_runner',Path(__file__).with_name('run.py'))
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)

class OwnedCleanup(unittest.TestCase):
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
        from docker_relay import Relay
        for cid,port in [('a'*12,1234),('a'*64,0),('a'*64,65536),('a'*64,True)]:
            with self.assertRaises(ValueError):Relay(cid,port)

if __name__=='__main__':unittest.main()

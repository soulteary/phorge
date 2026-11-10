import os,tempfile,unittest
from pathlib import Path
import importlib.util
ROOT=Path(__file__).resolve().parents[2]
_spec=importlib.util.spec_from_file_location('N05_reviewed_qualify',ROOT/'support/governance/project-content-grants-qualification/qualify.py')
qualify=importlib.util.module_from_spec(_spec);_spec.loader.exec_module(qualify)

class NativeLinks(unittest.TestCase):
 def test_exact_native_storage_entry_and_payload(self):
  with tempfile.TemporaryDirectory()as td:
   root=Path(td).resolve();(root/'bin').mkdir();(root/'scripts/sql').mkdir(parents=True)
   target=root/'scripts/sql/manage_storage.php';target.write_bytes(b'<?php /* actual payload placeholder */')
   os.symlink('../scripts/sql/manage_storage.php',root/'bin/storage')
   self.assertEqual(qualify.native_source_bytes(root,'bin/storage'),target.read_bytes())
   (root/'bin/storage').unlink();other=root/'scripts/sql/other.php';other.write_bytes(target.read_bytes());os.symlink('../scripts/sql/other.php',root/'bin/storage')
   with self.assertRaises(ValueError):qualify.native_source_bytes(root,'bin/storage')
 def test_no_generic_symlink_or_path_escape(self):
  with tempfile.TemporaryDirectory()as td:
   root=Path(td).resolve();(root/'src').mkdir();(root/'src/good.php').write_bytes(b'<?php')
   os.symlink('good.php',root/'src/alias.php')
   for rel in ['src/alias.php','../outside','/absolute','src//good.php']:
    with self.subTest(rel=rel),self.assertRaises(ValueError):qualify.native_source_bytes(root,rel)
   self.assertEqual(qualify.native_source_bytes(root,'src/good.php'),b'<?php')

if __name__=='__main__':unittest.main()

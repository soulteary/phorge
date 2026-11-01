import hashlib
import unittest
from route_snapshot import remap_snapshot


class SnapshotRemapTests(unittest.TestCase):
    target='atomic_routes_'+'a'*16

    def raw(self):
        return '\n'.join('-- Current Database: `original_'+app+'`\nCREATE DATABASE /*!32312 IF NOT EXISTS*/ `original_'+app+'`;\nUSE `original_'+app+'`;\nCREATE TABLE `sample` (`value` TEXT);'
                         for app in ('project','user','spaces','policy','config')).encode()

    def remap(self, raw, **manifest):
        return remap_snapshot(raw,dict(namespace='original',snapshotSHA256=hashlib.sha256(raw).hexdigest(),**manifest),self.target)

    def test_dump_comments_preserved_and_selection_remapped(self):
        result=self.remap(self.raw())
        self.assertIn('-- Current Database: `original_project`',result)
        self.assertIn('USE `'+self.target+'_project`;',result)
        self.assertNotIn('USE `original_',result)

    def test_hash_mismatch_refused(self):
        with self.assertRaises(ValueError):
            remap_snapshot(self.raw(),{'namespace':'original','snapshotSHA256':'0'*64},self.target)

    def test_foreign_database_and_missing_schema_refused(self):
        for raw in (self.raw()+b'\nUSE `foreign_project`;',self.raw().replace(b'original_spaces',b'original_other')):
            with self.assertRaises(ValueError): self.remap(raw)

    def test_qualified_source_references_refused(self):
        with self.assertRaises(ValueError): self.remap(self.raw()+b'\nDROP TABLE `original_project`.`sample`;')

    def test_invalid_target_refused(self):
        with self.assertRaises(ValueError):
            remap_snapshot(self.raw(),{'namespace':'original','snapshotSHA256':hashlib.sha256(self.raw()).hexdigest()},'original')

if __name__=='__main__': unittest.main()

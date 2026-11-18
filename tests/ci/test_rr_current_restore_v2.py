"""Synthetic consumer DATA and own temporary files only; never RR/SQL proof."""
import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
sys.path.insert(0,str(ROOT/'tests/governance'))
import check_native_user_rr_mdl as legacy
from test_native_user_rr_mdl_gate import template as legacy_template
spec=importlib.util.spec_from_file_location('RR_current_restore_DATA',ROOT/'scripts/governance/check_native_user_rr_mdl_current_restore_v2.py')
v2=importlib.util.module_from_spec(spec);spec.loader.exec_module(v2)
REV='a'*40


def current_receipt():
    value=legacy_template();restore=value['source_restore'];audit=restore['patch_audit']
    restore['current_table_count']=552;audit['source_patch_count']=audit['applied_patch_count']=1576
    audit['migrations_executed']=39;audit['migration_source_hashes']['phabricator:'+Path(v2.TASK_SQL).name]=v2.TASK_SQL_SHA
    return value


class CurrentRestoreData(unittest.TestCase):
    def accept(self,value,root=ROOT):return v2.validate(value,root,source_revision=REV)

    def test_valid_current_is_explicit_reference_only_and_raw_unchanged(self):
        value=current_receipt();before=copy.deepcopy(value);accepted=self.accept(value)
        self.assertEqual(value,before);self.assertEqual(accepted['schema'],v2.SCHEMA)
        self.assertEqual(accepted['scope'],v2.SCOPE);self.assertEqual(accepted['checks'],21)
        self.assertEqual(accepted['SQL_obligations'],12);self.assertEqual(accepted['native_children'],2)
        self.assertTrue(accepted['legacy_reference_validated']);self.assertTrue(accepted['legacy_reference_only'])
        for key in ('raw_v1_accepted','raw_v1_profile_qualified','raw_receipt_modified','Task_physical_schema_verified',
                    'Task_empty_tables_verified','Task_config_epoch_verified','independent_Task_schema_before_after_verified',
                    'SQL_executed_by_validator','source_owner_issued','production_authority'):
            self.assertIs(accepted[key],False,key)
        self.assertEqual(accepted['legacy_reference'],legacy.validate(legacy_template(),source_revision=REV))

    def test_original_v1_gate_and_real_CLI_still_reject_current_raw(self):
        value=current_receipt()
        with self.assertRaises(ValueError):legacy.validate(value,source_revision=REV)
        with self.assertRaises(ValueError):self.accept(legacy_template())
        with tempfile.TemporaryDirectory() as tmp:
            path=Path(tmp).resolve()/'receipt.json';path.write_text(json.dumps(value));path.chmod(0o600)
            result=subprocess.run([sys.executable,'-B',str(ROOT/v2.GATE),'--receipt',str(path),
                '--source-revision',REV],capture_output=True,text=True,timeout=5)
            self.assertNotEqual(result.returncode,0)

    def test_new_real_CLI_equals_inprocess_and_no_profile_fallback(self):
        with tempfile.TemporaryDirectory() as tmp:
            path=Path(tmp).resolve()/'receipt.json';value=current_receipt();path.write_text(json.dumps(value));path.chmod(0o600)
            argv=[sys.executable,'-B',str(Path(v2.__file__)),'--receipt',str(path),'--source-revision',REV,'--source-root',str(ROOT)]
            result=subprocess.run(argv,capture_output=True,text=True,timeout=5)
            self.assertEqual(result.returncode,0,result.stderr);self.assertEqual(result.stderr,'')
            self.assertEqual(json.loads(result.stdout),self.accept(value))
            value['source_restore']['current_table_count']=549;path.write_text(json.dumps(value))
            result=subprocess.run(argv,capture_output=True,text=True,timeout=5)
            self.assertEqual(result.returncode,1);self.assertEqual(result.stderr,'')
            self.assertFalse(json.loads(result.stdout)['current_restore_metadata_accepted'])
            result=subprocess.run(argv+['--profile','caller-profile'],capture_output=True,text=True,timeout=5)
            self.assertNotEqual(result.returncode,0)

    def test_every_restore_and_audit_key_is_closed(self):
        value=current_receipt()
        for name in ('source_restore','audit'):
            keys=value['source_restore'] if name=='source_restore' else value['source_restore']['patch_audit']
            self.assertEqual(len(keys),8 if name=='source_restore' else 6)
            for key in [*keys,'unreviewed']:
                changed=copy.deepcopy(value);target=changed['source_restore'] if name=='source_restore' else changed['source_restore']['patch_audit']
                if key=='unreviewed':target[key]=0
                else:del target[key]
                with self.subTest(name=name,key=key),self.assertRaises(ValueError):self.accept(changed)

    def test_every_restore_scalar_and_type_is_exact(self):
        base=current_receipt()
        for key,value in base['source_restore'].items():
            if key=='patch_audit':continue
            bad=(True,False,None,str(value),float(value),value+1) if type(value)is int else (None,False,value.upper(),'0'*64)
            for replacement in bad:
                if type(replacement)is type(value) and replacement==value:continue
                changed=copy.deepcopy(base);changed['source_restore'][key]=replacement
                with self.subTest(key=key,replacement=replacement),self.assertRaises(ValueError):self.accept(changed)
        for key in ('source_patch_count','applied_patch_count','migrations_executed','current_patch_ledger_complete','pending_patch_keys'):
            value=base['source_restore']['patch_audit'][key]
            bad=(True,None,str(value),float(value),value+1) if type(value)is int else ((False,1,None,'true')if type(value)is bool else(None,{},(),['pending']))
            for replacement in bad:
                changed=copy.deepcopy(base);changed['source_restore']['patch_audit'][key]=replacement
                with self.subTest(key=key,replacement=replacement),self.assertRaises(ValueError):self.accept(changed)

    def test_all_39_migration_hashes_names_and_types_are_required(self):
        base=current_receipt();hashes=base['source_restore']['patch_audit']['migration_source_hashes'];self.assertEqual(len(hashes),39)
        for key in hashes:
            for mode in ('missing','hash','type','samecount-name'):
                changed=copy.deepcopy(base);rows=changed['source_restore']['patch_audit']['migration_source_hashes']
                if mode=='missing':del rows[key]
                elif mode=='hash':rows[key]='0'*64
                elif mode=='type':rows[key]=True
                else:rows['phabricator:unreviewed.sql']=rows.pop(key)
                with self.subTest(key=key,mode=mode),self.assertRaises(ValueError):self.accept(changed)

    def test_all_original_21_obligations_still_required(self):
        for check in legacy.BOOTSTRAP|legacy.SQL|legacy.CLEANUP:
            changed=current_receipt();changed['checks'].remove(check)
            with self.subTest(check=check),self.assertRaises(ValueError):self.accept(changed)
        for key,value in (('check_count',True),('passed',False),('cleanup_failures',['unknown'])):
            changed=current_receipt();changed[key]=value
            with self.assertRaises(ValueError):self.accept(changed)

    def test_every_original_cleanup_and_terminal_bit_still_required(self):
        base=current_receipt();paths=[]
        paths += [('cleanup',key)for key in base['cleanup']]
        paths += [('worker_cleanup',key)for key in base['worker_cleanup']]
        paths += [('native','local_cleanup',i,key)for i in range(2)for key in base['native']['local_cleanup'][i]]
        paths += [('native','final_owned_cleanup',key)for key in ('cleanup_proved','local_children_registered','failures','revoker_closed')]
        paths += [('native','final_owned_cleanup','principal_cleanup',key)for key in base['native']['final_owned_cleanup']['principal_cleanup']]
        for path in paths:
            changed=copy.deepcopy(base);target=changed
            for key in path[:-1]:target=target[key]
            value=target[path[-1]];target[path[-1]]=not value if type(value)is bool else value+1 if type(value)is int else ['unproved']
            with self.subTest(path=path),self.assertRaises(ValueError):self.accept(changed)

    def test_original_scope_and_revision_cannot_be_promoted(self):
        for key in ('native_enable_executed','minimal_grants_negative_SQL_complete','unknown_transport_fault_matrix_complete',
                    'production_companion_fence_installed','role_proxy_dynamic_privileges_complete','all13_complete','restoration_authorized'):
            changed=current_receipt();changed['native'][key]=True
            with self.subTest(key=key),self.assertRaises(ValueError):self.accept(changed)
        for revision in ('b'*40,'0'*40,True,None):
            with self.assertRaises(ValueError):v2.validate(current_receipt(),ROOT,source_revision=revision)

    def test_fixed_current_assets_and_consumers_refuse_source_tamper_and_alias(self):
        _,completion=v2.consumers(ROOT)
        names=[v2.GATE,v2.COMPLETION,v2.TASK_SQL,v2.TASK_SPEC,'resources/sql/quickstart.sql']
        names += ['resources/sql/autopatches/'+name for name in completion.PATCHES]
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp).resolve()
            for name in names:
                path=root/name;path.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(ROOT/name,path)
            self.accept(current_receipt(),root)
            for name in (v2.TASK_SQL,v2.TASK_SPEC,v2.GATE,v2.COMPLETION):
                path=root/name;raw=path.read_bytes();path.write_bytes(raw+b'\n# unreviewed')
                with self.subTest(name=name),self.assertRaises(ValueError):self.accept(current_receipt(),root)
                path.write_bytes(raw)
            path=root/v2.TASK_SQL;path.unlink();path.symlink_to(ROOT/v2.TASK_SQL)
            with self.assertRaises(ValueError):self.accept(current_receipt(),root)
        self.assertEqual(v2.sha((ROOT/v2.GATE).read_bytes()),v2.GATE_SHA)
        self.assertEqual(v2.sha((ROOT/v2.COMPLETION).read_bytes()),v2.COMPLETION_SHA)

    def test_duplicate_nonfinite_and_unbounded_receipt_inputs_refuse(self):
        for raw in (b'{"x":1,"x":2}',b'{"x":NaN}',b'{"x":Infinity}'):
            with self.assertRaises(ValueError):v2.decode(raw)
        with tempfile.TemporaryDirectory() as tmp:
            path=Path(tmp).resolve()/'receipt';path.write_bytes(b'x'*1048577)
            with self.assertRaises(ValueError):v2.read_file(path,1048576)
            path.unlink();path.symlink_to(ROOT/v2.GATE)
            with self.assertRaises(ValueError):v2.read_file(path,1048576)

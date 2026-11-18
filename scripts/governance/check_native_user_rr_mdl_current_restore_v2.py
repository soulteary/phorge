#!/usr/bin/env python3
"""RR current restore metadata only; no SourceOwner, schema-layout or SQL permit.

The fixed CI runner must separately bind this consumer and its two Task assets
from the exact Git checkout to the live immutable stage. This pure validator
never treats a staged directory, caller profile or previous receipt as authority.
"""
import argparse
import copy
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import types

ROOT=Path(__file__).resolve().parents[2]
TASK_SQL='resources/sql/autopatches/20261112.task.contentgrants.sql'
TASK_SPEC='src/infrastructure/governance/content/PhorgeTaskContentGrantSchemaSpec.php'
TASK_SQL_SHA='632b4412c18ea61787ca6b38afc9d3c16b62f76591e9a99d624401eeac117236'
TASK_SPEC_SHA='7cac65fe600e2681eed05618fd584a02f37e27b7f532d6ca2b59244bf5045ccb'
GATE='scripts/governance/check_native_user_rr_mdl.py'
GATE_SHA='45f5097603be4862928426cb346145805d13165ee03b8e07124837988e8c8a1f'
COMPLETION='scripts/governance/check_completion.py'
COMPLETION_SHA='39956a43c26607ac5ebb761ec25f686b2314326511a082f80efe4bf3a2258649'
IMPORT=b'import check_completion as completion\n'
SCHEMA='native-user-RR-current-restore-metadata-v2'
SCOPE='same-native-User-writer-RR-MDL-current-restore-metadata-only'


def need(value):
    if value is not True:raise ValueError('RR_current_restore_metadata_refused')


def sha(raw):return hashlib.sha256(raw).hexdigest()
def stamp(s):return(s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid,s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)


def decode(raw):
    def unique(pairs):
        result={}
        for key,value in pairs:need(key not in result);result[key]=value
        return result
    return json.loads(raw,object_pairs_hook=unique,parse_constant=lambda _:need(False))


def read_file(path,limit):
    path=Path(path);need(path.is_absolute() and path.resolve(strict=True)==path)
    before=path.lstat()
    need(stat.S_ISREG(before.st_mode) and before.st_uid==os.geteuid() and before.st_nlink==1
         and not before.st_mode&0o022 and 0<before.st_size<=limit)
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    try:
        need(stamp(os.fstat(fd))==stamp(before));parts=[];offset=0
        while offset<before.st_size:
            block=os.read(fd,min(65536,before.st_size-offset));need(bool(block));parts.append(block);offset+=len(block)
        need(os.read(fd,1)==b'' and stamp(os.fstat(fd))==stamp(before)==stamp(path.lstat()))
        return b''.join(parts)
    finally:os.close(fd)


def source_bytes(root,relative):
    root=Path(root);need(root.is_absolute() and root.resolve(strict=True)==root)
    path=root
    for part in Path(relative).parts[:-1]:
        path=path/part;info=path.lstat();need(stat.S_ISDIR(info.st_mode) and not path.is_symlink())
    return read_file(root/relative,16777216)


def consumers(root):
    # Compile only these unchanged CURRENT pure consumers, never Git old code.
    # A single exact import is supplied by the verified completion module. The
    # inverse below proves every other original gate byte remains unchanged.
    completion_raw=source_bytes(root,COMPLETION);gate_raw=source_bytes(root,GATE)
    need(sha(completion_raw)==COMPLETION_SHA and sha(gate_raw)==GATE_SHA and gate_raw.count(IMPORT)==1)
    completion=types.ModuleType('RR_current_verified_completion_DATA');completion.__file__=str(Path(root)/COMPLETION)
    exec(compile(completion_raw,completion.__file__,'exec'),completion.__dict__)
    offset=gate_raw.index(IMPORT);adapted=gate_raw[:offset]+gate_raw[offset+len(IMPORT):]
    need(adapted[:offset]+IMPORT+adapted[offset:]==gate_raw)
    gate=types.ModuleType('RR_current_verified_legacy_reference_DATA');gate.__file__=str(Path(root)/GATE)
    gate.__dict__['completion']=completion
    exec(compile(adapted,gate.__file__,'exec'),gate.__dict__)
    return gate,completion


def exact(value,expected):
    need(type(value)is type(expected))
    if type(expected)is dict:
        need(set(value)==set(expected))
        for key,item in expected.items():exact(value[key],item)
    elif type(expected)is list:
        need(len(value)==len(expected))
        for a,b in zip(value,expected):exact(a,b)
    else:need(value==expected)


def validate(data,root=ROOT,*,source_revision):
    need(type(source_revision)is str and re.fullmatch('[a-f0-9]{40}',source_revision)is not None
         and source_revision!='0'*40 and type(data)is dict)
    root=Path(root);gate,completion=consumers(root)
    patches=completion.PATCHES
    need(type(patches)is list and len(patches)==len(set(patches))==38
         and all(type(p)is str and re.fullmatch('[A-Za-z0-9_.-]+',p)is not None for p in patches))
    leaf=Path(TASK_SQL).name;need(leaf not in patches)
    task=source_bytes(root,TASK_SQL);spec=source_bytes(root,TASK_SPEC)
    need(sha(task)==TASK_SQL_SHA and sha(spec)==TASK_SPEC_SHA)
    hashes={'phabricator:'+p:sha(source_bytes(root,'resources/sql/autopatches/'+p))for p in patches}
    task_key='phabricator:'+leaf;current_hashes={**hashes,task_key:TASK_SQL_SHA}
    need(len(current_hashes)==39)
    quickstart=sha(source_bytes(root,'resources/sql/quickstart.sql'))
    expected={'quickstart_sha256':quickstart,'app_schema_count':54,'owned_schema_count':55,
        'quickstart_source_table_count':500,'current_table_count':552,'restore_phase_workers_started':0,
        'restore_phase_network_endpoints_configured':0,'patch_audit':{'source_patch_count':1576,
        'applied_patch_count':1576,'pending_patch_keys':[],'current_patch_ledger_complete':True,
        'migration_source_hashes':current_hashes,'migrations_executed':39}}
    exact(data.get('source_restore'),expected)
    # Only authenticated source metadata is projected. The raw receipt and its
    # original scope remain intact; every other original RR predicate still runs.
    reference=copy.deepcopy(data);restore=reference['source_restore'];audit=restore['patch_audit']
    restore['current_table_count']=549;audit['source_patch_count']=audit['applied_patch_count']=1575
    audit['migrations_executed']=38;del audit['migration_source_hashes'][task_key]
    accepted=gate.validate(reference,root=root,source_revision=source_revision)
    return {'schema':SCHEMA,'profile':'RR-current-restore-metadata-v2','scope':SCOPE,'passed':True,
        'source_revision':source_revision,'checks':21,'SQL_obligations':12,'native_children':2,
        'current_restore_metadata_verified':True,'legacy_reference_validated':True,
        'legacy_reference_only':True,'legacy_reference':accepted,'raw_v1_accepted':False,
        'raw_v1_profile_qualified':False,'raw_receipt_modified':False,'Task_physical_schema_verified':False,
        'Task_empty_tables_verified':False,'Task_config_epoch_verified':False,
        'independent_Task_schema_before_after_verified':False,'SQL_executed_by_validator':False,
        'source_owner_issued':False,'production_authority':False}


def main():
    parser=argparse.ArgumentParser(allow_abbrev=False)
    parser.add_argument('--receipt',required=True,type=Path)
    parser.add_argument('--source-revision',required=True)
    parser.add_argument('--source-root',required=True,type=Path)
    args=parser.parse_args()
    try:
        result=validate(decode(read_file(args.receipt,1048576)),args.source_root,source_revision=args.source_revision)
        print(json.dumps(result,sort_keys=True));return 0
    except Exception:
        print(json.dumps({'schema':SCHEMA,'current_restore_metadata_accepted':False,
            'raw_v1_profile_qualified':False,'SQL_executed_by_validator':False}));return 1


if __name__=='__main__':raise SystemExit(main())

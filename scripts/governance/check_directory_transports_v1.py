#!/usr/bin/env python3
"""Strict selected native-directory receipt/source replay; never activation."""
import argparse, contextvars, itertools, hashlib, importlib.util, json, os, re, stat, subprocess
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
MANIFEST='tests/governance/native-directory-transports-source-v1.json'
SOURCE_FILES=frozenset(('.github/workflows/native-directory-transports-v1.yml', '.github/workflows/oauth-authorization-producer-focused.yml', '.github/workflows/oauth-client-lifecycle-focused.yml', 'resources/sql/autopatches/20260911.differential.01.appemail.php', 'resources/sql/autopatches/20260911.paste.01.appemail.php', 'resources/sql/autopatches/20260918.differential.01.heraldrules.php', 'resources/sql/autopatches/20260918.differential.02.diffpolicy.php', 'resources/sql/autopatches/20260918.owners.01.heraldrules.php', 'resources/sql/autopatches/20260918.owners.02.auditrequests.php', 'scripts/governance/check_completion.py', 'scripts/governance/check_directory_transports_v1.py', 'scripts/governance/check_native_organization.py', 'scripts/governance/probe_oauth_authorization_mysql84.php', 'scripts/governance/project_directory_transport_failure_v1.py', 'scripts/governance/qualify_oauth_authorization_mysql84.py', 'src/applications/conduit/call/ConduitCall.php', 'src/applications/conduit/controller/PhabricatorConduitAPIController.php', 'src/applications/conduit/method/ConduitAPIMethod.php', 'src/applications/conduit/protocol/ConduitAPIRequest.php', 'src/applications/conduit/query/PhabricatorConduitTokenQuery.php', 'src/applications/conduit/storage/PhabricatorConduitToken.php', 'src/applications/files/builtin/PhabricatorFilesComposeAvatarBuiltinFile.php', 'src/applications/files/engine/PhabricatorFileStorageEngine.php', 'src/applications/files/engine/PhabricatorGorgeFileStorageEngine.php', 'src/applications/files/storage/PhabricatorFile.php', 'src/applications/people/cache/PhabricatorUserProfileImageCacheType.php', 'src/applications/people/governance/approval/PhorgeGovernanceNativeApprovalAdapter.php', 'src/applications/people/governance/auth/PhorgeGovernanceNativeActorContext.php', 'src/applications/people/governance/auth/PhorgeGovernanceNativeIdentitySnapshot.php', 'src/applications/people/governance/auth/PhorgeGovernanceNativeIdentitySource.php', 'src/applications/people/governance/conduit/PeopleOrganizationEditConduitAPIMethod.php', 'src/applications/people/governance/conduit/PeopleOrganizationQueryConduitAPIMethod.php', 'src/applications/people/governance/controller/PhorgeOrganizationController.php', 'src/applications/people/governance/query/PhorgeOrganizationQuery.php', 'src/applications/people/governance/service/PhorgeOrganizationEndpoint.php', 'src/applications/people/governance/service/PhorgeOrganizationRequest.php', 'src/applications/people/governance/service/PhorgeOrganizationService.php', 'src/applications/people/phid/PhabricatorPeopleUserPHIDType.php', 'src/applications/people/query/PhabricatorPeopleQuery.php', 'src/applications/people/storage/PhabricatorUser.php', 'src/applications/phid/query/PhabricatorObjectQuery.php', 'src/applications/phid/type/PhabricatorPHIDType.php', 'src/infrastructure/cluster/PhabricatorGorgeFileStorageClient.php', 'support/governance/completion/fixture.php', 'support/governance/completion/pipeline-gorge.php', 'support/governance/fixtures/PhorgeGovernanceNativeFixture.php', 'support/governance/native-organization/README.directory-transports.md', 'support/governance/native-organization/directory-avatar-fixture.php', 'support/governance/native-organization/directory-output-origin-fixture.php', 'support/governance/native-organization/directory-output-origin.php', 'support/governance/native-organization/directory-restore-progress.php', 'support/governance/native-organization/directory-transport-cases.php', 'support/governance/native-organization/directory-transport-diagnostic-v1.json', 'support/governance/native-organization/directory-transport-diagnostic.php', 'support/governance/native-organization/native-cases.php', 'support/governance/native-organization/privacy-cases.php', 'support/governance/native-organization/route-cases.php', 'support/governance/native-organization/test-directory-transports-v1-mysql.php', 'support/governance/native-organization/test-native-organization-mysql.php', 'support/runtime/php-version.php', 'tests/atomic/build_directory_file.py', 'tests/atomic/build_gorge.py', 'tests/atomic/check_pair.py', 'tests/atomic/paired-gorge.json', 'tests/governance/test_calendar_conduit_union.py', 'tests/governance/test_directory_avatar_fixture.py', 'tests/governance/test_directory_file_build.py', 'tests/governance/test_directory_output_hints.py', 'tests/governance/test_directory_output_origin.py', 'tests/governance/test_directory_restore_progress.py', 'tests/governance/test_directory_terminal_failure_v1.py', 'tests/governance/test_directory_transport_failure_v1.py', 'tests/governance/test_directory_transports.py', 'tests/governance/test_directory_transports_receipt_v1.py'))
SOURCE_FILES=SOURCE_FILES|frozenset(('scripts/governance/project_description_source_delta.py','scripts/governance/project_description_admission_delta.py'))
ROLES={'admin':['dt-child','dt-parent'],'ordinary':[],'parent':['dt-parent'],'child':['dt-child'],'both':['dt-child','dt-parent']}
AVATAR_ROLES=['admin','ordinary','parent','child','both','addedweb','addedapi']
ACTIONS=['grant','revoke','propose','compound-propose','compound-execute']

RESTORE_LINES={
 '20260911.differential.01.appemail.php':'No Differential application email addresses to release.',
 '20260911.paste.01.appemail.php':'No Paste application email addresses to release.',
 '20260918.differential.01.heraldrules.php':'No Herald rules test removed Differential revision fields.',
 '20260918.differential.02.diffpolicy.php':'No retained diffs inherited a restrictive revision policy.',
 '20260918.owners.01.heraldrules.php':'No Herald rules test removed Owners package fields.',
 '20260918.owners.02.auditrequests.php':'No commits carry Owners package audit requests.'}
def validate_restore_progress(value,root):
    need(type(value)is dict and set(value)=={'profile','bytes','sha256','line_count','exact_six_empty_lines_once','separate_from_native_business_capture','unknown_output_accepted','source_sha256'})
    lines=[(x+'\n').encode()for x in RESTORE_LINES.values()]
    exact(value['profile'],'owned-empty-native-restore-progress-v1');exact(value['bytes'],sum(map(len,lines)))
    exact(value['line_count'],6);exact(value['exact_six_empty_lines_once'],True)
    exact(value['separate_from_native_business_capture'],True);exact(value['unknown_output_accepted'],False)
    need(type(value['sha256'])is str and value['sha256']in {hashlib.sha256(b''.join(order)).hexdigest()for order in itertools.permutations(lines)})
    exact(value['source_sha256'],{'resources/sql/autopatches/'+n:hashlib.sha256((Path(root)/'resources/sql/autopatches'/n).read_bytes()).hexdigest()for n in RESTORE_LINES})

TOP_FIELDS=frozenset(('batch','passed','scope','php_version','optional_source_reader_adapter_exercised',
 'isolated_optional_features_enabled','worker_cleanup','file_service_cleanup','successful_owned_file_service_startups',
 'cleanup','failure','cleanup_failures','terminal_failure_cleanup_rollbacks','captured_native_output_bytes',
 'captured_native_output_sha256','production_deployment','production_executor','production_optional_features_enabled',
 'native_permission_axes_changed','remaining_blocked'))
FAILED_CHECKS=frozenset({'unknown','arguments','source-inventory','file-binary','receipt-read','receipt-shape',
 'checks-shape','checks-exact','checks-count','avatars-shape','avatar-row-shape','avatar-fixed-fields',
 'avatar-readback-bytes','avatar-readback-hash','native-exact','source-restore','restore-progress'}|{'top.'+k for k in TOP_FIELDS})
_FAILURE_CHECK=contextvars.ContextVar('directory_failed_check',default='unknown')
def mark(label):
    if label not in FAILED_CHECKS:raise ValueError('invalid fixed diagnostic label')
    _FAILURE_CHECK.set(label)
def failed_check():
    value=_FAILURE_CHECK.get()
    return value if value in FAILED_CHECKS else 'unknown'

def need(ok):
    if not ok: raise ValueError('directory transport evidence refused')
def exact(a,b):
    need(type(a) is type(b))
    if type(b) is dict:
        need(set(a)==set(b))
        for k in b: exact(a[k],b[k])
    elif type(b) is list:
        need(len(a)==len(b))
        for x,y in zip(a,b): exact(x,y)
    else: need(a==b)
def unique(pairs):
    out={}
    for k,v in pairs:
        need(k not in out);out[k]=v
    return out
def read_json(path,with_digest=False):
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    try:
        before=os.fstat(fd);need(stat.S_ISREG(before.st_mode) and before.st_nlink==1 and before.st_size<=8*1024*1024)
        raw=b''
        while len(raw)<before.st_size:
            chunk=os.read(fd,min(65536,before.st_size-len(raw)));need(bool(chunk));raw+=chunk
        need(os.read(fd,1)==b'');after=os.fstat(fd);entry=os.stat(path,follow_symlinks=False)
        stamp=lambda s:(s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid,s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
        need(stamp(before)==stamp(after)==stamp(entry))
        data=json.loads(raw,object_pairs_hook=unique)
        return (data,hashlib.sha256(raw).hexdigest()) if with_digest else data
    finally:os.close(fd)
def expected_samples():
    rows=[]
    for role,ids in ROLES.items():
        for mode in ['list','selector','export','count','user-teams']:
            rows.append(dict(actor=role,mode=mode,unit=None,status=200,same_projection=True,row_count=len(ids),
                unit_ids=[] if mode=='count' else ids,parent_ids=[] if mode=='count' else ['dt-parent' if x=='dt-child' and 'dt-parent' in ids else None for x in ids]))
        for unit in ['dt-parent','dt-child']:
            for mode in ['members','member-count']:
                rows.append(dict(actor=role,mode=mode,unit=unit,status=200 if unit in ids else 403,
                    same_projection=True,row_count=3 if unit in ids else 0,unit_ids=[],parent_ids=[]))
    return rows

def expected_checks():
    checks={'full-current-source-quickstart-and-patch-ledger-restored','completion-reader-real-select-only-grants-and-lineage-proved',
        'completion-writer-forbidden-privilege-denied','completion-writer-cannot-modify-ownership-marker',
        'completion-reader-forbidden-privilege-denied','completion-reader-cannot-modify-ownership-marker',
        'owned-native-write-guard-disposed','owned-writer-environment-and-cache-restored','all-owned-full-native-schemas-and-principals-cleaned',
        'directory-transports-unrelated-media-and-delivery-off','directory-transports-unknown-mode-generic-refusal',
        'directory-transports-expired-token-native-authentication-refused','directory-transports-deleted-token-native-authentication-refused'}
    for role,ids in ROLES.items():
        checks.add(f'directory-transports-{role}-explicit-projection')
        for mode in ['list','selector','export','count','user-teams']:
            checks.add(f'directory-transports-{role}-{mode}-web-conduit-same-projection')
            if mode=='count':checks.add(f'directory-transports-{role}-count-exact')
            else:
                checks.add(f'directory-transports-{role}-{mode}-exact-ids')
                checks.update(f'directory-transports-{role}-{mode}-{unit}-parent-redaction' for unit in ids)
        for unit in ['dt-parent','dt-child']:
            checks.update(f'directory-transports-{role}-{unit}-{mode}-'+('visible' if unit in ids else 'hidden') for mode in ['members','member-count'])
    checks.update(f'directory-transports-team-admin-{a}-denied-unchanged' for a in ACTIONS)
    for t in ['web','conduit']:checks.update([f'directory-transports-ordinary-add-{t}-native-success',f'directory-transports-ordinary-add-{t}-no-appointment'])
    checks.add('completion-real-gorge-file-ready-loopback');checks.add('directory-transports-paired-file-profile-isolated')
    checks.update(f'directory-transports-native-avatar-{role}-stored-readback' for role in AVATAR_ROLES)
    return checks

def source(root=ROOT,revision=None):
    mark('source-inventory')
    root=Path(root);manifest=read_json(root/MANIFEST)
    need(type(manifest) is dict and set(manifest)=={'schema','base_revision','production_git_tree_inventory_sha256','pins'})
    exact(manifest['schema'],'native-directory-transports-source-v1')
    exact(manifest['base_revision'],'149f315b4c29cdc5a9f3b2d10a92c6eab0403e1f')
    need(type(manifest['pins']) is dict and set(manifest['pins'])==SOURCE_FILES)
    for path,pin in manifest['pins'].items():
        need(type(path) is str and not Path(path).is_absolute() and '..' not in Path(path).parts)
        raw=(root/path).read_bytes();exact(len(raw),pin['bytes']);exact(hashlib.sha256(raw).hexdigest(),pin['sha256'])
    tree=subprocess.check_output(['git','-C',str(root),'ls-tree','-r','HEAD','--','src','resources'])
    exact(hashlib.sha256(tree).hexdigest(),manifest['production_git_tree_inventory_sha256'])
    subprocess.run(['git','-C',str(root),'diff','--quiet','HEAD','--','src','resources'],check=True)
    need(not subprocess.check_output(['git','-C',str(root),'ls-files','--others','--exclude-standard','--','src','resources']))
    if revision is not None:
        need(type(revision) is str and re.fullmatch('[a-f0-9]{40}',revision))
        exact(subprocess.check_output(['git','-C',str(root),'rev-parse','HEAD']).decode().strip(),revision)
        exact(subprocess.check_output(['git','-C',str(root),'show',revision+':'+MANIFEST]),(root/MANIFEST).read_bytes())
        for path in manifest['pins']:
            exact(subprocess.check_output(['git','-C',str(root),'show',revision+':'+path]),(root/path).read_bytes())
    return manifest

def validate(data,root=ROOT,file_digest=None):
    mark('file-binary')
    need(type(file_digest) is str and re.fullmatch('[a-f0-9]{64}',file_digest))
    # Preserve exact historical full-restore validation; do not relax old consumers.
    spec=importlib.util.spec_from_file_location('directory_v1_restore',Path(root)/'scripts/governance/check_native_organization.py')
    inherited=importlib.util.module_from_spec(spec);spec.loader.exec_module(inherited)
    mark('receipt-shape')
    need(type(data) is dict and set(data)=={'batch','passed','scope','checks','check_count','php_version','source_restore','restore_progress','native',
        'optional_source_reader_adapter_exercised','isolated_optional_features_enabled','worker_cleanup','file_service_cleanup',
        'successful_owned_file_service_startups','cleanup','failure','cleanup_failures','terminal_failure_cleanup_rollbacks',
        'captured_native_output_bytes','captured_native_output_sha256','production_deployment','production_executor',
        'production_optional_features_enabled','native_permission_axes_changed','remaining_blocked'})
    for k,v in dict(batch='native-directory-transports-v2-restore-progress',passed=True,scope='owned-native-directory-transport-matrix-with-paired-file-and-exact-restore-progress',php_version='8.5.12',
        optional_source_reader_adapter_exercised=True,isolated_optional_features_enabled=True,worker_cleanup=None,file_service_cleanup=dict(kind='file',binary_sha256=file_digest,process_stopped=True,private_storage_removed=True),
        successful_owned_file_service_startups=1,cleanup={'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True},failure=None,
        cleanup_failures=[],terminal_failure_cleanup_rollbacks=0,captured_native_output_bytes=0,captured_native_output_sha256=hashlib.sha256(b'').hexdigest(),
        production_deployment=False,production_executor=False,production_optional_features_enabled=False,native_permission_axes_changed=False,
        remaining_blocked=['media-http-and-cookie-integration','file-object-guards','notification-producers','global-governance-completion']).items():
        mark('top.'+k);exact(data[k],v)
    mark('checks-shape')
    checks=data['checks'];need(type(checks) is list and all(type(x) is str for x in checks))
    mark('checks-exact');need(len(checks)==len(set(checks)) and set(checks)==expected_checks())
    mark('checks-count');exact(data['check_count'],len(checks))
    mark('avatars-shape')
    need(type(data['native']) is dict)
    native=dict(data['native']);avatars=native.pop('avatars',None);need(type(avatars) is list and len(avatars)==7)
    for role,row in zip(AVATAR_ROLES,avatars):
        mark('avatar-row-shape')
        need(type(row) is dict and set(row)=={'role','storage_engine','fresh_native_user_and_file_match','readback_bytes','readback_sha256','width','height','actual_PNG_decode','content_hash_match'})
        mark('avatar-fixed-fields')
        for k,v in dict(role=role,storage_engine='gorge',fresh_native_user_and_file_match=True,width=400,height=400,actual_PNG_decode=True,content_hash_match=True).items():exact(row[k],v)
        mark('avatar-readback-bytes')
        need(type(row['readback_bytes']) is int and 1<=row['readback_bytes']<=1048576)
        mark('avatar-readback-hash')
        need(type(row['readback_sha256']) is str and re.fullmatch('[a-f0-9]{64}',row['readback_sha256']))
    mark('native-exact')
    exact(native,dict(schema='native-directory-transports-v1-file-dependency',read_samples=expected_samples(),
        negative_actions=[dict(action=a,web_status=403,conduit_generic_error=True,business_unchanged=True) for a in ACTIONS],
        ordinary_adds=[dict(transport=t,member_present=True,appointment_version=0) for t in ['web','conduit']],
        actual_native_ConduitCall=True,actual_native_token_authentication=True,actual_native_Web_controller=True,
        fake_query_or_actor=False,HTTP_server_or_browser=False,production_authority=False,native_permission_axes_changed=False))
    mark('restore-progress');validate_restore_progress(data['restore_progress'],root)
    mark('source-restore')
    inherited.validate_restore(data['source_restore'],Path(root))
    return dict(schema='native-directory-transports-accepted-v2-exact-restore-progress',selected_inprocess_matrix_accepted=True,
        read_samples=45,avatars=7,paired_file_binary_sha256=file_digest,restore_progress={k:data['restore_progress'][k]for k in ('profile','bytes','sha256','line_count')},checks=len(checks),source_bound_by_CLI=False,runtime_authority=False,
        HTTP_browser_proved=False,full_N01_N04_complete=False,production_enabled=False)

def file_binary_digest(path,expected):
    mark('file-binary')
    need(type(path) is str and type(expected) is str and re.fullmatch('[a-f0-9]{64}',expected))
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    try:
        before=os.fstat(fd);need(stat.S_ISREG(before.st_mode) and before.st_nlink==1 and 64<=before.st_size<=64*1024*1024)
        need(stat.S_IMODE(before.st_mode) in {0o700,0o755})
        digest=hashlib.sha256();total=0;header=b''
        while total<before.st_size:
            block=os.read(fd,min(65536,before.st_size-total));need(bool(block))
            if not header:header=block[:64]
            digest.update(block);total+=len(block)
        need(os.read(fd,1)==b'')
        stamp=lambda s:(s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid,s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
        need(stamp(before)==stamp(os.fstat(fd))==stamp(os.stat(path,follow_symlinks=False)))
        need(header[:6]==b'\x7fELF\x02\x01' and int.from_bytes(header[18:20],'little') in {62,183})
        actual=digest.hexdigest();exact(actual,expected);return actual
    finally:os.close(fd)

def main():
    parser=argparse.ArgumentParser();parser.add_argument('--receipt');parser.add_argument('--source-only',action='store_true');parser.add_argument('--source-revision',required=True);parser.add_argument('--file-binary');parser.add_argument('--file-binary-sha256');args=parser.parse_args()
    try:
        mark('arguments')
        need(bool(args.receipt) != args.source_only)
        source(ROOT,args.source_revision)
        if args.source_only:
            print(json.dumps(dict(schema='native-directory-transports-source-only-v1',source_revision=args.source_revision,selected_inprocess_matrix_accepted=False,runtime_authority=False)));return 0
        actual_file=file_binary_digest(args.file_binary,args.file_binary_sha256)
        mark('receipt-read')
        data,digest=read_json(args.receipt,True);out=validate(data,file_digest=actual_file);out['source_revision']=args.source_revision;out['source_bound_by_CLI']=True
        out['source_tree']=subprocess.check_output(['git','-C',str(ROOT),'rev-parse','HEAD^{tree}']).decode().strip()
        out['receipt_sha256']=digest
        print(json.dumps(out,sort_keys=True))
    except Exception:
        print(json.dumps(dict(accepted=False,scope='native-directory-transports-v1',failed_check=failed_check()),sort_keys=True));return 1
    return 0
if __name__=='__main__':raise SystemExit(main())

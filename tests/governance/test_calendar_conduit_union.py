"""Exact main/Calendar union controls. Native PHP selector/cleanup, no SQL."""
import hashlib, json, subprocess, sys, unittest
from pathlib import Path
from recovery_oauth_code_test_cohort import complete_source_cohort
ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts/governance'))
import check_recovery_file_parent_policy as driver_admission
MAIN = 'fc0369af9d84c4a5f23a7080528e70f1ce84c4e7'
WIRE = '9710b9edd56b9acf99b7c79b8c7d84669f7f2559'
DRIVER = 'support/governance/native-organization/test-native-organization-mysql.php'
MEDIA_WORKFLOW = '.github/workflows/native-media-session-positive-sql.yml'
DESCRIPTION_WORKFLOW = '.github/workflows/project-description-focused.yml'
WORKFLOW_BASELINE = '46a1b044950584006cba754bc9841031d452eb76'
MEDIA_WORKFLOW_SHA256 = 'd0b00933b908aa5e8da92ca3a1c6c154d7a082adaea9883a762324e4e8a3d786'

def git(*args):
    return subprocess.check_output(['git', '-C', str(ROOT), *args])

def candidate_predecessor_DATA(relative,raw):
    import task_source_operation_context as candidate
    if relative in candidate.PHP86_COMPATIBILITY_PATHS:
        return candidate.php86_compatibility_inverse(relative,raw,(ROOT/candidate.PHP86_COMPATIBILITY_HISTORY).read_bytes())
    return raw
from contextlib import contextmanager

@contextmanager
def _workflow_owner(operation=None):
    from task_inverse_test_api_v2 import owner
    import source_operation_snapshot as source
    if operation is None:
        with owner(ROOT) as current:
            source.require_task_operation(current, ROOT)
            yield current
    else:
        source.require_task_operation(operation, ROOT)
        yield operation

# Exact full-tier continuation DATA; never execute YAML or grant source authority.
PRIVATE_TIER_HEADER=b'on:\n  workflow_call:\n    inputs:\n      expected_head:\n        required: true\n        type: string\n  workflow_dispatch:\n    inputs:\n      expected_head:\n        description: Exact signed source commit for this manual full qualification\n        required: true\n        type: string\n\n'
PRIVATE_PRIOR_HEADER=b"on:\n  pull_request:\n    types: [opened, synchronize, reopened, ready_for_review]\n    paths:\n      - '.github/workflows/private-mail-wire.yml'\n      - 'src/**'\n      - 'resources/**'\n      - 'bin/**'\n      - 'support/**'\n      - 'tests/governance/**'\n      - 'scripts/runtime/**'\n  workflow_dispatch:\n"
PRIVATE_TIER_BINDING=b'      - name: Require exact full-tier caller checkpoint\n        env:\n          FOCUSED_EXPECTED_HEAD: ${{ inputs.expected_head }}\n        run: |\n          python3 -B scripts/ci/focused_workflows.py bind-head --expected-head "$FOCUSED_EXPECTED_HEAD"\n'
PRIVATE_BINDING_ANCHOR=b'      - name: Require public immutable reviewed dependency pins\n'
FULL_TIER_WORKFLOW_PINS={'.github/workflows/container-image.yml': (9212, 'a695d3036f9d355cbf60aae1cfbe80af8796c9dfe278421fd0fe0b4e0a11f04c', 7025, 'aa476d68759dd7bbf38193f585b1480b168a8218dc7d1927d1ea575a5ca1b5a2'), '.github/workflows/gorge-runtime-contracts.yml': (17699, '172e2221d8870aac5a7d3d3cb9fe6d8e4bbe38fb2d154990c7f733d7bdb0f3ba', 14318, '1fac4ce6d96459d41d5f86f618c987b0e9678a180afe19111888a369d623ef77'), '.github/workflows/native-rr-owner-build.yml': (2978, '42c30097c96481a00eddc9800c14ed4c4644bab62b6806684967eba1bac93a50', 3380, '025e5f5d8d9eb73952539c7bf8efb7c66b62c06de5c19aab9b3d9df69d04a432'), '.github/workflows/release-full.yml': (4461, '821c22b1cc8f39b4a99223a774c82eba08bfa1fdbf4c91f35afb4c2b205d009f', None, None), '.github/workflows/php-core-security.yml': (3027, '068c4b1affba81909da33a1da8d93802b20fb312351247fc747bc12e25d9a7e3', 2860, 'c712fc74eea5f742509e50266af8047b604892c4a2523169487afaae5165bcbd'), '.github/workflows/rebuild-phutil-map.yml': (2700, '1df42d1bc93140106fbfc1aa8478f41310d05f1739c8592dee60a72d5189ba10', 2700, 'e3e716164b12a6b6ba4a9654d74e5f55027b57043c30b227448b8f7216c5b758')}
RELEASE_FULL_LITERAL=b"name: Full release qualification\n\non:\n  workflow_call:\n    inputs:\n      expected_head:\n        required: true\n        type: string\n    outputs:\n      qualified_head:\n        description: Exact source which passed every full release job\n        value: ${{ jobs.qualified.outputs.head }}\n\npermissions:\n  contents: read\n\njobs:\n  atomic_feature_regressions:\n    uses: ./.github/workflows/atomic-feature-regressions.yml\n    with:\n      expected_head: ${{ inputs.expected_head }}\n  assessment_credential_focused:\n    uses: ./.github/workflows/assessment-credential-focused.yml\n    with:\n      expected_head: ${{ inputs.expected_head }}\n  conduit_producer_focused:\n    uses: ./.github/workflows/conduit-producer-focused.yml\n    with:\n      expected_head: ${{ inputs.expected_head }}\n  oauth_access_producer_focused:\n    uses: ./.github/workflows/oauth-access-producer-focused.yml\n    with:\n      expected_head: ${{ inputs.expected_head }}\n  oauth_authorization_producer_focused:\n    uses: ./.github/workflows/oauth-authorization-producer-focused.yml\n    with:\n      expected_head: ${{ inputs.expected_head }}\n  oauth_code_producer_focused:\n    uses: ./.github/workflows/oauth-code-producer-focused.yml\n    with:\n      expected_head: ${{ inputs.expected_head }}\n  oauth_client_lifecycle_focused:\n    uses: ./.github/workflows/oauth-client-lifecycle-focused.yml\n    with:\n      expected_head: ${{ inputs.expected_head }}\n  native_directory_transports_v1:\n    uses: ./.github/workflows/native-directory-transports-v1.yml\n    with:\n      expected_head: ${{ inputs.expected_head }}\n  native_media_session_positive_sql:\n    uses: ./.github/workflows/native-media-session-positive-sql.yml\n    with:\n      expected_head: ${{ inputs.expected_head }}\n  native_rr_owner_build:\n    uses: ./.github/workflows/native-rr-owner-build.yml\n    with:\n      expected_head: ${{ inputs.expected_head }}\n  private_mail_wire:\n    uses: ./.github/workflows/private-mail-wire.yml\n    with:\n      expected_head: ${{ inputs.expected_head }}\n  project_content_grants_focused:\n    uses: ./.github/workflows/project-content-grants-focused.yml\n    with:\n      expected_head: ${{ inputs.expected_head }}\n  project_description_focused:\n    uses: ./.github/workflows/project-description-focused.yml\n    with:\n      expected_head: ${{ inputs.expected_head }}\n  qualified:\n    name: Full release qualification gate\n    if: always()\n    needs: [atomic_feature_regressions, assessment_credential_focused, conduit_producer_focused, oauth_access_producer_focused, oauth_authorization_producer_focused, oauth_code_producer_focused, oauth_client_lifecycle_focused, native_directory_transports_v1, native_media_session_positive_sql, native_rr_owner_build, private_mail_wire, project_content_grants_focused, project_description_focused]\n    runs-on: ubuntu-latest\n    outputs:\n      head: ${{ steps.source.outputs.head }}\n    steps:\n      - name: Refuse skipped, cancelled, failed or mismatched full qualification\n        id: source\n        env:\n          EXPECTED_HEAD: ${{ inputs.expected_head }}\n          SOURCE_HEAD: ${{ github.sha }}\n          RESULTS: ${{ toJSON(needs) }}\n        run: |\n          python3 - <<'PY'\n          import json, os, re\n          expected = os.environ['EXPECTED_HEAD']\n          results = json.loads(os.environ['RESULTS'])\n          if not re.fullmatch('[a-f0-9]{40}', expected) or expected != os.environ['SOURCE_HEAD']:\n              raise SystemExit('Full qualification source mismatch.')\n          required = ['atomic_feature_regressions', 'assessment_credential_focused', 'conduit_producer_focused', 'oauth_access_producer_focused', 'oauth_authorization_producer_focused', 'oauth_code_producer_focused', 'oauth_client_lifecycle_focused', 'native_directory_transports_v1', 'native_media_session_positive_sql', 'native_rr_owner_build', 'private_mail_wire', 'project_content_grants_focused', 'project_description_focused']\n          if set(results) != set(required) or any(row['result'] != 'success' for row in results.values()):\n              raise SystemExit('Every full release job must succeed; skips are not acceptance.')\n          if results['atomic_feature_regressions'].get('outputs', {}).get('qualified_head') != expected:\n              raise SystemExit('Atomic full qualification must identify this exact source; core is insufficient.')\n          with open(os.environ['GITHUB_OUTPUT'], 'a') as output:\n              output.write('head=' + expected + '\\n')\n          PY\n"

def reviewed_private_mail_parent(path,raw):
    # Test-only exact pair/toolchain continuation; no source issuer or runtime grant.
    # Exact current security baseline; the old version is inert comparison DATA.
    if path!='.github/workflows/private-mail-wire.yml' or type(raw)is not bytes or len(raw)!=5143 or hashlib.sha256(raw).hexdigest()!='c56ec593dfbf075e091e2cb1d1124f6f7419742eec3307fcc477883e7b24f5ec':
        raise ValueError('unreviewed_private_mail_security_baseline')
    current=raw
    raw=raw.replace(b'8.5.13',b'8.5.12')
    if raw.replace(b'8.5.12',b'8.5.13')!=current:
        raise ValueError('unreviewed_private_mail_security_roundtrip')
    if path!='.github/workflows/private-mail-wire.yml'or type(raw)is not bytes or len(raw)!=5143 or hashlib.sha256(raw).hexdigest()!='44e4b0d12459cfebe51c24b60febb7204324c5cadbb3d3cefb7ae85ce0328cdf':
        raise ValueError('unreviewed_private_mail_workflow')
    physical=raw
    for current,prior in ((PRIVATE_TIER_HEADER,PRIVATE_PRIOR_HEADER),(PRIVATE_TIER_BINDING,b'')):
        if raw.count(current)!=1:raise ValueError('unreviewed_private_mail_full_tier_binding')
        raw=raw.replace(current,prior,1)
    if len(raw)!=4914 or hashlib.sha256(raw).hexdigest()!='daa78d081d7e3820d85b8e8dc7e66f9db85fd87e53de7e46ef1c69ca1f4a0dbb':
        raise ValueError('unreviewed_private_mail_pre_tier_DATA')
    if raw.count(PRIVATE_PRIOR_HEADER)!=1 or raw.count(PRIVATE_BINDING_ANCHOR)!=1:
        raise ValueError('unreviewed_private_mail_roundtrip_anchor')
    forward=raw.replace(PRIVATE_PRIOR_HEADER,PRIVATE_TIER_HEADER,1).replace(PRIVATE_BINDING_ANCHOR,PRIVATE_TIER_BINDING+PRIVATE_BINDING_ANCHOR,1)
    if forward!=physical:raise ValueError('unreviewed_private_mail_full_tier_roundtrip')
    edits=((b'GORGE_MAIL_WIRE_REF: 16318f87a017f82c28f3011b362364cf24b73ef8',b'GORGE_MAIL_WIRE_REF: 83351b0e7eba38e50ff076ba6763ca68ebc57e1b'),(b"go-version: '1.27.4'",b"go-version: '1.27.3'"))
    for current,prior in edits:
        if raw.count(current)!=1:raise ValueError('unreviewed_private_mail_workflow')
        raw=raw.replace(current,prior,1)
    if len(raw)!=4914 or hashlib.sha256(raw).hexdigest()!='d1c90f5e6d047bf6227504b7595600a3a4c813c98b5f6314b601d4b820aae6a1':raise ValueError('unreviewed_private_mail_workflow')
    return raw

def reviewed_full_tier_parent(path,raw,parent,history_raw):
    if type(path)is not str or path not in FULL_TIER_WORKFLOW_PINS or type(raw)is not bytes:
        raise ValueError('unreviewed_full_tier_workflow')
    size,digest,parent_size,parent_digest=FULL_TIER_WORKFLOW_PINS[path]
    if len(raw)!=size or hashlib.sha256(raw).hexdigest()!=digest:
        raise ValueError('unreviewed_full_tier_current_bytes')
    if path=='.github/workflows/release-full.yml':
        # A complete fixed literal addition inverses to an absent parent. It is
        # inert DATA; this never dispatches the workflow or executes its YAML.
        if parent is not None or raw!=RELEASE_FULL_LITERAL or raw.count(RELEASE_FULL_LITERAL)!=1:
            raise ValueError('unreviewed_full_tier_addition')
        target=raw.replace(RELEASE_FULL_LITERAL,b'',1)
        if target!=b'' or target+RELEASE_FULL_LITERAL!=raw:
            raise ValueError('unreviewed_full_tier_addition_roundtrip')
        return None
    if type(parent)is not bytes or len(parent)!=parent_size or hashlib.sha256(parent).hexdigest()!=parent_digest:
        raise ValueError('unreviewed_full_tier_parent_DATA')
    import task_source_operation_context as history
    doc=history.load_release_history(history_raw);row=doc['files'][path]
    if (row['current_bytes'],row['current_sha256'],row['target_bytes'],row['target_sha256'])!=(size,digest,parent_size,parent_digest):
        raise ValueError('unreviewed_full_tier_history_role')
    target=history.release_history_inverse(path,raw,doc)
    if target!=parent or history.release_history_roundtrip(path,target,doc)!=raw:
        raise ValueError('unreviewed_full_tier_parent_roundtrip')
    return target

def validate_workflow_union(changed, historical, read, *, _operation=None):
    with _workflow_owner(_operation) as operation:
        current_read = read

        def read(path):
            current_raw = current_read(path)
            if type(current_raw) is not bytes or current_raw != operation._task_contexts[ROOT].current(path):
                raise ValueError('unknown_Task_workflow_union_bytes')
            return operation._task_contexts[ROOT].parent(path)
        import project_content_grant_ci_source_delta as n05_ci
        n05_current = operation._modules['project_description_admission_delta']
        n05_current.assert_current_recipe(ROOT)
        actual = n05_current.description.checked_bytes(ROOT / DESCRIPTION_WORKFLOW)
        if (ROOT / DESCRIPTION_WORKFLOW).stat().st_mode & 4095 != 420 or read(DESCRIPTION_WORKFLOW) != actual or git('ls-tree', WORKFLOW_BASELINE, '--', DESCRIPTION_WORKFLOW) != b'':
            raise ValueError('unreviewed description workflow')
        import recovery_oauth_code_ci_delta as code_ci
        import recovery_oauth_authorization_ci_delta as authorization_ci
        import recovery_oauth_client_lifecycle_ci_delta as lifecycle_ci
        changed = set(changed)
        # The additional candidate workflow is checked as a signed current leaf,
        # not counted as historical Calendar coverage or release qualification.
        candidate_path='.github/workflows/php86-isolated-compatibility.yml'
        if candidate_path in changed:
            import task_source_operation_context as candidate
            context=operation._task_contexts[ROOT]
            physical=current_read(candidate_path);pin=candidate.PHP86_COMPATIBILITY_NEW_PINS[candidate_path]
            if (type(physical)is not bytes or physical!=context.current(candidate_path)or
                len(physical)!=pin['bytes']or hashlib.sha256(physical).hexdigest()!=pin['sha256']or
                context.parent(candidate_path)is not None or
                git('ls-tree',WORKFLOW_BASELINE,'--',candidate_path)!=b''):
                raise ValueError('unknown_candidate_workflow_current_leaf')
            changed.remove(candidate_path)
        private_mail='.github/workflows/private-mail-wire.yml'
        if private_mail in changed:
            physical=current_read(private_mail);context=operation._task_contexts[ROOT]
            if type(physical)is not bytes or physical!=context.current(private_mail):raise ValueError('unknown_private_mail_current_bytes')
            parent=reviewed_private_mail_parent(private_mail,physical)
            if parent!=context.parent(private_mail)or parent!=git('show',WORKFLOW_BASELINE+':'+private_mail):raise ValueError('unknown_private_mail_parent_DATA')
            changed.remove(private_mail)
        conduit = '.github/workflows/conduit-producer-focused.yml'
        if conduit in changed:
            context = operation._task_contexts[ROOT]
            if conduit not in context.ci_inverse:
                raise ValueError('unreviewed_Task_Conduit_workflow')
            physical = current_read(conduit)
            if type(physical) is not bytes or physical != context.current(conduit):
                raise ValueError('unknown_Task_Conduit_workflow_bytes')
            parent = context.parent(conduit)
            if physical == parent or parent != git('show', WORKFLOW_BASELINE + ':' + conduit):
                raise ValueError('unknown_Conduit_parent_union_baseline')
            changed.remove(conduit)
        full=set(FULL_TIER_WORKFLOW_PINS)
        if not full.issubset(changed):raise ValueError('missing_reviewed_full_tier_workflow')
        context=operation._task_contexts[ROOT]
        history_raw=context.current('support/governance/source-operation/task-release-source-history-v1.json')
        for path in sorted(full):
            physical=current_read(path)
            if type(physical)is not bytes or physical!=context.current(path):
                raise ValueError('unknown_full_tier_current_bytes')
            parent=context.parent(path);restored=reviewed_full_tier_parent(path,physical,parent,history_raw)
            if restored is None:
                if git('ls-tree',WORKFLOW_BASELINE,'--',path)!=b'':raise ValueError('unreviewed_full_tier_parent_absence')
            elif restored!=git('show',WORKFLOW_BASELINE+':'+path):
                raise ValueError('unreviewed_full_tier_baseline_DATA')
            changed.remove(path)
        if set(changed) != set(historical) | {'.github/workflows/native-directory-transports-v1.yml', MEDIA_WORKFLOW, code_ci.WORKFLOW, authorization_ci.WORKFLOW, lifecycle_ci.WORKFLOW, n05_ci.WORKFLOW, DESCRIPTION_WORKFLOW}:
            raise ValueError('unreviewed workflow union')
        if hashlib.sha256(read(MEDIA_WORKFLOW)).hexdigest() != MEDIA_WORKFLOW_SHA256:
            raise ValueError('reviewed media workflow changed')
        code_ci.assert_workflow(code_ci.WORKFLOW, read(code_ci.WORKFLOW))
        authorization_ci.assert_workflow(authorization_ci.WORKFLOW, read(authorization_ci.WORKFLOW))
        lifecycle_ci.assert_workflow(lifecycle_ci.WORKFLOW, read(lifecycle_ci.WORKFLOW))
        current_n05 = read(n05_ci.WORKFLOW)
        if current_n05 != n05_current.description.checked_bytes(ROOT / n05_ci.WORKFLOW):
            raise ValueError('unreviewed current content-grants workflow')
        n05_current.assert_workflow(n05_ci.WORKFLOW, current_n05)

def historical_atomic_source(path='.github/workflows/atomic-feature-regressions.yml', raw=None, *, _operation=None):
    with _workflow_owner(_operation) as operation:
        current = operation._modules['project_description_admission_delta']
        import check_recovery_conduit_producer as conduit_gate
        current.assert_current_recipe(ROOT)
        producer = 'scripts/governance/check_recovery_conduit_producer.py'
        if path != '.github/workflows/atomic-feature-regressions.yml' or path not in current.CURRENT_METADATA or producer not in current.CURRENT_METADATA:
            raise ValueError('unreviewed Atomic historical source role')
        if Path(conduit_gate.__file__).resolve() != ROOT / producer:
            raise ValueError('unreviewed current producer source role')
        if (ROOT / producer).stat().st_mode & 4095 != 420:
            raise ValueError('unknown current producer mode')
        producer_raw = current.description.checked_bytes(ROOT / producer)
        pin = current.CURRENT_METADATA[producer]
        if len(producer_raw) != pin['bytes'] or hashlib.sha256(producer_raw).hexdigest() != pin['sha256']:
            raise ValueError('unknown current producer bytes')
        if (ROOT / path).stat().st_mode & 4095 != 420:
            raise ValueError('unknown current Atomic source mode')
        physical = operation._task_contexts[ROOT].current(path)
        if raw is not None and (type(raw) is not bytes or raw != physical):
            raise ValueError('unknown current Atomic source bytes')
        actual = current.description.checked_bytes(ROOT / path)
        raw = actual
        if type(raw) is not bytes or raw != actual:
            raise ValueError('unknown current Atomic source bytes')
        prior = current._normalize_metadata(path, raw)
        expected = subprocess.check_output(['/usr/bin/git', '--no-replace-objects', '-C', str(ROOT), 'show', '20b138b4cd9a2cf61522613b4a8e5e21eca7341b:' + path])
        if prior != expected:
            raise ValueError('unknown Atomic historical stage')
        return prior.decode('utf-8')

def _calendar_source_context(operation):
    import source_operation_snapshot as source
    source.require_task_operation(operation, ROOT)
    if operation.code_root != ROOT or operation.producer_root != ROOT or source.ROOT != ROOT:
        raise ValueError('unknown Calendar source owner role')
    return operation._task_contexts[ROOT]

def _current_native_driver_prior(operation, root, raw):
    import source_operation_snapshot as source
    source.require_task_operation(operation, root)
    root = Path(root)
    if root != ROOT or operation.code_root != ROOT or operation.producer_root != ROOT:
        raise ValueError('unknown Calendar source owner role')
    from types import MappingProxyType
    current = operation._modules['project_description_admission_delta']
    context = _calendar_source_context(operation)
    try:
        if not root.is_absolute() or root.resolve() != root:
            raise ValueError('native driver source root refused')
        current.assert_current_recipe(root)
        target = root / DRIVER
        if type(raw) is not bytes or raw != context.current(DRIVER) or raw != driver_admission.read_native_driver_checked(target):
            raise ValueError('unknown physical native driver source')
        registry_path = 'support/governance/source-operation/registry-v1.json'
        registry_raw = context.current(registry_path)
        if registry_path not in current.CURRENT_METADATA:
            raise ValueError('native driver registry role missing')
        current._exact(registry_raw, current.CURRENT_METADATA[registry_path])

        def pairs(items):
            result = {}
            for (key, value) in items:
                if key in result:
                    raise ValueError('duplicate native driver source role')
                result[key] = value
            return result
        registry = json.loads(registry_raw, object_pairs_hook=pairs)
        if type(registry) is not dict or registry.get('schema') != 'source-operation-registry-v1' or registry.get('source_revision') != driver_admission.NATIVE_DRIVER_TARGET_ORIGIN['revision'] or (registry.get('source_tree') != driver_admission.NATIVE_DRIVER_TARGET_ORIGIN['tree']) or (type(registry.get('entries')) is not dict) or (type(registry.get('successor_entries')) is not dict):
            raise ValueError('native driver registry origin refused')
        pin = registry['successor_entries'][DRIVER]['current'] if DRIVER in registry['successor_entries'] else registry['entries'][DRIVER]
        if type(pin) is not dict or set(pin) != {'bytes', 'sha256'} or type(pin['bytes']) is not int or (type(pin['sha256']) is not str):
            raise ValueError('native driver source role refused')
        current._exact(raw, pin)
        if hashlib.sha256(raw).hexdigest() == driver_admission.NATIVE_DRIVER_TARGET_PIN['sha256']:
            prior = driver_admission._native_driver_prior(raw)
        else:
            row = current.CURRENT_METADATA.get(DRIVER)
            if type(row) not in (dict, MappingProxyType) or row.get('target_bytes') != driver_admission.NATIVE_DRIVER_TARGET_PIN['bytes'] or row.get('target_sha256') != driver_admission.NATIVE_DRIVER_TARGET_PIN['sha256']:
                raise ValueError('native driver current metadata role missing')
            current._exact(raw, row)
            prior = driver_admission._native_driver_prior(current._normalize_metadata(DRIVER, raw))
        current.assert_current_recipe(root)
        return prior
    except OSError:
        raise ValueError('native driver source unavailable') from None

def _fixture_recipe_paths(operation):
    import source_operation_snapshot as source
    _calendar_source_context(operation)
    n05 = operation._modules['project_description_admission_delta']
    delta = operation._modules['recovery_oauth_code_source_delta']
    partial = operation._modules['recovery_partial_current_schema_delta']
    authorization = operation._modules['recovery_oauth_authorization_source_delta']
    n05.assert_current_recipe(ROOT)
    required = set(n05.description.CURRENT_PINS) | set(n05.CURRENT_METADATA) | {n05.MANIFEST, n05.BRIDGE, n05.PREPARATION, n05.description.LEGACY_REDUCER['path'], 'scripts/governance/project_content_grant_ci_source_delta.py'}
    extras = {**delta.SOURCE_INPUTS, **partial.SOURCE_INPUTS, **{p: authorization.BASE_PINS[p] for p in authorization.PRODUCTION_PATHS}}
    return (required | set(extras), extras)

def _supply_parent_fixture(operation, root):
    (required, extras) = _fixture_recipe_paths(operation)
    from recovery_oauth_code_task_test_cohort_v2 import _supply_missing
    context = operation._task_contexts[ROOT]
    authorization = operation._modules['recovery_oauth_authorization_source_delta']
    root = Path(root)
    for name in sorted(required):
        raw = context.parent(name)
        if type(raw) is not bytes:
            raise ValueError('Calendar parent fixture DATA missing')
        if name in extras and hashlib.sha256(authorization.reduce_if_selected(name, raw)).hexdigest() != extras[name]:
            raise ValueError('Calendar parent fixture cohort changed')
        _supply_missing(root, name, raw, context.parent_pins[name]['mode'])

def _fixture_SQL_obligations(operation, root, module):
    import ast, re
    import source_operation_snapshot as source
    context = _calendar_source_context(operation)
    relative = 'scripts/governance/check_recovery_oauth_access_producer_v3.py'
    if Path(module.__file__).resolve() != ROOT / relative:
        raise ValueError('Calendar DATA checker source role refused')
    code = context.current(relative)
    if hashlib.sha256(code).hexdigest() != 'c24ea6004b27f1722dd30c45cae0f34d1b243012f4db3a2f33b648e6ac173082':
        raise ValueError('Calendar DATA checker source changed')
    pins = next((ast.literal_eval(n.value) for n in ast.parse(code).body if isinstance(n, ast.Assign) and any((isinstance(k, ast.Name) and k.id == 'SOURCE_INPUTS' for k in n.targets))))
    if module.SOURCE_INPUTS != pins:
        raise ValueError('Calendar DATA checker pins changed')
    root = Path(root)
    if root == ROOT or root.resolve() != root or (not root.is_dir()):
        raise ValueError('Calendar fixture DATA root refused')
    (required, extras) = _fixture_recipe_paths(operation)
    required |= set(pins)
    terminal = operation._modules['recovery_worker_terminal_delta']
    coordinator = operation._modules['recovery_oauth_code_coordinator_delta']
    calendar = operation._modules['recovery_conduit_source_delta']
    for name in sorted(required):
        raw = module.read_source_checked(root / name)
        expected = context.parent(name)
        if type(expected) is not bytes:
            raise ValueError('Calendar fixture DATA reference missing')
        if name == terminal.PATH:
            if terminal.reduce_if_selected(name, raw) != terminal.reduce_if_selected(name, expected):
                raise ValueError('Calendar fixture worker DATA changed')
        elif raw != expected:
            raise ValueError('Calendar fixture DATA cohort changed')
    captured = {}
    for (relative, pin) in module.SOURCE_INPUTS.items():
        raw = terminal.reduce_if_selected(relative, coordinator.source_view(relative, module.read_source_checked(root / relative)))
        if relative == DRIVER and hashlib.sha256(raw).hexdigest() != pin:
            raw = calendar.reduce_calendar_wire_union(raw)
        if hashlib.sha256(raw).hexdigest() != pin:
            raise ValueError('reviewed borrowed source changed')
        captured[relative] = raw
    text = captured['support/governance/recovery/oauth-access-cases.php'].decode('utf-8')
    prefixes = re.findall("'(borrowed-oauth-access-[A-Za-z0-9-]+-)'", text)
    prefixes = {p for p in prefixes if p.endswith(('owner-', 'resumes-', 'binding-', 'InnoDB-'))}
    if len(prefixes) != 4:
        raise ValueError('borrowed source obligations changed')
    return {p + ending for p in prefixes for ending in ('commit', 'rollback')}

class CalendarConduitUnion(unittest.TestCase):
    def test_release_php_setup_exact_continuation_rejects_mutation(self):
        import task_source_operation_context as history
        name = '.github/workflows/container-image.yml'
        raw = (ROOT / name).read_bytes()
        history_raw = (ROOT / history.RELEASE_HISTORY).read_bytes()
        doc = history.load_release_history(history_raw)
        parent = history.release_history_inverse(name, raw, doc)
        self.assertEqual(reviewed_full_tier_parent(name, raw, parent, history_raw), parent)
        self.assertEqual(hashlib.sha256(parent).hexdigest(),
                         'aa476d68759dd7bbf38193f585b1480b168a8218dc7d1927d1ea575a5ca1b5a2')
        for changed in (raw.replace(b"php-version: '8.5.13'", b"php-version: '8.5.12'", 1),
                        raw.replace(b'extensions: opcache', b'extensions: none', 1),
                        raw.replace(b'      - name: Set up PHP for release predicate tests',
                                    b'      - name: Unreviewed setup', 1)):
            with self.subTest(changed=hashlib.sha256(changed).hexdigest()):
                self.assertNotEqual(changed, raw)
                with self.assertRaisesRegex(ValueError, 'unreviewed_full_tier_current_bytes'):
                    reviewed_full_tier_parent(name, changed, parent, history_raw)


    def test_current_Atomic_history_refuses_unknown_names_bytes_and_producer_role(self):
        with _workflow_owner() as operation:
            from unittest.mock import patch
            import check_recovery_conduit_producer as conduit_gate
            path = '.github/workflows/atomic-feature-regressions.yml'
            raw = (ROOT / path).read_bytes()
            old = subprocess.check_output(['/usr/bin/git', '--no-replace-objects', '-C', str(ROOT), 'show', '20b138b4cd9a2cf61522613b4a8e5e21eca7341b:' + path])
            self.assertEqual(historical_atomic_source(_operation=operation).encode(), old)
            for (name, body) in [('.github/workflows/unreviewed.yml', raw), (path, raw + b'\n# unknown'), (path, raw.decode())]:
                with self.subTest(name=name), self.assertRaises(ValueError):
                    historical_atomic_source(name, body, _operation=operation)
            if raw != old:
                with self.assertRaises(ValueError):
                    historical_atomic_source(path, old, _operation=operation)
            with patch.object(conduit_gate, '__file__', str(ROOT / 'scripts/governance/unreviewed.py')):
                with self.assertRaises(ValueError):
                    historical_atomic_source(_operation=operation)

    def test_current_main_production_hooks_map_and_workflows_preserved(self):
        from test_project_description_source_inverse_task_v2 import reviewed_i18n_sources, assert_reviewed_i18n_drift_controls
        assert_reviewed_i18n_drift_controls(self)
        with _workflow_owner() as operation:
            context = operation._task_contexts[ROOT]
            sys.path.insert(0, str(ROOT / 'scripts/governance'))
            assessment = operation._modules['recovery_assessment_source_delta']
            previous = '46a1b044950584006cba754bc9841031d452eb76'
            changed = set(git('diff', '--name-only', previous, '--', 'src', 'resources').decode().splitlines())
            code_delta = operation._modules['recovery_oauth_code_source_delta']
            authorization_delta = operation._modules['recovery_oauth_authorization_source_delta']
            lifecycle_delta = operation._modules['recovery_oauth_client_lifecycle_delta']
            n05_delta = operation._modules['project_description_admission_delta']
            n05_delta.assert_production_cohort(ROOT)
            from task_historical_views_test_api_v2 import retired_source_DATA
            retirement = json.loads(context.current('docs/operations/retirement/wiki.json'))
            all_retired = {row['path'] for row in retirement['removed_files']}
            self.assertEqual(len(all_retired), 68)
            retired = {path for path in all_retired if path.startswith(('src/', 'resources/'))}
            self.assertTrue(retired <= changed)
            for path in retired:
                self.assertFalse((ROOT / path).exists())
                self.assertEqual(retired_source_DATA(context, path), git('show', previous + ':' + path))
            active = changed - retired
            (changed, reviewed) = operation.historical_task_inputs(ROOT, previous, active, {path: (ROOT / path).read_bytes() for path in active})
            i18n = reviewed_i18n_sources(ROOT)
            self.assertEqual(changed, set(assessment.SOURCE_INPUTS) | set(code_delta.SOURCE_INPUTS) | set(authorization_delta.PRODUCTION_PATHS) | set(lifecycle_delta.PRODUCTION_PATHS) | set(i18n))
            for path in changed:
                if path in i18n:
                    self.assertEqual(reviewed[path], (ROOT / path).read_bytes())
                else:
                    self.assertEqual(assessment.reduce_current(path, reviewed[path]), git('show', previous + ':' + path))
            workflows = {'.github/workflows/atomic-feature-regressions.yml', '.github/workflows/oauth-access-producer-focused.yml', '.github/workflows/assessment-credential-focused.yml'}
            validate_workflow_union(git('diff', '--name-only', previous, '--', '.github/workflows').decode().splitlines(), workflows, lambda path: (ROOT / path).read_bytes(), _operation=operation)
            candidate_path='.github/workflows/php86-isolated-compatibility.yml'
            changed=git('diff','--name-only',previous,'--','.github/workflows').decode().splitlines()
            with self.assertRaises(ValueError):
                validate_workflow_union(changed,workflows,lambda path:(ROOT/path).read_bytes()+b'\n'if path==candidate_path else(ROOT/path).read_bytes(),_operation=operation)
            self.assertEqual(git('ls-tree', previous, '--', MEDIA_WORKFLOW), b'')
            for path in workflows - {'.github/workflows/assessment-credential-focused.yml'}:
                raw = historical_atomic_source(_operation=operation).encode() if path == '.github/workflows/atomic-feature-regressions.yml' else operation._task_contexts[ROOT].parent(path)
                needle = b'check_recovery_oauth_access_producer_v3.py'
                self.assertEqual(raw.count(needle), 1)
                self.assertEqual(raw.replace(needle, b'check_recovery_oauth_access_producer_v2.py'), git('show', previous + ':' + path))
            for path in ['support/governance/recovery/conduit-producer.php', 'support/governance/recovery/conduit-focused.php', 'support/governance/recovery/conduit-cases.php', 'support/governance/recovery/conduit-diagnostic.php', 'support/governance/native-organization/native-worker.php', 'support/governance/native-organization/workers.php']:
                raw = (ROOT / path).read_bytes()
                if path == 'support/governance/native-organization/workers.php':
                    sys.path.insert(0, str(ROOT / 'scripts/governance'))
                    import recovery_worker_terminal_delta as worker_delta
                    raw = worker_delta.reduce_current(path, raw)
                self.assertEqual(raw, git('show', MAIN + ':' + path))

    def test_private_mail_pair_toolchain_continuation_is_exact(self):
        with _workflow_owner()as operation:
            path='.github/workflows/private-mail-wire.yml';raw=(ROOT/path).read_bytes();context=operation._task_contexts[ROOT]
            self.assertEqual(reviewed_private_mail_parent(path,raw),context.parent(path))
            historical={'.github/workflows/atomic-feature-regressions.yml','.github/workflows/oauth-access-producer-focused.yml','.github/workflows/assessment-credential-focused.yml'}
            changed=git('diff','--name-only',WORKFLOW_BASELINE,'--','.github/workflows').decode().splitlines()
            validate_workflow_union(changed,historical,lambda name:(ROOT/name).read_bytes(),_operation=operation)
            for name,body in [(MEDIA_WORKFLOW,raw),('tests/atomic/build_gorge.py',raw),(path,raw+b'\n'),(path,context.parent(path)),(path,raw.replace(b"go-version: '1.27.4'",b"go-version: '1.27.3'")),(path,raw.replace(b'16318f87a017f82c28f3011b362364cf24b73ef8',b'a'*40))]:
                with self.subTest(name=name),self.assertRaises(ValueError):reviewed_private_mail_parent(name,body)
            with self.assertRaises(ValueError):validate_workflow_union(changed,historical,lambda name:raw+b'\n'if name==path else(ROOT/name).read_bytes(),_operation=operation)
            for body in (raw.replace(b'workflow_call:',b'pull_request:',1),
                         raw.replace(b'FOCUSED_EXPECTED_HEAD: ${{ inputs.expected_head }}',b'FOCUSED_EXPECTED_HEAD: ${{ github.sha }}',1),
                         raw.replace(PRIVATE_TIER_BINDING,b'',1)):
                with self.assertRaises(ValueError):reviewed_private_mail_parent(path,body)
            history_raw=context.current('support/governance/source-operation/task-release-source-history-v1.json')
            for name in FULL_TIER_WORKFLOW_PINS:
                current=context.current(name);parent=context.parent(name)
                self.assertEqual(reviewed_full_tier_parent(name,current,parent,history_raw),parent)
                for body,prior in ((current+b'unknown',parent),(b'!'+current[1:],parent),(current,b'wrong-parent')):
                    with self.assertRaises(ValueError):reviewed_full_tier_parent(name,body,prior,history_raw)
            with self.assertRaises(ValueError):reviewed_full_tier_parent('.github/workflows/unknown.yml',raw,None,history_raw)


    def test_reviewed_media_workflow_union_rejects_unknown_addition_or_mutation(self):
        with _workflow_owner() as operation:
            historical = {'.github/workflows/atomic-feature-regressions.yml', '.github/workflows/oauth-access-producer-focused.yml', '.github/workflows/assessment-credential-focused.yml'}
            import recovery_oauth_code_ci_delta as code_ci
            import project_content_grant_ci_source_delta as n05_ci
            expected = historical | {'.github/workflows/native-directory-transports-v1.yml', MEDIA_WORKFLOW, code_ci.WORKFLOW, '.github/workflows/oauth-authorization-producer-focused.yml', '.github/workflows/oauth-client-lifecycle-focused.yml', n05_ci.WORKFLOW, DESCRIPTION_WORKFLOW}
            expected |= set(FULL_TIER_WORKFLOW_PINS)
            raw = (ROOT / MEDIA_WORKFLOW).read_bytes()
            validate_workflow_union(expected, historical, lambda path: (ROOT / path).read_bytes(), _operation=operation)
            for changed in (expected | {'.github/workflows/unreviewed.yml'}, expected - {MEDIA_WORKFLOW}, expected - {DESCRIPTION_WORKFLOW}, *(expected-{p}for p in FULL_TIER_WORKFLOW_PINS)):
                with self.assertRaises(ValueError):
                    validate_workflow_union(changed, historical, lambda path: (ROOT / path).read_bytes(), _operation=operation)
            with self.assertRaises(ValueError):
                validate_workflow_union(expected, historical, lambda path: raw + b'\n# unreviewed mutation\n', _operation=operation)

    def test_exact_description_workflow_and_existing_hook_mutations_are_refused(self):
        with _workflow_owner() as operation:
            import recovery_oauth_code_ci_delta as code_ci
            import project_content_grant_ci_source_delta as n05_ci
            n05_current = operation._modules['project_description_admission_delta']
            historical = {'.github/workflows/atomic-feature-regressions.yml', '.github/workflows/oauth-access-producer-focused.yml', '.github/workflows/assessment-credential-focused.yml'}
            changed = git('diff', '--name-only', WORKFLOW_BASELINE, '--', '.github/workflows').decode().splitlines()
            for target in (DESCRIPTION_WORKFLOW, code_ci.WORKFLOW, n05_ci.WORKFLOW):

                def mutated(path):
                    raw = (ROOT / path).read_bytes()
                    return raw + b'\n# unknown-workflow-mutation\n' if path == target else raw
                with self.subTest(path=target), self.assertRaises(ValueError):
                    validate_workflow_union(changed, historical, mutated, _operation=operation)

            def old_target(path):
                return n05_current._target_bytes(path) if path == n05_ci.WORKFLOW else (ROOT / path).read_bytes()
            with self.assertRaises(ValueError):
                validate_workflow_union(changed, historical, old_target, _operation=operation)

    def test_signed_Calendar_fixture_files_are_exact_and_not_reimplemented(self):
        with _workflow_owner() as operation:
            context = operation._task_contexts[ROOT]
            paths = git('diff', '--name-only', 'c496e2cf0ae25fb3e5153c6a1070eb53cf56b504', WIRE, '--', 'support/governance/notification').decode().splitlines()
            self.assertGreater(len(paths), 10)
            for path in paths:
                context.current(path)
                self.assertEqual((ROOT / path).read_bytes(), git('show', WIRE + ':' + path))

    def test_exact_dual_profile_inverse_and_unknown_mutation_refusal(self):
        with _workflow_owner() as operation:
            context = operation._task_contexts[ROOT]
            sys.path.insert(0, str(ROOT / 'scripts/governance'))
            import recovery_conduit_source_delta as delta
            import check_private_calendar_reminders as calendar
            raw = _current_native_driver_prior(operation, ROOT, (ROOT / DRIVER).read_bytes())
            self.assertEqual(delta.reduce_calendar_wire_union(raw), git('show', MAIN + ':' + DRIVER))
            self.assertEqual(delta.reduce_driver(raw), git('show', 'c496e2cf0ae25fb3e5153c6a1070eb53cf56b504:' + DRIVER))
            self.assertEqual(calendar.reduce_reviewed_calendar_wire_driver(raw), delta.reduce_driver(raw))
            for addition in delta.CALENDAR_WIRE_DRIVER_ADDITIONS:
                changed = raw.replace(addition, b'', 1)
                old = delta.DRIVER_SHA256
                try:
                    delta.DRIVER_SHA256 = hashlib.sha256(changed).hexdigest()
                    with self.assertRaises(ValueError):
                        delta.reduce_driver(changed)
                finally:
                    delta.DRIVER_SHA256 = old

    def test_OAuth_current_and_historical_consumers_keep_manifests_and_reject_driver_tamper(self):
        with _workflow_owner() as operation:
            context = operation._task_contexts[ROOT]
            import ast, tempfile
            sys.path.insert(0, str(ROOT / 'scripts/governance'))
            import check_recovery_oauth_access_producer_v3 as current
            import check_recovery_oauth_access_producer as historical
            self.assertEqual((50, 8, 18), (len(current.SOURCE_INPUTS), len(current.SQL_obligations(ROOT, _source_operation=operation)), len(current.obligations(ROOT, _source_operation=operation))))
            import check_recovery_oauth_access_producer_v2 as historical_v2
            for module in (historical, historical_v2):
                path = 'scripts/governance/' + module.__name__ + '.py'

                def pins(raw):
                    return next((ast.literal_eval(x.value) for x in ast.parse(raw).body if isinstance(x, ast.Assign) and any((isinstance(t, ast.Name) and t.id == 'SOURCE_INPUTS' for t in x.targets))))
                n05_delta = operation._modules['project_description_admission_delta']
                self.assertEqual(pins(n05_delta.reduce_if_selected(path, context.parent(path))), pins(git('show', '46a1b044950584006cba754bc9841031d452eb76:' + path)))
            with tempfile.TemporaryDirectory() as tmp:
                root = Path(tmp).resolve()
                for n in current.SOURCE_INPUTS:
                    p = root / n
                    p.parent.mkdir(parents=True, exist_ok=True)
                    p.write_bytes(context.parent(n))
                    p.chmod(384)
                _supply_parent_fixture(operation, root)
                self.assertEqual(_fixture_SQL_obligations(operation, root, current), current.SQL_obligations(ROOT, _source_operation=operation))
                p = root / DRIVER
                p.write_bytes(p.read_bytes() + b'// unrelated edit')
                with self.assertRaises(ValueError):
                    _fixture_SQL_obligations(operation, root, current)

    def test_worker_terminal_layer_composes_without_changing_Calendar_or_historical_pins(self):
        with _workflow_owner() as operation:
            context = operation._task_contexts[ROOT]
            import ast
            sys.path.insert(0, str(ROOT / 'scripts/governance'))
            import recovery_worker_terminal_delta as worker
            for name in ('check_recovery_session_lease', 'check_recovery_borrowed_session', 'check_recovery_conduit_producer'):
                gate = __import__(name)
                path = 'scripts/governance/' + name + '.py'

                def pins(raw):
                    return next((ast.literal_eval(n.value) for n in ast.parse(raw).body if isinstance(n, ast.Assign) and any((isinstance(t, ast.Name) and t.id == 'SOURCE_INPUTS' for t in n.targets))))
                assessment = operation._modules['recovery_assessment_source_delta']
                n05_delta = operation._modules['project_description_admission_delta']
                historical = pins(n05_delta.reduce_if_selected(path, context.parent(path)))
                for (source, pin) in assessment.SOURCE_INPUTS.items():
                    if source in historical:
                        self.assertEqual(historical[source], pin)
                        self.assertEqual(hashlib.sha256(assessment.reduce_current(source, context.parent(source))).hexdigest(), assessment.BASE_PINS[source])
                        historical[source] = assessment.BASE_PINS[source]
                self.assertEqual(historical, pins(git('show', '732dd79049f017becc8b05d1c3fd0852ed00ff44:' + path)))
                import recovery_conduit_source_delta as calendar
                self.assertEqual(gate.SOURCE_INPUTS[DRIVER], hashlib.sha256(calendar.reduce_policy_cleanup_driver(_current_native_driver_prior(operation, ROOT, (ROOT / DRIVER).read_bytes()))).hexdigest())
                self.assertTrue(gate.SQL_obligations(ROOT, _source_operation=operation))
            self.assertEqual(worker.reduce_current(worker.PATH, candidate_predecessor_DATA(worker.PATH,(ROOT / worker.PATH).read_bytes())), git('show', MAIN + ':' + worker.PATH))

    def test_OAuth_worker_source_view_accepts_only_exact_old_or_terminal_inverse(self):
        with _workflow_owner() as operation:
            context = operation._task_contexts[ROOT]
            import tempfile
            sys.path.insert(0, str(ROOT / 'scripts/governance'))
            import check_recovery_oauth_access_producer as old
            import check_recovery_oauth_access_producer_v3 as current
            import check_recovery_conduit_producer as conduit
            import recovery_worker_terminal_delta as worker
            actual = candidate_predecessor_DATA(worker.PATH,(ROOT / worker.PATH).read_bytes())
            prior = git('show', MAIN + ':' + worker.PATH)
            for module in (old, current):
                self.assertEqual(module.worker_terminal.reduce_if_selected(worker.PATH, actual), prior)
                self.assertEqual(module.worker_terminal.reduce_if_selected(worker.PATH, prior), prior)
                with self.assertRaises(ValueError):
                    module.worker_terminal.reduce_if_selected(worker.PATH, actual + b'// other change')
            self.assertEqual((len(current.SQL_obligations(ROOT, _source_operation=operation)), len(conduit.SQL_obligations(ROOT, _source_operation=operation))), (8, 6))
            with tempfile.TemporaryDirectory() as tmp:
                root = Path(tmp).resolve()
                for name in current.SOURCE_INPUTS:
                    p = root / name
                    p.parent.mkdir(parents=True, exist_ok=True)
                    p.write_bytes(context.parent(name))
                    p.chmod(384)
                _supply_parent_fixture(operation, root)
                self.assertEqual(_fixture_SQL_obligations(operation, root, current), current.SQL_obligations(ROOT, _source_operation=operation))
                (root / worker.PATH).write_bytes(prior)
                self.assertEqual(_fixture_SQL_obligations(operation, root, current), current.SQL_obligations(ROOT, _source_operation=operation))
                (root / worker.PATH).write_bytes(actual + b'// other change')
                with self.assertRaises(ValueError):
                    _fixture_SQL_obligations(operation, root, current)

    def test_actual_PHP_both_selectors_and_independent_unknown_cleanup_guards(self):
        with _workflow_owner() as operation:
            context = operation._task_contexts[ROOT]
            context.current(DRIVER)
            program = "$s=file_get_contents($argv[1]);$a=strpos($s,'$arguments=array_slice');$b=strpos($s,'$fixture = null;',$a);$init=substr($s,$a,$b-$a);$a=strpos($s,'$borrowed_unknown=$phase===');$b=strpos($s,'  if(!$borrowed_unknown){$_COOKIE',$a);$unknown=substr($s,$a,$b-$a);$a=strrpos($s,'  if($borrowed_unknown){');$b=strpos($s,'  if(!$borrowed_unknown){if ($control !== null)',$a);$guard=substr($s,$a,$b-$a);function governance_recovery_conduit_cleanup_ready(){return($GLOBALS['governance_recovery_conduit_cleanup_proved']??null)===true;}class UnionFixture{function cleanup(){$GLOBALS['drops']++;return[];}}$rows=[];foreach(['--calendar-reminder-wire-hold','--conduit-producer-owner']as$selector){$argv=['owned',$selector];eval($init);$fixture=new UnionFixture();$cleanup_failures=[];$checks=[];$drops=0;eval($unknown);eval($guard);$rows[]=['phase'=>$phase,'calendar'=>$calendar_wire,'calendar_cleanup'=>$GLOBALS['governance_workbench_http_cleanup_permitted'],'conduit_cleanup'=>$GLOBALS['governance_recovery_conduit_cleanup_proved'],'drops'=>$drops,'failures'=>count($cleanup_failures)];}echo json_encode($rows);"
            r = subprocess.run(['php', '-d', 'disable_functions=mysqli_init,mysqli_connect,stream_socket_client,fsockopen', '-r', program, str(ROOT / DRIVER)], capture_output=True, text=True, timeout=5)
            self.assertEqual(r.returncode, 0, r.stderr)
            self.assertEqual(r.stderr, '')
            rows = json.loads(r.stdout)
            self.assertEqual(rows, [{'phase': 'calendar-reminder-wire-hold', 'calendar': True, 'calendar_cleanup': False, 'conduit_cleanup': True, 'drops': 0, 'failures': 1}, {'phase': 'conduit-producer-owner', 'calendar': False, 'calendar_cleanup': True, 'conduit_cleanup': False, 'drops': 0, 'failures': 1}])

#!/usr/bin/env python3
"""Offline regression tests for the online zh_CN translation tool.

Run from the repository root:
    python3 -m unittest discover -s scripts/i18n -p 'test_*.py' -v

API integration tests use a localhost HTTP server and synthetic credentials.
Catalog extraction is replaced with fixtures; all writes stay in temporary
directories. PHP syntax checks use the installed PHP binary when available.
"""

from __future__ import annotations

import copy
import hashlib
import importlib.util
import json
import os
import pathlib
import shutil
import subprocess
import sys
import tempfile
import threading
import urllib.request
import unittest
from contextlib import contextmanager, redirect_stdout
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from io import StringIO
from unittest import mock


SCRIPT = pathlib.Path(__file__).with_name('translate_zh_api_batch.py')
SPEC = importlib.util.spec_from_file_location('zh_api_under_test', SCRIPT)
assert SPEC is not None and SPEC.loader is not None
TOOL = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = TOOL
SPEC.loader.exec_module(TOOL)


@contextmanager
def fake_api(responses):
    """Serve queued OpenAI-compatible responses without external networking."""
    queue = list(responses)
    requests = []

    class Handler(BaseHTTPRequestHandler):
        def do_POST(self):
            body = self.rfile.read(int(self.headers.get('Content-Length', '0')))
            requests.append({'path': self.path, 'body': json.loads(body)})
            if not queue:
                status, content = 500, {'error': {'message': 'unexpected call'}}
            else:
                status, content = queue.pop(0)
                if callable(content):
                    content = content(requests[-1]['body'])
            if status == 0:
                self.close_connection = True
                return
            if status == 200:
                content = {'choices': [{'message': {'content': content}}]}
            payload = json.dumps(content).encode('utf-8')
            self.send_response(status)
            self.send_header('Content-Type', 'application/json')
            self.send_header('Content-Length', str(len(payload)))
            self.end_headers()
            self.wfile.write(payload)

        def log_message(self, *_args):
            pass

    server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
    worker = threading.Thread(target=server.serve_forever, daemon=True)
    worker.start()
    try:
        # Ignore machine proxy settings so fixture traffic stays on loopback.
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        with mock.patch.object(TOOL.urllib.request, 'urlopen', side_effect=opener.open):
            yield f'http://127.0.0.1:{server.server_port}/v1', requests
    finally:
        server.shutdown()
        server.server_close()
        worker.join(timeout=2)


def response_for(candidates, translations):
    result = []
    for candidate in candidates:
        if candidate.source not in translations:
            continue
        text = translations[candidate.source]
        _, markers = TOOL.protect_text(candidate.source)
        for marker, original in markers.items():
            text = text.replace(original, marker, 1)
        result.append({'id': candidate.id, 'text': text})
    return json.dumps({'translations': result}, ensure_ascii=False)


class TranslationToolTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix='phorge-zh-api-test-')
        self.addCleanup(self.directory.cleanup)
        self.root = pathlib.Path(self.directory.name)
        self.translation_file = self.root / 'ChineseTranslation.php'
        self.translation_file.write_text('<?php return array();\n', encoding='utf-8')
        self.progress_file = self.root / 'progress.json'
        self.glossary_file = self.root / 'glossary.md'
        self.glossary_file.write_text('| Branch | 分支 |\n', encoding='utf-8')

    def catalog(self, sources, translations=None):
        return {
            'sources': {key: {'types': types, 'uses': [{'file': 'fixture.php', 'line': index + 1}]}
                        for index, (key, types) in enumerate(sources.items())},
            'translations': copy.deepcopy(translations or {}),
            'prefix': '<?php return ',
            'suffix': ';\n',
            'sha256': hashlib.sha256(self.translation_file.read_bytes()).hexdigest(),
        }

    def write_progress(self, completed=None, **extra):
        self.progress_file.write_text(json.dumps({
            'version': 1, 'translation_file': str(self.translation_file.resolve()),
            'completed': completed or {}, **extra,
        }) + '\n')

    def args(self, base_url='http://127.0.0.1:1/v1', *extra):
        return TOOL.parse_args([
            '--file', str(self.translation_file),
            '--progress-file', str(self.progress_file),
            '--glossary', str(self.glossary_file),
            '--api-key-env', 'PHORGE_I18N_TEST_API_KEY',
            '--base-url', base_url,
            '--model', 'offline-test-model',
            '--request-interval', '0', '--retry-sleep', '0',
            '--request-timeout', '2', '--batch-size', '30',
            *extra,
        ])

    def validate_syntax(self, _args, path, _sources):
        php = shutil.which('php')
        if php:
            result = subprocess.run([php, '-l', str(path)], capture_output=True, text=True, check=False)
            if result.returncode:
                raise RuntimeError(result.stdout + result.stderr)

    def run_tool(self, args, catalog, validator=None):
        with mock.patch.object(TOOL, 'load_catalog', return_value=copy.deepcopy(catalog)), \
             mock.patch.object(TOOL, 'validate_php', side_effect=validator or self.validate_syntax), \
             mock.patch.dict(os.environ, {'PHORGE_I18N_TEST_API_KEY': 'synthetic-localhost-only'}), \
             redirect_stdout(StringIO()):
            return TOOL.run(args)

    def run_failure(self, args, catalog, validator=None):
        try:
            return self.run_tool(args, catalog, validator)
        except (RuntimeError, ValueError, OSError):
            return 1

    def read_translations(self):
        php = shutil.which('php')
        if not php:
            self.skipTest('PHP is required to inspect generated translation arrays')
        result = subprocess.run([
            php, '-r', '$v = require $argv[1]; echo json_encode($v, JSON_UNESCAPED_UNICODE);',
            str(self.translation_file),
        ], capture_output=True, text=True, check=True)
        return json.loads(result.stdout)

    def test_dry_run_with_reset_never_writes_or_calls_api(self):
        catalog = self.catalog({'Create Project': []})
        self.write_progress(sentinel='keep')
        before = (self.translation_file.read_bytes(), self.progress_file.read_bytes())
        validator = mock.Mock(side_effect=AssertionError('Dry run attempted write validation'))
        with fake_api([]) as (url, requests), \
             mock.patch.object(TOOL, 'request_translations', side_effect=AssertionError('API called')):
            self.assertEqual(0, self.run_tool(self.args(url, '--mode', 'missing', '--dry-run', '--reset-progress'), catalog, validator))
        self.assertEqual([], requests)
        validator.assert_not_called()
        self.assertEqual(before, (self.translation_file.read_bytes(), self.progress_file.read_bytes()))

    def test_missing_self_mapped_and_nested_branches_use_real_http(self):
        catalog = self.catalog({
            'Create Project': [], 'Edit Task %s': [None],
            'You have %s object(s) in %s project(s).': ['number', 'number'],
        }, {
            'Edit Task %s': 'Edit Task %s',
            'You have %s object(s) in %s project(s).': [
                ['You have %s object in %s project.', 'You have %s object in %s projects.'],
                ['You have %s objects in %s project.', 'You have %s objects in %s projects.'],
            ],
        })
        candidates = TOOL.collect_candidates(catalog, 'all', [], {}, 500)
        translations = {
            'Create Project': '创建项目', 'Edit Task %s': '编辑任务 %s',
            'You have %s object in %s project.': '您有 %s 个对象，位于 %s 个项目。',
            'You have %s object in %s projects.': '您有 %s 个对象，位于 %s 个项目。',
            'You have %s objects in %s project.': '您有 %s 个对象，位于 %s 个项目。',
            'You have %s objects in %s projects.': '您有 %s 个对象，位于 %s 个项目。',
        }
        with fake_api([(200, response_for(candidates, translations))]) as (url, requests):
            self.assertEqual(0, self.run_tool(self.args(url, '--mode', 'all'), catalog))
        self.assertEqual(1, len(requests))
        self.assertEqual('/v1/chat/completions', requests[0]['path'])
        actual = self.read_translations()
        self.assertEqual('创建项目', actual['Create Project'])
        self.assertEqual('编辑任务 %s', actual['Edit Task %s'])
        leaf = '您有 %s 个对象，位于 %s 个项目。'
        self.assertEqual([[leaf, leaf], [leaf, leaf]],
                         actual['You have %s object(s) in %s project(s).'])
        self.assertTrue(self.progress_file.exists())

    def test_pure_templates_and_product_names_are_not_candidates(self):
        sources = {'%s %s': [None, None], '"%s"': [None], 'ID': [], 'PHID': [], 'URI': [],
                   'GitHub': [], 'Differential': [], 'Create Project': []}
        candidates = TOOL.collect_candidates(self.catalog(sources), 'missing', [], {}, 500)
        self.assertEqual(['Create Project'], [candidate.key for candidate in candidates])

    def test_mixed_translation_is_repaired_from_current_leaf(self):
        key = 'The type of a blueprint cannot be changed.'
        current = '该 type of a blueprint cannot be changed.'
        catalog = self.catalog({key: []}, {key: current})
        candidates = TOOL.collect_candidates(catalog, 'untranslated', [], {}, 500)
        self.assertEqual([current], [candidate.source for candidate in candidates])
        with fake_api([(200, response_for(candidates, {current: '蓝图的类型无法更改。'}))]) as (url, _requests):
            self.assertEqual(0, self.run_tool(self.args(url), catalog))
        self.assertEqual('蓝图的类型无法更改。', self.read_translations()[key])

    def test_ids_stay_stable_when_keys_are_reordered(self):
        first = self.catalog({'Edit Task': [], 'Create Project': []})
        second = self.catalog({'Create Project': [], 'Edit Task': []})
        a = {candidate.key: (candidate.id, candidate.fingerprint)
             for candidate in TOOL.collect_candidates(first, 'missing', [], {}, 500)}
        b = {candidate.key: (candidate.id, candidate.fingerprint)
             for candidate in TOOL.collect_candidates(second, 'missing', [], {}, 500)}
        self.assertEqual(a, b)

    def test_invalid_progress_fails_without_rewriting_it(self):
        args = self.args()
        invalid_payloads = [[], None, 'invalid', {
            'version': 1, 'translation_file': str(self.translation_file.resolve()), 'completed': [],
        }]
        for payload in invalid_payloads:
            with self.subTest(payload=payload):
                text = json.dumps(payload) + '\n'
                self.progress_file.write_text(text)
                with self.assertRaises(ValueError):
                    TOOL.load_progress(args)
                self.assertEqual(text, self.progress_file.read_text())

    def test_invalid_or_missing_model_results_never_modify_files(self):
        catalog = self.catalog({'Edit Task %s': [None]}, {'Edit Task %s': 'Edit Task %s'})
        candidate = TOOL.collect_candidates(catalog, 'untranslated', [], {}, 500)[0]
        bad_contents = [
            'not JSON',
            json.dumps({'translations': []}),
            json.dumps({'translations': [{'id': candidate.id, 'text': '编辑任务'}]}),
            json.dumps({'translations': [{'id': candidate.id, 'text': 'Edit Task %s'}]}),
        ]
        before = self.translation_file.read_bytes()
        for content in bad_contents:
            with self.subTest(content=content), fake_api([(200, content)]) as (url, _requests):
                self.run_failure(self.args(url, '--retry', '1'), catalog)
            self.assertEqual(before, self.translation_file.read_bytes())
            if self.progress_file.exists():
                self.assertFalse(json.loads(self.progress_file.read_text()).get('completed'))

    def test_api_duplicate_or_unknown_ids_fail_closed(self):
        batch = [{'id': 'known', 'text': 'Create Project', 'key': 'Create Project', 'path': []}]
        contents = [
            {'translations': [{'id': 'unknown', 'text': '创建项目'}]},
            {'translations': [{'id': 'known', 'text': '创建项目'}, {'id': 'known', 'text': '另一译文'}]},
        ]
        for content in contents:
            with self.subTest(content=content), fake_api([(200, json.dumps(content))]) as (url, _requests), \
                 mock.patch.dict(os.environ, {'PHORGE_I18N_TEST_API_KEY': 'synthetic-localhost-only'}):
                try:
                    result = TOOL.request_translations(self.args(url, '--retry', '1'), batch, '')
                except (RuntimeError, ValueError):
                    result = {}
                self.assertEqual({}, result)

    def test_partial_success_keeps_invalid_entry_retryable(self):
        sources = {'Create Project': [], 'Edit Task %s': [None]}
        catalog = self.catalog(sources, {'Edit Task %s': 'Edit Task %s'})
        candidates = TOOL.collect_candidates(catalog, 'all', [], {}, 500)
        content = response_for(candidates, {'Create Project': '创建项目', 'Edit Task %s': '编辑任务'})
        with fake_api([(200, content)]) as (url, _requests):
            self.run_failure(self.args(url, '--mode', 'all'), catalog)
        actual = self.read_translations()
        self.assertEqual('创建项目', actual['Create Project'])
        self.assertEqual('Edit Task %s', actual['Edit Task %s'])
        updated_catalog = self.catalog(sources, actual)
        remaining = TOOL.collect_candidates(updated_catalog, 'untranslated', [], {}, 500)
        self.assertEqual(['Edit Task %s'], [candidate.key for candidate in remaining])
        with fake_api([(200, response_for(remaining, {'Edit Task %s': '编辑任务 %s'}))]) as (url, requests):
            self.assertEqual(0, self.run_tool(self.args(url), updated_catalog))
        self.assertEqual(1, len(requests))
        self.assertEqual('编辑任务 %s', self.read_translations()['Edit Task %s'])

    def test_manual_revert_is_not_hidden_by_checkpoint(self):
        sources = {'Create Project': []}
        catalog = self.catalog(sources, {'Create Project': 'Create Project'})
        candidates = TOOL.collect_candidates(catalog, 'untranslated', [], {}, 500)
        response = response_for(candidates, {'Create Project': '创建项目'})
        with fake_api([(200, response)]) as (url, _requests):
            self.assertEqual(0, self.run_tool(self.args(url), catalog))
        self.assertTrue(self.progress_file.exists())
        self.translation_file.write_text('<?php return array("Create Project" => "Create Project");\n')
        reverted_catalog = self.catalog(sources, {'Create Project': 'Create Project'})
        with fake_api([(200, response)]) as (url, requests):
            self.assertEqual(0, self.run_tool(self.args(url), reverted_catalog))
        self.assertEqual(1, len(requests))
        self.assertEqual('创建项目', self.read_translations()['Create Project'])

    def test_http_retry_then_success(self):
        batch = [{'id': 'known', 'text': 'Create Project', 'key': 'Create Project', 'path': []}]
        success = json.dumps({'translations': [{'id': 'known', 'text': '创建项目'}]})
        with fake_api([(429, {'error': {'message': 'retry'}}), (200, success)]) as (url, requests), \
             mock.patch.dict(os.environ, {'PHORGE_I18N_TEST_API_KEY': 'synthetic-localhost-only'}):
            self.assertEqual({'known': '创建项目'}, TOOL.request_translations(self.args(url), batch, ''))
        self.assertEqual(2, len(requests))

    def test_url_targets_are_preserved_while_link_labels_can_translate(self):
        source = 'Read [[ https://example.invalid/docs | documentation ]].'
        translated = '阅读 [[ https://example.invalid/docs | 文档 ]]。'
        altered = '阅读 [[ https://example.invalid/changed | 文档 ]]。'
        _, markers = TOOL.protect_text(source)
        self.assertTrue(any('https://example.invalid/docs' in original for original in markers.values()))
        self.assertTrue(TOOL.validate_translation(source, translated))
        self.assertFalse(TOOL.validate_translation(source, altered))

    def test_transport_disconnect_is_retried(self):
        batch = [{'id': 'known', 'text': 'Create Project', 'key': 'Create Project', 'path': []}]
        success = json.dumps({'translations': [{'id': 'known', 'text': '创建项目'}]})
        with fake_api([(0, None), (200, success)]) as (url, requests), \
             mock.patch.dict(os.environ, {'PHORGE_I18N_TEST_API_KEY': 'synthetic-localhost-only'}):
            self.assertEqual({'known': '创建项目'}, TOOL.request_translations(self.args(url), batch, ''))
        self.assertEqual(2, len(requests))

    def test_validation_failure_preserves_translation_and_progress(self):
        catalog = self.catalog({'Create Project': []})
        self.write_progress()
        before = (self.translation_file.read_bytes(), self.progress_file.read_bytes())
        candidates = TOOL.collect_candidates(catalog, 'missing', [], {}, 500)
        validator = mock.Mock(side_effect=RuntimeError('native validation failed'))
        with fake_api([(200, response_for(candidates, {'Create Project': '创建项目'}))]) as (url, _requests):
            result = self.run_failure(self.args(url, '--mode', 'missing'), catalog, validator)
        validator.assert_called_once()
        self.assertNotEqual(0, result)
        self.assertEqual(before, (self.translation_file.read_bytes(), self.progress_file.read_bytes()))

    def test_concurrent_user_edit_is_preserved(self):
        catalog = self.catalog({'Create Project': []})
        self.translation_file.write_text('<?php return array("User edit" => "保留");\n')
        before = self.translation_file.read_bytes()
        candidates = TOOL.collect_candidates(catalog, 'missing', [], {}, 500)
        with fake_api([(200, response_for(candidates, {'Create Project': '创建项目'}))]) as (url, _requests):
            result = self.run_failure(self.args(url, '--mode', 'missing'), catalog)
        self.assertNotEqual(0, result)
        self.assertEqual(before, self.translation_file.read_bytes())
        self.assertFalse(self.progress_file.exists())

    def test_failed_atomic_replace_preserves_original(self):
        catalog = self.catalog({'Create Project': []})
        candidates = TOOL.collect_candidates(catalog, 'missing', [], {}, 500)
        before = self.translation_file.read_bytes()
        with fake_api([(200, response_for(candidates, {'Create Project': '创建项目'}))]) as (url, _requests), \
             mock.patch.object(TOOL.os, 'replace', side_effect=OSError('synthetic replace failure')) as replace:
            result = self.run_failure(self.args(url, '--mode', 'missing'), catalog)
        replace.assert_called_once()
        self.assertNotEqual(0, result)
        self.assertEqual(before, self.translation_file.read_bytes())
        self.assertFalse(self.progress_file.exists())

    def test_protected_tokens_and_php_escape_boundary(self):
        source = 'Edit %s in [Project] with 100%% progress'
        masked, mapping = TOOL.protect_text(source)
        self.assertTrue(TOOL.protected_tokens_intact(masked, mapping))
        self.assertFalse(TOOL.protected_tokens_intact(masked + next(iter(mapping)), mapping))
        self.assertFalse(TOOL.protected_tokens_intact(masked + '__PH_TOKEN_999__', mapping))
        self.assertEqual(source, TOOL.restore_protected_tokens(masked, mapping))
        self.assertFalse(TOOL.validate_translation('Edit %s', '编辑'))
        self.assertFalse(TOOL.validate_translation('Edit %s', 'Edit %s'))
        self.assertTrue(TOOL.validate_translation('Edit %s', '编辑 %s'))
        key = "Open path \\' quoted"
        catalog = self.catalog({key: []})
        candidates = TOOL.collect_candidates(catalog, 'missing', [], {}, 500)
        translated = "打开路径 \\' 引用"
        with fake_api([(200, response_for(candidates, {key: translated}))]) as (url, _requests):
            self.assertEqual(0, self.run_tool(self.args(url, '--mode', 'missing'), catalog))
        self.assertEqual(translated, self.read_translations()[key])


if __name__ == '__main__':
    unittest.main()

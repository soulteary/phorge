#!/usr/bin/env python3
"""Offline request and error regressions for the Moonshot API defaults."""

from __future__ import annotations

import copy
import hashlib
import importlib.util
import io
import json
import os
import pathlib
import sys
import tempfile
import urllib.error
import unittest
from contextlib import redirect_stdout
from unittest import mock


SCRIPT = pathlib.Path(__file__).with_name('translate_zh_api_batch.py')
SPEC = importlib.util.spec_from_file_location('moonshot_transport_under_test', SCRIPT)
assert SPEC is not None and SPEC.loader is not None
TOOL = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = TOOL
SPEC.loader.exec_module(TOOL)

API_KEY_ENV = 'PHORGE_MOONSHOT_TEST_API_KEY'
SYNTHETIC_API_KEY = 'synthetic-moonshot-credential-not-for-network'
BATCH = [{'id': 'fixture', 'text': 'Create Project', 'key': 'Create Project'}]


def success_response(item_id='fixture'):
    content = json.dumps({'translations': [{'id': item_id, 'text': '创建项目'}]},
                         ensure_ascii=False)
    return io.BytesIO(json.dumps({'choices': [{'message': {'content': content}}]},
                                ensure_ascii=False).encode('utf-8'))


def http_error(status, body):
    if isinstance(body, dict):
        body = json.dumps(body, ensure_ascii=False).encode('utf-8')
    elif isinstance(body, str):
        body = body.encode('utf-8')
    return urllib.error.HTTPError(
        'https://api.moonshot.cn/v1/chat/completions', status,
        'Synthetic API failure', {}, io.BytesIO(body))


class MoonshotApiFixtures:
    def setUp(self):
        self.environment = mock.patch.dict(os.environ, {
            API_KEY_ENV: SYNTHETIC_API_KEY,
        })
        self.environment.start()
        self.addCleanup(self.environment.stop)

    def args(self, *extra):
        return TOOL.parse_args([
            '--api-key-env', API_KEY_ENV,
            '--retry', '3', '--retry-sleep', '0', '--request-interval', '0',
            *extra,
        ])

    def capture_request(self, args):
        captured = []

        def respond(request, **_options):
            captured.append(json.loads(request.data))
            self.assertEqual('Bearer ' + SYNTHETIC_API_KEY,
                             request.get_header('Authorization'))
            return success_response()

        with mock.patch.object(TOOL.urllib.request, 'urlopen', side_effect=respond) as open_url:
            self.assertEqual({'fixture': '创建项目'},
                             TOOL.request_translations(args, BATCH, ''))
        self.assertEqual(1, open_url.call_count)
        return captured[0]


class MoonshotRequestTests(MoonshotApiFixtures, unittest.TestCase):
    def test_default_sampling_values_are_unspecified(self):
        args = self.args()
        self.assertIsNone(args.temperature)
        self.assertIsNone(args.top_p)
        payload = self.capture_request(args)
        self.assertNotIn('temperature', payload)
        self.assertNotIn('top_p', payload)
        self.assertEqual({'type': 'disabled'}, payload['thinking'])

    def test_exact_official_hosts_disable_thinking_for_kimi_k26(self):
        for host in ('api.moonshot.cn', 'api.moonshot.ai'):
            with self.subTest(host=host):
                payload = self.capture_request(self.args(
                    '--base-url', 'https://' + host + '/v1',
                    '--model', 'kimi-k2.6'))
                self.assertEqual({'type': 'disabled'}, payload['thinking'])

    def test_other_providers_and_kimi_models_have_no_implicit_thinking(self):
        cases = [
            ('https://api.example.test/v1', 'kimi-k2.6'),
            ('https://api.moonshot.cn.example.test/v1', 'kimi-k2.6'),
            ('https://proxy.api.moonshot.ai/v1', 'kimi-k2.6'),
            ('https://api.moonshot.cn/v1', 'kimi-k2.5'),
            ('https://api.moonshot.ai/v1', 'kimi-k2'),
            ('https://api.moonshot.cn/v1', 'other-model'),
        ]
        for url, model in cases:
            with self.subTest(url=url, model=model):
                payload = self.capture_request(self.args(
                    '--base-url', url, '--model', model))
                self.assertNotIn('thinking', payload)

    def test_explicit_thinking_enabled_has_no_default_sampling(self):
        payload = self.capture_request(self.args(
            '--extra-body', '{"thinking":{"type":"enabled"}}'))
        self.assertEqual({'type': 'enabled'}, payload['thinking'])
        self.assertNotIn('temperature', payload)
        self.assertNotIn('top_p', payload)

    def test_explicit_sampling_values_are_preserved(self):
        payload = self.capture_request(self.args(
            '--temperature', '0.25', '--top-p', '0.8'))
        self.assertEqual(0.25, payload['temperature'])
        self.assertEqual(0.8, payload['top_p'])

    def test_extra_body_cannot_override_omitted_standard_sampling(self):
        for field in ('temperature', 'top_p'):
            with self.subTest(field=field):
                args = self.args('--extra-body', json.dumps({field: 0.5}))
                with mock.patch.object(TOOL.urllib.request, 'urlopen') as open_url:
                    with self.assertRaises(ValueError):
                        TOOL.request_translations(args, BATCH, '')
                open_url.assert_not_called()


class MoonshotErrorTests(MoonshotApiFixtures, unittest.TestCase):
    def failure(self, error, args=None):
        with mock.patch.object(TOOL.urllib.request, 'urlopen', side_effect=error) as open_url, \
             mock.patch.object(TOOL.time, 'sleep'):
            with self.assertRaises(RuntimeError) as raised:
                TOOL.request_translations(args or self.args(), BATCH, '')
        return str(raised.exception), open_url.call_count

    def test_http_400_exposes_json_error_once(self):
        message, calls = self.failure(http_error(400, {'error': {
            'message': 'Unsupported temperature for this model',
            'type': 'invalid_request_error', 'code': 'invalid_parameter',
        }}))
        self.assertEqual(1, calls)
        for expected in ('400', 'Unsupported temperature',
                         'invalid_request_error', 'invalid_parameter'):
            self.assertIn(expected, message)

    def test_http_400_errors_are_bounded_and_redact_credentials(self):
        bodies = [
            {'error': {'message': 'Invalid ' + SYNTHETIC_API_KEY + ' ' + ('细节' * 10000),
                       'type': 'invalid_request_error', 'code': 'bad_argument'}},
            '<html><body>blocked ' + SYNTHETIC_API_KEY + (' detail' * 10000) + '</body></html>',
            ('broken JSON: ' + SYNTHETIC_API_KEY + ' {"error":').encode() + b'\xff\xfe',
            b'',
        ]
        for body in bodies:
            with self.subTest(body_type=type(body).__name__):
                message, calls = self.failure(http_error(400, body))
                self.assertEqual(1, calls)
                self.assertIn('400', message)
                self.assertNotIn(SYNTHETIC_API_KEY, message)
                self.assertLessEqual(len(message), 4400)
                self.assertTrue(message.strip())

    def test_http_error_reason_also_redacts_credentials(self):
        error = urllib.error.HTTPError(
            'https://api.moonshot.cn/v1/chat/completions', 400,
            'Rejected ' + SYNTHETIC_API_KEY, {}, io.BytesIO(b''))
        message, calls = self.failure(error)
        self.assertEqual(1, calls)
        self.assertIn('Rejected', message)
        self.assertNotIn(SYNTHETIC_API_KEY, message)

    def test_http_429_and_server_errors_retry(self):
        for status in (429, 500, 502, 503):
            with self.subTest(status=status):
                error = http_error(status, {'error': {'message': 'temporary failure'}})
                with mock.patch.object(TOOL.urllib.request, 'urlopen',
                                       side_effect=[error, success_response()]) as open_url, \
                     mock.patch.object(TOOL.time, 'sleep'):
                    result = TOOL.request_translations(self.args(), BATCH, '')
                self.assertEqual({'fixture': '创建项目'}, result)
                self.assertEqual(2, open_url.call_count)

    def test_exhausted_server_retries_redact_error_body(self):
        errors = [http_error(503, {'error': {
            'message': 'Failure ' + SYNTHETIC_API_KEY,
        }}) for _ in range(3)]
        message, calls = self.failure(errors)
        self.assertEqual(3, calls)
        self.assertIn('503', message)
        self.assertNotIn(SYNTHETIC_API_KEY, message)

    def test_permanent_api_failure_leaves_dictionary_and_progress_unchanged(self):
        with tempfile.TemporaryDirectory(prefix='phorge-moonshot-files-') as directory:
            root = pathlib.Path(directory)
            dictionary, progress, glossary = (
                root / 'translation.php', root / 'progress.json', root / 'glossary.md')
            dictionary.write_text('<?php return array();\n')
            glossary.write_text('术语表\n')
            args = self.args('--file', str(dictionary), '--progress-file', str(progress),
                             '--glossary', str(glossary), '--mode', 'missing')
            progress.write_text(json.dumps({'version': 1,
                                           'translation_file': str(dictionary.resolve()),
                                           'completed': {}, 'sentinel': 'keep'}))
            catalog = {'sources': {'Create Project': {'types': [], 'uses': []}},
                       'translations': {}, 'prefix': '<?php return ', 'suffix': ';\n',
                       'sha256': hashlib.sha256(dictionary.read_bytes()).hexdigest()}
            before = dictionary.read_bytes(), progress.read_bytes()
            with mock.patch.object(TOOL, 'load_catalog', return_value=copy.deepcopy(catalog)), \
                 mock.patch.object(TOOL, 'save_translations') as save_dictionary, \
                 mock.patch.object(TOOL, 'save_progress') as save_progress, \
                 mock.patch.object(TOOL.urllib.request, 'urlopen',
                                   side_effect=http_error(400, {'error': {
                                       'message': 'Unsupported parameter',
                                       'type': 'invalid_request_error',
                                   }})) as open_url, \
                 redirect_stdout(io.StringIO()):
                with self.assertRaises(RuntimeError):
                    TOOL.run(args)
            self.assertEqual(1, open_url.call_count)
            save_dictionary.assert_not_called()
            save_progress.assert_not_called()
            self.assertEqual(before, (dictionary.read_bytes(), progress.read_bytes()))


if __name__ == '__main__':
    unittest.main()

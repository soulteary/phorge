"""Actual private-media response contracts; no HTTP, identity or SQL proof."""
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]
PHP = r'''

// Native response hardening only: no HTTP, identity, SQL or cache-hit proof.
require_once $argv[1].'/tests/contract/bootstrap.php';
$stack = new PhabricatorConfigStackSource();
$stack->pushSource(new PhabricatorConfigDefaultSource());
(new ReflectionProperty(PhabricatorEnv::class, 'sourceStack'))->setValue(null, $stack);
(new ReflectionProperty(PhabricatorEnv::class, 'cache'))->setValue(null, array());

$flags = array('governance.media-exchange.enabled',
  'governance.media-exchange.http-enabled');
$before = array();
foreach ($flags as $flag) {
  $before[$flag] = PhabricatorEnv::getEnvConfig($flag);
}
$checks = array();
$check = static function($ok, $name) use (&$checks) {
  if (!$ok) {
    throw new RuntimeException($name);
  }
  $checks[] = $name;
};

foreach (array(
  array('text/html', 'image/png'),
  array('image/png', 'text/html'),
  array('application/pdf', 'text/plain'),
  array('text/plain', 'application/pdf'),
) as $index => $types) {
  $file = (new PhabricatorFile())
    ->setName('synthetic.txt')->setMimeType($types[0]);
  $response = (new AphrontFileResponse())
    ->setMimeType($types[1])->setContent('synthetic');
  PhorgeGovernanceMediaFileGuard::secureResponse($response, $file);
  $check($response->getMimeType() === 'application/octet-stream',
    'unsafe-mime-'.$index.'-download-type');
  $check($response->getDownload() === 'synthetic.txt',
    'unsafe-mime-'.$index.'-download-name');
  $check($response->buildResponseString() === 'synthetic',
    'unsafe-mime-'.$index.'-content-preserved');
}

$file = (new PhabricatorFile())
  ->setName('synthetic.png')->setMimeType('image/png');
$response = (new AphrontFileResponse())
  ->setMimeType('image/png')->setContent('synthetic');
PhorgeGovernanceMediaFileGuard::secureResponse($response, $file);
$check($response->getMimeType() === 'image/png', 'safe-inline-type-preserved');
$check($response->getDownload() === null, 'safe-inline-not-forced-to-download');

foreach (array(302, 304) as $code) {
  $response = $code === 302
    ? (new AphrontRedirectResponse())->setIsExternal(true)
      ->setURI('https://files.example.invalid/file/data/synthetic/')
    : new Aphront304Response();
  $response->setHTTPResponseCode($code)
    ->setCanCDN(true)->setCacheDurationInSeconds(86400);
  $check(PhorgeGovernanceMediaFileGuard::secureResponse($response) === $response,
    'response-'.$code.'-identity-preserved');
  $check($response->getHTTPResponseCode() === $code,
    'response-'.$code.'-status-preserved');
  $headers = array();
  foreach ($response->getCacheHeaders() as $header) {
    $headers[strtolower($header[0])] = $header[1];
  }
  $check($headers['cache-control'] === 'no-store',
    'response-'.$code.'-public-cache-cleared');
  $check($headers['expires'] === 'Sat, 01 Jan 2000 00:00:00 GMT',
    'response-'.$code.'-expiration-cleared');
  $headers = array();
  foreach ($response->getHeaders() as $header) {
    $headers[strtolower($header[0])] = $header[1];
  }
  $check($headers['cache-control'] === 'no-store, private',
    'response-'.$code.'-private-header');
  $check($headers['referrer-policy'] === 'no-referrer',
    'response-'.$code.'-no-referrer');
}

foreach ($flags as $flag) {
  $check($before[$flag] === false &&
    PhabricatorEnv::getEnvConfig($flag) === $before[$flag],
    'default-off-preserved-'.$flag);
}

echo json_encode(array(
  'php' => PHP_VERSION,
  'passed' => true,
  'checks' => $checks,
  'http_or_browser_proof' => false,
  'authenticated_conditional_cache_hit_proof' => false,
  'application_flags_changed' => false,
), JSON_THROW_ON_ERROR)."\n";
'''


class MediaResponseContractTest(unittest.TestCase):
    def test_native_mime_and_cache_response_boundaries(self):
        result = subprocess.run(
            ['php', '-d', 'disable_functions=mysqli_init,mysqli_connect,stream_socket_client,fsockopen',
             '-r', PHP, str(ROOT)],
            capture_output=True, text=True, timeout=15)
        self.assertEqual(result.returncode, 0, result.stderr or result.stdout)
        self.assertEqual(result.stderr, '')
        report = json.loads(result.stdout)
        self.assertTrue(report['passed'])
        self.assertEqual(len(report['checks']), 28)
        self.assertEqual(len(set(report['checks'])), 28)
        self.assertFalse(report['http_or_browser_proof'])
        self.assertFalse(report['authenticated_conditional_cache_hit_proof'])
        self.assertFalse(report['application_flags_changed'])


if __name__ == '__main__':
    unittest.main()

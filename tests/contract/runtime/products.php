<?php

require_once dirname(__DIR__).'/bootstrap.php';
$root = dirname(__DIR__, 3);
$render_url = (string)getenv('GORGE_TEST_RENDER_URL');
$render_token = (string)getenv('GORGE_TEST_RENDER_TOKEN');
if (!phutil_nonempty_string($render_url) ||
    !phutil_nonempty_string($render_token)) {
  throw new RuntimeException(
    'Set GORGE_TEST_RENDER_URL and GORGE_TEST_RENDER_TOKEN to a dedicated '.
    'test render service.');
}
require_once $root.'/scripts/init/lib.php';
init_phabricator_script(array(
  'config.optional' => true,
  'no-extensions' => true,
));
LiskDAO::beginIsolateAllLiskEffectsToCurrentProcess();
$product_env = PhabricatorEnv::beginScopedEnv();
$product_env->overrideEnvConfig('phabricator.base-uri',
  'http://runtime-product.example.test');
$product_env->overrideEnvConfig('gorge.render.uri', $render_url);
$product_env->overrideEnvConfig('gorge.render.token', $render_token);
$product_env->overrideEnvConfig('gorge.service-policies', array(
  'render' => PhabricatorGorgeServiceSpec::POLICY_REQUIRED,
));
// Notebook block identities use a persisted named HMAC key in production.
// Supply the immutable cache and key in memory for this isolated contract.
PhabricatorCaches::getImmutableCache()
  ->setCaches(array(new PhutilInRequestKeyValueCache()))
  ->setKey('hmac.key(document-engine.content-digest)', str_repeat('12', 64));

function gorge_runtime_product_assert($condition, $message) {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

function gorge_runtime_product_contains($needle, $html, $message) {
  gorge_runtime_product_assert(strpos((string)$html, $needle) !== false,
    $message);
}

function gorge_runtime_product_rejects($callback, $message) {
  try {
    $callback();
  } catch (Exception $expected) {
    return;
  }
  throw new RuntimeException($message);
}

// The fixture proves the PHP HTTP/envelope/Jupyter integration. Gorge's prose
// algorithm has its own service tests; this fixture deliberately has one
// fixed response and rejects unexpected inputs.
function gorge_runtime_product_new_prose_fixture() {
  $directory = sys_get_temp_dir().'/gorge-runtime-products-'.
    bin2hex(random_bytes(12));
  mkdir($directory, 0700);
  $process = null;
  try {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) {
      throw new RuntimeException('Unable to bind prose fixture: '.$error);
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);

    $router = <<<'PHP'
<?php
header('Content-Type: application/json');
if ($_SERVER['REQUEST_URI'] === '/readyz') {
  echo '{"ready":true}';
  return;
}
$request = json_decode(file_get_contents('php://input'), true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' ||
    $_SERVER['REQUEST_URI'] !== '/api/diff/prose' ||
    ($_SERVER['HTTP_X_SERVICE_TOKEN'] ?? '') !== 'runtime-product-fixture' ||
    $request !== array(
      'old' => 'The old notebook <value>.',
      'new' => 'The new notebook <value>.')) {
  http_response_code(400);
  echo '{"error":{"code":"INVALID_FIXTURE_REQUEST"}}';
  return;
}
file_put_contents(__DIR__.'/request.json', json_encode($request));
echo json_encode(array('data' => array('parts' => array(
  array('type' => '=', 'text' => 'The '),
  array('type' => '-', 'text' => 'old'),
  array('type' => '+', 'text' => 'new'),
  array('type' => '=', 'text' => ' notebook <value>.'),
))));
PHP;
    file_put_contents($directory.'/router.php', $router);
    $process = proc_open(
      array(PHP_BINARY, '-S', $address, $directory.'/router.php'),
      array(
        0 => array('file', '/dev/null', 'r'),
        1 => array('file', $directory.'/server.log', 'a'),
        2 => array('file', $directory.'/server.log', 'a'),
      ),
      $pipes,
      $directory);
    if (!is_resource($process)) {
      throw new RuntimeException('Unable to start prose fixture.');
    }

    $uri = 'http://'.$address;
    $ready = false;
    $deadline = microtime(true) + 10;
    do {
      $connection = @stream_socket_client('tcp://'.$address, $errno, $error,
        0.1);
      if ($connection) {
        fclose($connection);
        $ready = true;
        break;
      }
      if (!proc_get_status($process)['running']) {
        break;
      }
      usleep(100000);
    } while (microtime(true) < $deadline);
    if (!$ready) {
      throw new RuntimeException('Prose fixture did not become ready: '.
        file_get_contents($directory.'/server.log'));
    }
    return array($process, $directory, $uri);
  } catch (Throwable $ex) {
    gorge_runtime_product_close_prose_fixture($process, $directory);
    throw $ex;
  }
}

function gorge_runtime_product_close_prose_fixture($process, $directory) {
  if (is_resource($process)) {
    proc_terminate($process);
    proc_close($process);
  }
  foreach (array('router.php', 'server.log', 'request.json') as $file) {
    if (is_file($directory.'/'.$file)) {
      unlink($directory.'/'.$file);
    }
  }
  rmdir($directory);
}

gorge_runtime_product_assert(
  realpath(phutil_get_library_root('arcanist')) ===
    realpath($root.'/support/runtime/src'),
  'Product checks loaded an external runtime.');

$viewer = new PhabricatorUser();
$jupyter = id(new PhabricatorJupyterDocumentEngine())->setViewer($viewer);
$old_ref = id(new PhabricatorDocumentRef())->setName('old.ipynb');
$new_ref = id(new PhabricatorDocumentRef())->setName('new.ipynb');

list($process, $directory, $uri) =
  gorge_runtime_product_new_prose_fixture();
$env = PhabricatorEnv::beginScopedEnv();
try {
  $env->overrideEnvConfig('gorge.render.uri', $uri);
  $env->overrideEnvConfig('gorge.render.token', 'runtime-product-fixture');
  $env->overrideEnvConfig('gorge.service-policies', array(
    'render' => PhabricatorGorgeServiceSpec::POLICY_REQUIRED,
  ));
  $old_block = id(new PhabricatorDocumentEngineBlock())->setContent(array(
    'cell_type' => 'markdown',
    'source' => array('The old notebook ', '<value>.'),
  ));
  $new_block = id(new PhabricatorDocumentEngineBlock())->setContent(array(
    'cell_type' => 'markdown',
    'source' => 'The new notebook <value>.',
  ));
  $diff = $jupyter->newBlockDiffViews(
    $old_ref, $old_block, $new_ref, $new_block);
  gorge_runtime_product_contains('<span class="bright">old</span>',
    $diff->getOldContent(), 'Jupyter markdown deletion lost highlighting.');
  gorge_runtime_product_contains('<span class="bright">new</span>',
    $diff->getNewContent(), 'Jupyter markdown insertion lost highlighting.');
  foreach (array($diff->getOldContent(), $diff->getNewContent()) as $html) {
    gorge_runtime_product_contains('&lt;value&gt;', $html,
      'Jupyter markdown failed to escape source HTML.');
  }
  gorge_runtime_product_assert(
    html_entity_decode(strip_tags((string)$diff->getOldContent()),
      ENT_QUOTES, 'UTF-8') === 'The old notebook <value>.' &&
    html_entity_decode(strip_tags((string)$diff->getNewContent()),
      ENT_QUOTES, 'UTF-8') === 'The new notebook <value>.',
    'Jupyter markdown mixed the old and new diff sides.');
  gorge_runtime_product_assert(
    phutil_json_decode(file_get_contents($directory.'/request.json')) ===
      array('old' => 'The old notebook <value>.',
        'new' => 'The new notebook <value>.'),
    'Jupyter markdown did not normalize source arrays for the HTTP request.');
} finally {
  unset($env);
  gorge_runtime_product_close_prose_fixture($process, $directory);
}

$old_block = id(new PhabricatorDocumentEngineBlock())->setContent(array(
  'cell_type' => 'code/line',
  'raw' => 'print("old <value>")',
  'display' => phutil_tag('span', array(), 'print("old <value>")'),
  'label' => 1,
  'head' => true,
  'last' => true,
));
$new_block = id(new PhabricatorDocumentEngineBlock())->setContent(array(
  'cell_type' => 'code/line',
  'raw' => 'print("new <value>")',
  'display' => phutil_tag('span', array(), 'print("new <value>")'),
  'label' => 1,
  'head' => true,
  'last' => true,
));
$diff = $jupyter->newBlockDiffViews(
  $old_ref, $old_block, $new_ref, $new_block);
gorge_runtime_product_contains('<span class="bright">old</span>',
  $diff->getOldContent(), 'Jupyter code deletion lost intraline highlighting.');
gorge_runtime_product_contains('<span class="bright">new</span>',
  $diff->getNewContent(), 'Jupyter code insertion lost intraline highlighting.');
foreach (array($diff->getOldContent(), $diff->getNewContent()) as $html) {
  gorge_runtime_product_contains('&lt;value&gt;', $html,
    'Jupyter code intraline rendering lost HTML escaping.');
}

$notebook = id(new PhabricatorDocumentRef())
  ->setName('valid.ipynb')
  ->setData(phutil_json_encode(array(
    'nbformat' => 4,
    'cells' => array(array(
      'cell_type' => 'markdown',
      'source' => array("第一段\n\n", '第二段'),
    )),
  )));
$blocks = $jupyter->newEngineBlocks($notebook);
gorge_runtime_product_assert($blocks->getMessages() === array(),
  'Valid nbformat 4 notebook returned an error.');
$cells = mpull($blocks->newOneUpLayout(), 'getContent');
gorge_runtime_product_assert(count($cells) === 2 &&
  $cells[0]['source'] === array('第一段') &&
  $cells[1]['source'] === array('第二段'),
  'Jupyter notebook paragraph blocks changed.');

$invalid = id(new PhabricatorDocumentRef())
  ->setName('invalid.ipynb')->setData('{"nbformat":');
$messages = $jupyter->newEngineBlocks($invalid)->getMessages();
gorge_runtime_product_assert(count($messages) === 1,
  'Invalid Jupyter JSON did not report exactly one error.');
gorge_runtime_product_contains('not a valid JSON document', $messages[0],
  'Invalid Jupyter JSON lost its diagnostic.');

$original_translator = PhutilTranslator::getInstance();
try {
  $translator = id(new PhutilTranslator())
    ->setLocale(PhutilLocale::loadLocale('zh_CN'))
    ->setTranslations(PhutilTranslation::getTranslationMapForLocale('zh_CN'));
  PhutilTranslator::setInstance($translator);
  gorge_runtime_product_assert(pht('Save Changes') === '保存更改',
    'Chinese translation was not dynamically loaded.');
  gorge_runtime_product_assert(
    (string)phutil_tag('button', array(), pht('Save Changes')) ===
      '<button>保存更改</button>',
    'Chinese translated HTML changed.');

  $markup = PhabricatorMarkupEngine::newPhrictionMarkupEngine();
  $markup->setConfig('viewer', $viewer);
  $html = (string)$markup->markupText(
    "= 文档标题 =\n\n**中文内容** [[https://example.test/|文档链接]]".
    "\n\n<script>alert(1)</script>");
  gorge_runtime_product_contains('<strong>中文内容</strong>', $html,
    'Document remarkup lost Chinese bold text.');
  gorge_runtime_product_contains('href="https://example.test/"', $html,
    'Document remarkup lost links.');
  gorge_runtime_product_contains('文档链接', $html,
    'Document remarkup lost Chinese link labels.');
  gorge_runtime_product_contains('&lt;script&gt;', $html,
    'Document remarkup no longer escapes raw script tags.');

  $code = '<?php'."\n".'$value = "<value>";';
  $html = (string)$markup->markupText("```lang=php\n".$code."\n```");
  gorge_runtime_product_contains('<span class=', $html,
    'Document PHP code blocks did not use real Gorge syntax highlighting.');
  gorge_runtime_product_assert(
    trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')) === $code,
    'Document PHP syntax highlighting changed the source text.');
  gorge_runtime_product_contains('&lt;', $html,
    'Document PHP syntax highlighting lost source HTML escaping.');
  gorge_runtime_product_assert(
    strpos($html, '<?php') === false && strpos($html, '<value>') === false,
    'Document PHP code blocks emitted raw source HTML.');
} finally {
  PhutilTranslator::setInstance($original_translator);
}

$password = new PhutilOpaqueEnvelope('runtime-product-password');
$hash = PhabricatorPasswordHasher::generateNewPasswordHash($password);
gorge_runtime_product_assert(
  PhabricatorPasswordHasher::comparePassword($password, $hash) &&
  !PhabricatorPasswordHasher::comparePassword(
    new PhutilOpaqueEnvelope('wrong-password'), $hash),
  'Login password verification did not accept/reject the expected inputs.');

$fact_engines = PhabricatorFactEngine::loadAllEngines();
$task_engine = null;
foreach ($fact_engines as $engine) {
  if ($engine instanceof PhabricatorManiphestTaskFactEngine) {
    $task_engine = $engine;
    break;
  }
}
gorge_runtime_product_assert($task_engine !== null,
  'The task Fact engine was not dynamically discovered.');
$fact_keys = mpull($task_engine->newFacts(), 'getKey');
foreach (array('tasks.count.create', 'tasks.open-count.status',
  'tasks.points.score') as $key) {
  gorge_runtime_product_assert(in_array($key, $fact_keys, true),
    'The task Fact engine lost fact '.$key.'.');
}
gorge_runtime_product_assert(
  $task_engine->supportsDatapointsForObject(new ManiphestTask()) &&
  !$task_engine->supportsDatapointsForObject(new PhabricatorUser()),
  'The task Fact engine no longer selects task objects.');
$scale = PhabricatorChartFunction::newFromDictionary(array(
  'function' => 'scale',
  'arguments' => array(3),
));
gorge_runtime_product_assert(
  $scale->evaluateFunction(array(1, 2)) === array(3, 6),
  'The dynamically loaded scale chart function changed.');
gorge_runtime_product_rejects(function() {
  PhabricatorChartFunction::newFromDictionary(array(
    'function' => 'runtime-product-unknown',
    'arguments' => array(),
  ));
}, 'Unknown chart functions were accepted.');
gorge_runtime_product_rejects(function() {
  PhabricatorChartFunction::newFromDictionary(array(
    'function' => 'scale',
    'arguments' => array('not-a-number'),
  ));
}, 'Non-numeric scale chart arguments were accepted.');

// Exact test files avoid ancestor suites which intentionally require storage.
// These exercise task status configuration, authentication primitives, real
// Differential rendering fixtures, document remarkup and translated HTML.
$paths = array(
  'src/applications/maniphest/constants/__tests__/ManiphestTaskStatusTestCase.php',
  'src/applications/auth/factor/__tests__/PhabricatorTOTPAuthFactorTestCase.php',
  'src/infrastructure/util/password/__tests__/PhabricatorPasswordHasherTestCase.php',
  'src/infrastructure/util/password/__tests__/PhabricatorIteratedMD5PasswordHasherTestCase.php',
  'src/applications/differential/__tests__/DifferentialParseRenderTestCase.php',
  'src/applications/differential/parser/__tests__/DifferentialHunkParserTestCase.php',
  'src/infrastructure/markup/__tests__/PhutilRemarkupEngineTestCase.php',
  'src/infrastructure/markup/__tests__/PhutilSafeHTMLTestCase.php',
  'src/infrastructure/markup/__tests__/PhutilTranslatedHTMLTestCase.php',
);
$expected_namespaces = array();
foreach ($paths as $key => $path) {
  $expected_namespaces[] = basename($path, '.php');
  $paths[$key] = $root.'/'.$path;
}
$engine = id(new PhutilUnitTestEngine())
  ->setWorkingCopy(ArcanistWorkingCopyIdentity::newFromPath($root))
  ->setPaths($paths)
  ->setRunAllTests(false)
  ->setEnableCoverage(false);
$results = $engine->run();
gorge_runtime_product_assert((bool)$results,
  'No retained product unit tests executed.');
$passed = 0;
$skipped = 0;
$failures = array();
$observed_namespaces = array();
foreach ($results as $result) {
  $observed_namespaces[$result->getNamespace()] = true;
  $test_name = $result->getNamespace().'::'.$result->getName();
  if ($result->getResult() === ArcanistUnitTestResult::RESULT_PASS) {
    $passed++;
    continue;
  }
  // The optional PHP operator extension is absent in standard deployments.
  // Safe HTML escaping is still asserted above and in the translation suite.
  if ($result->getResult() === ArcanistUnitTestResult::RESULT_SKIP &&
      $test_name === 'PhutilSafeHTMLTestCase::testOperator' &&
      !extension_loaded('operator')) {
    $skipped++;
    echo 'SKIP '.$test_name.': '.$result->getUserData()."\n";
    continue;
  }
  $failures[] = $test_name."\n".$result->getUserData();
}
$missing_namespaces = array_diff($expected_namespaces,
  array_keys($observed_namespaces));
gorge_runtime_product_assert(!$missing_namespaces,
  'Retained product unit test suites did not execute: '.
  implode(', ', $missing_namespaces));
if ($failures) {
  throw new RuntimeException('Retained product unit tests failed: '.
    implode("\n\n", $failures));
}
echo sprintf(
  "Retained product checks passed; %d existing unit tests passed, %d skipped.\n",
  $passed,
  $skipped);
LiskDAO::endIsolateAllLiskEffectsToCurrentProcess();
unset($product_env);

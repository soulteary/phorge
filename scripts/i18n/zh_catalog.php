<?php

// Internal bridge for translate_zh_api_batch.py. It does not call a model.
// Both operations use AST evaluation, never execute a supplied dictionary.
$root = dirname(__DIR__, 2);
require_once $root.'/support/runtime/bootstrap.php';
phutil_load_library($root.'/src');
PhutilErrorHandler::initialize();
ini_set('memory_limit', '512M');
if (!ini_get('date.timezone')) {
  date_default_timezone_set('UTC');
}

$parse_map = function($path) {
  $text = Filesystem::readFile($path);
  $parser = PhutilPHPParserLibrary::getParser();
  $tree = $parser->parse($text);
  $map_node = null;
  foreach ($tree as $node) {
    if (!($node instanceof PhpParser\Node\Stmt\Class_)) {
      continue;
    }
    foreach ($node->getMethods() as $method) {
      if ((string)$method->name !== 'getTranslations') {
        continue;
      }
      foreach ($method->stmts as $statement) {
        if ($statement instanceof PhpParser\Node\Stmt\Return_ &&
            $statement->expr instanceof PhpParser\Node\Expr\Array_) {
          $map_node = $statement->expr;
        }
      }
    }
  }
  if (!$map_node) {
    throw new RuntimeException('No static getTranslations() array in '.$path);
  }
  $keys = array();
  foreach ($map_node->items as $item) {
    $key = id(new PhpParser\ConstExprEvaluator())
      ->evaluateSilently($item->key);
    if (!is_string($key) || isset($keys[$key])) {
      throw new RuntimeException('Non-string or duplicate translation key.');
    }
    $keys[$key] = true;
  }
  return array(
    'translations' => id(new PhpParser\ConstExprEvaluator())
      ->evaluateSilently($map_node),
    'prefix' => substr($text, 0, $map_node->getStartFilePos()),
    'suffix' => substr($text, $map_node->getEndFilePos() + 1),
    'sha256' => hash('sha256', $text),
  );
};

try {
  $operation = idx($argv, 1);
  $path = idx($argv, 2);
  if (!$path || !in_array($operation, array('catalog', 'validate'))) {
    throw new RuntimeException('Expected: zh_catalog.php catalog|validate FILE');
  }
  $map = $parse_map($path);
  $validator = new PhorgeInternationalizationValidator();
  if ($operation === 'catalog') {
    $extractor = new PhabricatorInternationalizationManagementExtractWorkflow();
    $sources = array();
    ob_start();
    try {
      foreach (PhutilBootloader::getInstance()->getAllLibraries() as $library) {
        $base = phutil_get_library_root($library);
        $strings = $extractor->extractLibraryStrings($base);
        foreach ($strings as &$info) {
          foreach ($info['uses'] as &$use) {
            $use['file'] = Filesystem::resolvePath($use['file'], $base);
          }
          unset($use);
        }
        unset($info);
        $sources += $strings;
      }
    } finally {
      ob_end_clean();
    }
    foreach ($validator->getExtraSources() as $key => $source) {
      if (!isset($sources[$key])) {
        $sources[$key] = $source + array('uses' => array());
      }
    }
    unset($sources['']);
    ksort($sources);
    $map['sources'] = $sources;
    $map['translations'] = (object)$map['translations'];
    echo json_encode($map, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
  } else {
    $sources = json_decode(
      file_get_contents('php://stdin'), true, 512, JSON_THROW_ON_ERROR);
    $all = PhutilTranslation::getAllTranslations();
    $errors = $validator->validateTranslations(
      $sources,
      array(
        'zh_CN' => $map['translations'],
        'en_US' => idx($all, 'en_US', array()),
      ),
      array('zh_CN', 'en_US'));
    echo json_encode(array('errors' => $errors), JSON_THROW_ON_ERROR)."\n";
    exit($errors ? 1 : 0);
  }
} catch (Throwable $exception) {
  fwrite(STDERR, $exception->getMessage()."\n");
  exit(1);
}

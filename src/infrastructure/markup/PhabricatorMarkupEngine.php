<?php

/**
 * @task markup Markup Pipeline
 * @task engine Engine Construction
 * @task toc Table of Contents
 */
final class PhabricatorMarkupEngine extends Phobject {

  private $viewer;
  private $contextObjects = array();
  private $objects = array();
  private $config = array();
  private $toc;
  private $customInlineRule;
  private $customBlockRule;

  /**
   * @task engine
   */
  public function setViewer(PhabricatorUser $viewer) {
    $this->viewer = $viewer;
    return $this;
  }

  /**
   * @task engine
   */
  public function getViewer() {
    if (!$this->viewer) {
      throw new PhutilInvalidStateException('setViewer');
    }
    return $this->viewer;
  }

  /**
   * @task engine
   */
  public function setCustomInlineRule($rule) {
    $this->customInlineRule = $rule;
    return $this;
  }

  /**
   * @task engine
   */
  public function setCustomBlockRule($rule) {
    $this->customBlockRule = $rule;
    return $this;
  }

  public function setConfig($key, $value) {
    $this->config[$key] = $value;
    return $this;
  }

  public function getConfig($key, $default = null) {
    return idx($this->config, $key, $default);
  }

  /**
   * Set a list of context objects on the engine. This allows remarkup rules to
   * have access to custom objects when they are rendering. We need to pass
   * some information to remarkup this way because we can not use the normal
   * `setConfig()` mechanism, because rule configs are cached along with rule
   * outputs (so they must be reasonably small scalar values).
   *
   * @param list<Phobject> List of context objects.
   * @return this
   */
  public function setContextObjects(array $context_objects) {
    assert_instances_of($context_objects, 'Phobject');

    $map = array();
    foreach ($context_objects as $context_object) {
      $key = get_class($context_object);
      $map[$key] = $context_object;
    }

    $this->contextObjects = $map;

    return $this;
  }

  /**
   * Retrieve a context object by class name.
   *
   * @param string Class name.
   * @return Phobject|null Context object, or null if no context object of the
   *   requested class exists.
   */
  public function getContextObject($class) {
    return idx($this->contextObjects, $class);
  }

  /**
   * Load an object from a standard field specification.
   *
   * Phabricator has a large amount of Remarkup rendering code which uses
   * PhabricatorMarkupEngine as a high-level interface and sets configuration
   * for the lower-level PhutilRemarkupEngine. Keep this class focused on the
   * generic markup pipeline; syntax-highlighter-specific runtime flags are
   * configured by PhabricatorSyntaxHighlighter instead.
   */
  public function addObject(
    PhabricatorMarkupInterface $object,
    $field) {

    $key = $this->getMarkupFieldKey($object, $field);
    $this->objects[$key] = array($object, $field);

    return $this;
  }

  public function process() {
    foreach ($this->objects as $spec) {
      list($object, $field) = $spec;
      $this->processObject($object, $field);
    }
    return $this;
  }

  public function getOutput(
    PhabricatorMarkupInterface $object,
    $field) {

    return $this->getEngine($object, $field)->getOutput();
  }

  public function getTextOutput(
    PhabricatorMarkupInterface $object,
    $field) {

    return $this->getEngine($object, $field)->getTextOutput();
  }

  public function getEngine(
    PhabricatorMarkupInterface $object,
    $field) {

    $key = $this->getMarkupFieldKey($object, $field);
    if (!isset($this->objects[$key])) {
      $this->objects[$key] = array($object, $field);
    }

    return $this->processObject($object, $field);
  }

  public function getEngineMetadata(
    PhabricatorMarkupInterface $object,
    $field) {

    return $this->getEngine($object, $field)->getEngineMetadata();
  }

  public function getTableOfContents() {
    return $this->toc;
  }

  private function processObject(
    PhabricatorMarkupInterface $object,
    $field) {

    $key = $this->getMarkupFieldKey($object, $field);
    if (isset($this->objects[$key][2])) {
      return $this->objects[$key][2];
    }

    $engine = $this->buildEngine($object, $field);
    $engine->process();

    $this->objects[$key][2] = $engine;

    return $engine;
  }

  private function buildEngine(
    PhabricatorMarkupInterface $object,
    $field) {

    $spec = $object->getMarkupFieldSpecification($field);

    $engine = $this->newMarkupEngine($object, $field, $spec);

    $engine->setConfig('viewer', $this->getViewer());
    foreach ($this->config as $key => $value) {
      $engine->setConfig($key, $value);
    }

    if ($this->customInlineRule) {
      $engine->setCustomInlineRule($this->customInlineRule);
    }

    if ($this->customBlockRule) {
      $engine->setCustomBlockRule($this->customBlockRule);
    }

    return $engine;
  }

  private function newMarkupEngine(
    PhabricatorMarkupInterface $object,
    $field,
    array $spec) {

    $engine = new PhutilRemarkupEngine();

    $engine->setConfig('viewer', $this->getViewer());

    $engine->setConfig(
      'header.generate-toc',
      idx($spec, 'generate-toc', false));

    $engine->setConfig(
      'preserve-linebreaks',
      idx($spec, 'preserve-linebreaks', false));

    $engine->setConfig(
      'header.header-depth',
      idx($spec, 'header-depth', 3));

    $engine->setConfig(
      'header.base-level',
      idx($spec, 'header-base-level', 1));

    $engine->setConfig(
      'header.anchor-prefix',
      idx($spec, 'header-anchor-prefix', null));

    $engine->setConfig(
      'table-of-contents',
      idx($spec, 'table-of-contents', false));

    $engine->setConfig(
      'syntax-highlighter.engine',
      PhabricatorSyntaxHighlighter::newEngine());

    $engine->setConfig(
      'syntax.filemap',
      PhabricatorEnv::getEnvConfig('syntax.filemap'));

    $engine->setConfig(
      'phabricator.remarkup-objects',
      $this->contextObjects);

    $engine->setConfig(
      'phabricator.remarkup-object',
      $object);

    $engine->setConfig(
      'phabricator.remarkup-field',
      $field);

    if (idx($spec, 'disable-cache')) {
      $engine->setConfig('disable-cache', true);
    }

    if (idx($spec, 'simple')) {
      $rules = array(
        new PhutilRemarkupEscapeRemarkupRule(),
        new PhutilRemarkupMonospacedRule(),
        new PhutilRemarkupHyperlinkRule(),
      );
      $engine->setBlockRules($rules);
      $engine->setInlineRules(array());
    }

    if (idx($spec, 'no-blocks')) {
      $engine->setConfig('preserve-linebreaks', true);
      $engine->setBlockRules(array());
    }

    return $engine;
  }

  private function getMarkupFieldKey(
    PhabricatorMarkupInterface $object,
    $field) {

    return spl_object_hash($object).':'.$field;
  }

}

<?php

/**
 * Syntax highlighter engine which highlights most languages with the Gorge
 * render service.
 *
 * Select this engine with the "syntax-highlighter.engine" option. Languages
 * the default engine handles better on its own, and languages the service
 * can not improve on, continue to use local presentation helpers. Switching back
 * is a matter of restoring the option.
 *
 * This composes @{class:PhutilDefaultSyntaxHighlighterEngine} rather than
 * extending it because that class is final.
 */
final class PhabricatorGorgeSyntaxHighlighterEngine
  extends PhutilSyntaxHighlighterEngine {

  private $config = array();

  public function setConfig($key, $value) {
    $this->config[$key] = $value;
    return $this;
  }

  public function getLanguageFromFilename($filename) {
    return $this->newDefaultEngine()->getLanguageFromFilename($filename);
  }

  public function getHighlightFuture($language, $source) {
    return $this->newDefaultEngine()->getHighlightFuture($language, $source);
  }

  private function newDefaultEngine() {
    $engine = new PhutilDefaultSyntaxHighlighterEngine();

    foreach ($this->config as $key => $value) {
      $engine->setConfig($key, $value);
    }

    return $engine;
  }

}

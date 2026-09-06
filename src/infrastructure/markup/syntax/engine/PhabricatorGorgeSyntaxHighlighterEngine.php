<?php

/**
 * Syntax highlighter engine which highlights most languages with the Gorge
 * render service.
 *
 * Select this engine with the "syntax-highlighter.engine" option. Languages
 * the default engine handles better on its own, and languages the service
 * can not improve on, continue to use the default engine, so switching back
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
    if ($language === null) {
      $language = PhutilLanguageGuesser::guessLanguage($source);
    }

    if ($this->shouldHighlightWithGorge($language)) {
      return id(new PhabricatorGorgeSyntaxHighlighter())
        ->setConfig('language', $language)
        ->getHighlightFuture($source);
    }

    return $this->newDefaultEngine()->getHighlightFuture($language, $source);
  }

  private function shouldHighlightWithGorge($language) {
    if ($language === null) {
      return false;
    }

    if (!PhabricatorGorgeRenderClient::isConfigured()) {
      return false;
    }

    // "text" and "txt" gain nothing from highlighting, and the rest of these
    // are either not real languages or are rendered by a local highlighter
    // which understands more about them than a general purpose lexer does.
    static $local_languages = array(
      'console' => true,
      'diviner' => true,
      'invisible' => true,
      'rainbow' => true,
      'remarkup' => true,
      'text' => true,
      'txt' => true,
    );

    if (isset($local_languages[$language])) {
      return false;
    }

    // XHPAST parses PHP instead of lexing it, so it highlights PHP better
    // than the service can, and it does it without a round trip.
    if ($language == 'php' && PhutilXHPASTBinary::isAvailable()) {
      return false;
    }

    return true;
  }

  private function newDefaultEngine() {
    $engine = new PhutilDefaultSyntaxHighlighterEngine();

    foreach ($this->config as $key => $value) {
      $engine->setConfig($key, $value);
    }

    return $engine;
  }

}

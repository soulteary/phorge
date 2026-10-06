<?php

final class PhutilDefaultSyntaxHighlighterEngine
  extends PhutilSyntaxHighlighterEngine {

  private $config = array();

  public function setConfig($key, $value) {
    $this->config[$key] = $value;
    return $this;
  }

  public function getLanguageFromFilename($filename) {
    static $default_map = array(
      '@\\.([^./]+)$@' => 1,
    );

    $maps = array();
    if (!empty($this->config['filename.map'])) {
      $maps[] = $this->config['filename.map'];
    }
    $maps[] = $default_map;

    foreach ($maps as $map) {
      foreach ($map as $regexp => $lang) {
        $matches = null;
        if (preg_match($regexp, $filename, $matches)) {
          if (is_numeric($lang)) {
            return idx($matches, $lang);
          } else {
            return $lang;
          }
        }
      }
    }

    return null;
  }

  public function getHighlightFuture($language, $source) {
    if ($language === null) {
      $language = PhutilLanguageGuesser::guessLanguage($source);
    }

    if ($language == 'console') {
      return id(new PhutilConsoleSyntaxHighlighter())
        ->getHighlightFuture($source);
    }

    if ($language == 'diviner' || $language == 'remarkup') {
      return id(new PhutilDivinerSyntaxHighlighter())
        ->getHighlightFuture($source);
    }

    if ($language == 'rainbow') {
      return id(new PhutilRainbowSyntaxHighlighter())
        ->getHighlightFuture($source);
    }

    if ($language == 'invisible') {
      return id(new PhutilInvisibleSyntaxHighlighter())
        ->getHighlightFuture($source);
    }

    if ($language !== null && $language !== 'text' && $language !== 'txt' &&
        PhabricatorGorgeRenderClient::isConfigured()) {
      return id(new PhabricatorGorgeSyntaxHighlighter())
        ->setConfig('language', $language)
        ->getHighlightFuture($source);
    }

    // Recovery mode returns escaped plain text; no parallel language lexer
    // or external highlighter implementation remains in this engine.
    return id(new PhutilDefaultSyntaxHighlighter())
      ->getHighlightFuture($source);
  }

}

<?php

/**
 * Syntax highlighter which delegates to the Gorge render service.
 *
 * The service returns HTML with Pygments-compatible CSS class names, so
 * existing stylesheets continue to work without modification.
 */
final class PhabricatorGorgeSyntaxHighlighter extends Phobject {

  private $config = array();

  public function setConfig($key, $value) {
    $this->config[$key] = $value;
    return $this;
  }

  public function getHighlightFuture($source) {
    $language = idx($this->config, 'language');

    if ($language) {
      // A PHP lexer only leaves the initial "text" state once it sees an
      // opening tag, so add one for fragments which lack it and drop the
      // line it lands on when the response comes back.
      $scrub = false;
      if ($language == 'php' && strpos($source, '<?') === false) {
        $source = "<?php\n".$source;
        $scrub = true;
      }

      $client = new PhabricatorGorgeRenderClient();

      return new PhabricatorGorgeHighlightFuture(
        $client->newHighlightFuture($source, $language),
        $client->getHighlightURI(),
        $scrub);
    }

    return id(new PhutilDefaultSyntaxHighlighter())
      ->getHighlightFuture($source);
  }

}

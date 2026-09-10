<?php

/**
 * Compatibility shim for callers which still name the old Pygments class.
 *
 * The Python/pygmentize runtime is no longer part of this fork. Advanced
 * highlighting is provided by PhabricatorGorgeSyntaxHighlighterEngine; an old
 * direct class reference degrades to the built-in plain highlighter instead of
 * spawning an external process.
 */
final class PhutilPygmentsSyntaxHighlighter extends Phobject {

  public function setConfig($key, $value) {
    return $this;
  }

  public function getHighlightFuture($source) {
    return id(new PhutilDefaultSyntaxHighlighter())
      ->getHighlightFuture($source);
  }

}

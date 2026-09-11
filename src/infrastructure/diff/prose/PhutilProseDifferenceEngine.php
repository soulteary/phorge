<?php

/**
 * Thin compatibility facade for prose diffs served by Gorge.
 *
 * The local recursive PHP implementation was retired once the bundled stack
 * made Gorge the required production diff engine. Keep the class name and the
 * small whitespace helper because other Phorge code and extensions may still
 * reference this API surface.
 */
final class PhutilProseDifferenceEngine extends Phobject {

  public function getDiff($u, $v) {
    if (!PhabricatorGorgeDiffClient::isEnabled()) {
      throw new Exception(
        pht(
          'Gorge prose diff generation is required, but the Gorge render '.
          'service or the "%s" switch is not enabled.',
          'gorge.diff.enabled'));
    }

    return id(new PhabricatorGorgeDiffClient())->generateProseDiff($u, $v);
  }

  public static function trimApart($input) {
    $parts = array();

    $length = strlen($input);

    $corpus = ltrim($input);
    $l_length = strlen($corpus);
    if ($l_length !== $length) {
      $parts[] = substr($input, 0, $length - $l_length);
    }

    $corpus = rtrim($corpus);
    $lr_length = strlen($corpus);

    if ($lr_length) {
      $parts[] = $corpus;
    }

    if ($lr_length !== $l_length) {
      $parts[] = substr($input, $lr_length - $l_length);
    }

    return $parts;
  }

}

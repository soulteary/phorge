<?php

/**
 * Utility class which encapsulates some shared behavior between different
 * applications which render diffs.
 *
 * @task config Configuring the Engine
 * @task diff Generating Diffs
 */
final class PhabricatorDifferenceEngine extends Phobject {

  private $oldName;
  private $newName;
  private $normalize;
  private $tryEncoding;

/* -(  Configuring the Engine  )--------------------------------------------- */

  public function setOldName($old_name) {
    $this->oldName = $old_name;
    return $this;
  }

  public function setNewName($new_name) {
    $this->newName = $new_name;
    return $this;
  }

  public function setNormalize($normalize) {
    $this->normalize = $normalize;
    return $this;
  }

  public function getNormalize() {
    return $this->normalize;
  }

  /**
   * Declare the encoding the inputs are stored in.
   *
   * Content read out of a repository is raw bytes in whatever encoding that
   * repository uses. The diff request is JSON, which is UTF-8 only, so bytes
   * which are not valid UTF-8 have to be converted before they can be sent.
   * The removed GNU implementation worked on bytes directly and left the
   * conversion to the caller's parser, so callers which read non-UTF-8
   * content must now declare its encoding here instead.
   */
  public function setTryEncoding($encoding) {
    $this->tryEncoding = $encoding;
    return $this;
  }

  public function getTryEncoding() {
    return $this->tryEncoding;
  }

/* -(  Generating Diffs  )--------------------------------------------------- */

  /**
   * Generate a raw diff from two raw files through Gorge.
   *
   * The bundled deployment now treats Gorge as the only production diff
   * implementation. The historical GNU `diff -U65535` fallback has been
   * removed so a service outage can not silently switch parsers or reintroduce
   * a host binary dependency.
   */
  public function generateRawDiffFromFileContent($old, $new) {
    if (!PhabricatorGorgeDiffClient::isEnabled()) {
      throw new Exception(
        pht(
          'Gorge diff generation is required, but the render service is '.
          'unavailable: either "%s" is not configured, or the Gorge service '.
          'policy for "render" is "%s". There is no native difference engine '.
          'to fall back to.',
          'gorge.render.uri',
          PhabricatorGorgeServiceSpec::POLICY_OFF));
    }

    $old = $this->newUTF8Content($old, 'old');
    $new = $this->newUTF8Content($new, 'new');

    return id(new PhabricatorGorgeDiffClient())->generateDiff(
      $old,
      $new,
      $this->oldName,
      $this->newName,
      $this->getNormalize());
  }

  /**
   * Make one side of the diff safe to put in a JSON request body.
   *
   * Returns the content unchanged when it is already UTF-8, which is every
   * caller except repositories with a declared legacy encoding. Otherwise the
   * declared encoding is used to convert it; without one there is nothing to
   * convert from, and failing here with the encoding named is far easier to
   * act on than the json_encode() error the request would otherwise raise
   * from inside the service client.
   */
  private function newUTF8Content($content, $which) {
    if (!strlen($content)) {
      return $content;
    }

    if (phutil_is_utf8($content)) {
      return $content;
    }

    $encoding = $this->getTryEncoding();
    if ($encoding === null || !strlen($encoding)) {
      throw new Exception(
        pht(
          'The %s side of this diff is not valid UTF-8 and no source '.
          'encoding was declared with "%s". Difference generation is served '.
          'over a JSON API, which can not carry arbitrary bytes, so content '.
          'in another encoding must be declared before it can be diffed. '.
          'For a repository, set its "%s" property.',
          $which,
          'setTryEncoding()',
          'encoding'));
    }

    return phutil_utf8_convert($content, 'UTF-8', $encoding);
  }

  public function generateChangesetFromFileContent($old, $new) {
    $diff = $this->generateRawDiffFromFileContent($old, $new);

    $changes = id(new ArcanistDiffParser())->parseDiff($diff);
    $diff = DifferentialDiff::newEphemeralFromRawChanges($changes);
    return head($diff->getChangesets());
  }

  public static function applyIntralineDiff($str, $intra_stack) {
    $buf = '';
    $p = $s = $e = 0;
    $highlight = $tag = $ent = false;
    $highlight_o = '<span class="bright">';
    $highlight_c = '</span>';

    $depth_in = '<span class="depth-in">';
    $depth_out = '<span class="depth-out">';

    $is_html = false;
    if ($str instanceof PhutilSafeHTML) {
      $is_html = true;
      $str = $str->getHTMLContent();
    }

    $n = strlen($str);
    for ($i = 0; $i < $n; $i++) {
      if ($p == $e) {
        do {
          if (empty($intra_stack)) {
            $buf .= substr($str, $i);
            break 2;
          }
          $stack = array_shift($intra_stack);
          $s = $e;
          $e += $stack[1];
        } while ($stack[0] === 0);

        switch ($stack[0]) {
          case '>':
            $open_tag = $depth_in;
            break;
          case '<':
            $open_tag = $depth_out;
            break;
          default:
            $open_tag = $highlight_o;
            break;
        }
      }

      if (!$highlight && !$tag && !$ent && $p == $s) {
        $buf .= $open_tag;
        $highlight = true;
      }

      if ($str[$i] == '<') {
        $tag = true;
        if ($highlight) {
          $buf .= $highlight_c;
        }
      }

      if (!$tag) {
        if ($str[$i] == '&') {
          $ent = true;
        }
        if ($ent && $str[$i] == ';') {
          $ent = false;
        }
        if (!$ent) {
          $p++;
        }
      }

      $buf .= $str[$i];

      if ($tag && $str[$i] == '>') {
        $tag = false;
        if ($highlight) {
          $buf .= $open_tag;
        }
      }

      if ($highlight && ($p == $e || $i == $n - 1)) {
        $buf .= $highlight_c;
        $highlight = false;
      }
    }

    if ($is_html) {
      return phutil_safe_html($buf);
    }

    return $buf;
  }

}

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
          'Gorge diff generation is required, but the Gorge render service '.
          'or the "%s" switch is not enabled.',
          'gorge.diff.enabled'));
    }

    return id(new PhabricatorGorgeDiffClient())->generateDiff(
      $old,
      $new,
      $this->oldName,
      $this->newName,
      $this->getNormalize());
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

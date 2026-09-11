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

    // Identical input is not a difference, whatever the content is. The
    // removed GNU path recognized this too -- `diff` exited 0 with no output
    // and it synthesized a changeless diff so the unchanged file could still
    // be rendered -- and it matters more now, because the binary shortcut
    // below would otherwise report an unchanged binary file (an SVN copy, for
    // instance) as differing from itself.
    //
    // The comparison is on raw bytes only, so files which differ but are
    // equivalent after normalization still go to the service, which applies
    // the normalize flag itself.
    if ($old === $new) {
      // The synthetic diff carries the file's own content, and callers are
      // told this engine emits UTF-8 -- diffusion.diffquery disables
      // parser-side conversion on the strength of that -- so legacy-encoded
      // text has to be converted here too. Binary content is passed through:
      // there is nothing to convert it from, and the removed implementation
      // embedded the raw bytes in this case as well.
      $content = $old;
      if (!self::isBinaryContent($content)) {
        $content = $this->newUTF8Content($content, 'old');
      }

      return $this->newUnchangedDiff($content);
    }

    // Binary content can not go through the service at all: the request body
    // is JSON, and even where an encoding is declared, running arbitrary
    // bytes through a text conversion corrupts them rather than diffing them.
    // The removed GNU path did not diff binaries either -- `diff` printed a
    // "Binary files ... differ" line and the parser turned that into a binary
    // changeset -- so produce that same line here instead of calling out.
    if (self::isBinaryContent($old) || self::isBinaryContent($new)) {
      return $this->newBinaryDiff();
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
   * Detect content which must not be treated as text.
   *
   * This is the same rule GNU `diff` applies: a NUL byte means binary. It is
   * deliberately not "invalid UTF-8", because a legacy-encoded text file is
   * invalid UTF-8 and is still text -- it goes through the declared encoding
   * instead, see newUTF8Content().
   */
  public static function isBinaryContent($content) {
    return (strpos($content, "\0") !== false);
  }

  /**
   * Build the changeless diff two identical files produce.
   *
   * This is the synthetic all-context diff the removed GNU path built when
   * `diff` reported no differences: it keeps the unchanged file renderable
   * instead of reducing it to "this file did not change", which callers can
   * not display because they do not hold the content themselves.
   */
  private function newUnchangedDiff($content) {
    $old_name = nonempty($this->oldName, '/dev/universe').' 9999-99-99';
    $new_name = nonempty($this->newName, '/dev/universe').' 9999-99-99';

    $lines = explode("\n", $content);
    foreach ($lines as $key => $line) {
      $lines[$key] = ' '.$line;
    }

    $len = count($lines);
    $lines = implode("\n", $lines);

    // NOTE: Inherited from the implementation this replaces: if both files
    // were identical but missing trailing newlines, the counts here are
    // probably wrong. It was never established that this matters.
    return "--- {$old_name}\n".
           "+++ {$new_name}\n".
           "@@ -1,{$len} +1,{$len} @@\n".
           $lines."\n";
  }

  /**
   * Build the marker the diff parser turns into a binary changeset.
   *
   * The wording matches what GNU `diff` emitted on the removed path, which is
   * what ArcanistDiffParser::setDetectBinaryFiles() was written to recognize.
   * Callers which force a path on the parser -- `diffusion.diffquery` does --
   * get their own path back regardless of the names used here.
   */
  private function newBinaryDiff() {
    $old_name = nonempty($this->oldName, '/dev/universe');
    $new_name = nonempty($this->newName, '/dev/universe');

    return "Binary files {$old_name} and {$new_name} differ\n";
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

<?php

/** Pure serialization shared by synchronous adapters and projection exports. */
final class PhabricatorSearchDocumentSerializer extends Phobject {

  const VERSION = 'fulltext-2026-10-a';

  public static function newDocumentSpec(PhabricatorSearchAbstractDocument $doc) {
    $spec = array(
      'phid' => $doc->getPHID(),
      'type' => $doc->getDocumentType(),
      'title' => (string)$doc->getDocumentTitle(),
      'dateCreated' => (int)$doc->getDocumentCreated(),
      'dateModified' => (int)$doc->getDocumentModified(),
    );

    // Fields arrive as a list of tuples rather than a map because a document
    // may carry several corpora for one field name -- every comment on a task
    // is another "cmnt" field -- so they are kept as a list on the wire too
    // and grouped by the service.
    $fields = array();
    foreach ($doc->getFieldData() as $field) {
      list($field_name, $corpus, $aux) = $field;

      $item = array(
        'name' => $field_name,
        'corpus' => (string)$corpus,
      );

      if ($aux !== null) {
        $item['aux'] = $aux;
      }

      $fields[] = $item;
    }

    if ($fields) {
      $spec['fields'] = $fields;
    }

    $relationships = array();
    foreach ($doc->getRelationshipData() as $relationship) {
      list($field_name, $related_phid, $rtype, $time) = $relationship;

      $item = array(
        'name' => $field_name,
        'relatedPHID' => $related_phid,
        'rtype' => $rtype,
      );

      if ($time) {
        $item['timestamp'] = (int)$time;
      }

      $relationships[] = $item;
    }

    if ($relationships) {
      $spec['relationships'] = $relationships;
    }

    return $spec;
  }


  public static function getDocumentHash(PhabricatorSearchAbstractDocument $doc) {
    $spec = self::newDocumentSpec($doc);
    // Match DocumentField's omitempty without changing the legacy wire serializer.
    if (isset($spec['fields'])) {
      foreach ($spec['fields'] as $key => $field) {
        if (idx($field, 'aux') === '') {
          unset($spec['fields'][$key]['aux']);
        }
      }
    }
    $spec = self::sortObjectKeys($spec);
    $json = json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
      throw new InvalidArgumentException(pht('Invalid UTF-8 search document.'));
    }
    return hash('sha256', $json);
  }

  private static function sortObjectKeys(array $value) {
    foreach ($value as $key => $item) {
      if (is_array($item)) {
        $value[$key] = self::sortObjectKeys($item);
      }
    }
    if ($value && array_keys($value) !== range(0, count($value) - 1)) {
      ksort($value, SORT_STRING);
    }
    return $value;
  }

}

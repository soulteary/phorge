<?php

abstract class PhabricatorFulltextEngine
  extends Phobject {

  private $object;
  private $localFulltextExtensions;

  public function setObject($object) {
    $this->object = $object;
    $this->localFulltextExtensions = null;
    return $this;
  }

  public function getObject() {
    return $this->object;
  }

  protected function getViewer() {
    return PhabricatorUser::getOmnipotentUser();
  }

  abstract protected function buildAbstractDocument(
    PhabricatorSearchAbstractDocument $document,
    $object);

  /** Build a projection without updating local indexes or contacting a backend. */
  final public function buildFulltextDocument() {
    $object = $this->getObject();
    $enrich_extensions = array();
    $this->localFulltextExtensions = array();
    foreach ($this->newFulltextExtensions() as $extension) {
      if ($extension->shouldEnrichFulltextObject($object)) {
        $enrich_extensions[] = $extension;
      }
      if ($extension->shouldIndexFulltextObject($object)) {
        $this->localFulltextExtensions[] = $extension;
      }
    }
    $document = $this->newAbstractDocument($object);
    $this->buildAbstractDocument($document, $object);
    foreach ($enrich_extensions as $extension) {
      $extension->enrichFulltextObject($object, $document);
    }

    return $document;
  }

  /** Apply the local extensions to an already-built document. */
  final public function indexLocalFulltextDocument(
    PhabricatorSearchAbstractDocument $document) {
    $object = $this->getObject();
    if ($document->getPHID() !== $object->getPHID()) {
      throw new InvalidArgumentException(
        pht('Fulltext document does not belong to this object.'));
    }

    $extensions = $this->localFulltextExtensions;
    if ($extensions === null) {
      $extensions = array();
      foreach ($this->newFulltextExtensions() as $extension) {
        if ($extension->shouldIndexFulltextObject($object)) {
          $extensions[] = $extension;
        }
      }
    }
    foreach ($extensions as $extension) {
      $extension->indexFulltextObject($object, $document);
    }
  }

  /** Existing callers retain build, local indexing and publication ordering. */
  final public function buildFulltextIndexes() {
    $document = $this->buildFulltextDocument();
    $this->indexLocalFulltextDocument($document);
    PhabricatorSearchService::reindexAbstractDocument($document);
  }

  protected function newFulltextExtensions() {
    return PhabricatorFulltextEngineExtension::getAllExtensions();
  }

  protected function newAbstractDocument($object) {
    $phid = $object->getPHID();
    return id(new PhabricatorSearchAbstractDocument())
      ->setPHID($phid)
      ->setDocumentType(phid_get_type($phid));
  }

}

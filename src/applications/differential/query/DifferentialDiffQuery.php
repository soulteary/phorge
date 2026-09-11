<?php

/**
 * @extends PhabricatorCursorPagedPolicyAwareQuery<DifferentialDiff>
 */
final class DifferentialDiffQuery
  extends PhabricatorCursorPagedPolicyAwareQuery {

  private $ids;
  private $phids;
  private $commitPHIDs;

  private $needChangesets = false;
  private $needProperties;

  public function withIDs(array $ids) {
    $this->ids = $ids;
    return $this;
  }

  public function withPHIDs(array $phids) {
    $this->phids = $phids;
    return $this;
  }

  public function withCommitPHIDs(array $phids) {
    $this->commitPHIDs = $phids;
    return $this;
  }

  public function needChangesets($bool) {
    $this->needChangesets = $bool;
    return $this;
  }

  public function needProperties($need_properties) {
    $this->needProperties = $need_properties;
    return $this;
  }

  public function newResultObject() {
    return new DifferentialDiff();
  }

  protected function willFilterPage(array $diffs) {
    // This used to load each diff's revision, attach it, and drop diffs whose
    // revision the viewer could not see. With revisions removed, a diff's
    // visibility is its own: see DifferentialDiff::getPolicy(), which now
    // returns the diff's view policy directly.

    if ($diffs && $this->needChangesets) {
      $diffs = $this->loadChangesets($diffs);
    }

    return $diffs;
  }

  protected function didFilterPage(array $diffs) {
    if ($this->needProperties) {
      $properties = id(new DifferentialDiffProperty())->loadAllWhere(
        'diffID IN (%Ld)',
        mpull($diffs, 'getID'));

      $properties = mgroup($properties, 'getDiffID');
      foreach ($diffs as $diff) {
        $map = idx($properties, $diff->getID(), array());
        $map = mpull($map, 'getData', 'getName');
        $diff->attachDiffProperties($map);
      }
    }

    return $diffs;
  }

  private function loadChangesets(array $diffs) {
    id(new DifferentialChangesetQuery())
      ->setViewer($this->getViewer())
      ->setParentQuery($this)
      ->withDiffs($diffs)
      ->needAttachToDiffs(true)
      ->needHunks(true)
      ->execute();

    return $diffs;
  }

  protected function buildWhereClauseParts(AphrontDatabaseConnection $conn) {
    $where = parent::buildWhereClauseParts($conn);

    if ($this->ids !== null) {
      $where[] = qsprintf(
        $conn,
        'id IN (%Ld)',
        $this->ids);
    }

    if ($this->phids !== null) {
      $where[] = qsprintf(
        $conn,
        'phid IN (%Ls)',
        $this->phids);
    }

    if ($this->commitPHIDs !== null) {
      $where[] = qsprintf(
        $conn,
        'commitPHID IN (%Ls)',
        $this->commitPHIDs);
    }

    return $where;
  }

  public function getQueryApplicationClass() {
    return PhabricatorDiffusionApplication::class;
  }

}

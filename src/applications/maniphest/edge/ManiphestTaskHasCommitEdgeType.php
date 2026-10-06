<?php

final class ManiphestTaskHasCommitEdgeType extends PhabricatorEdgeType {

  const EDGECONST = 1;

  // This edge used to be half of an inverse pair with
  // DiffusionCommitHasTaskEdgeType. That type is gone, and
  // PhabricatorEdgeType::getAllTypes() throws when a type names an inverse
  // which does not exist, so declaring one here would fatal every caller of
  // that method. The base class returns null, meaning "no inverse", and with
  // no inverse there is nothing to write inverse transactions to.

  public function getConduitKey() {
    return 'task.commit';
  }

  public function getConduitName() {
    return pht('Task Has Commit');
  }

  public function getConduitDescription() {
    return pht('The source task is associated with the destination commit.');
  }

  public function getTransactionAddString(
    $actor,
    $add_count,
    $add_edges) {

    return pht(
      '%s added %s commit(s): %s.',
      $actor,
      $add_count,
      $add_edges);
  }

  public function getTransactionRemoveString(
    $actor,
    $rem_count,
    $rem_edges) {

    return pht(
      '%s removed %s commit(s): %s.',
      $actor,
      $rem_count,
      $rem_edges);
  }

  public function getTransactionEditString(
    $actor,
    $total_count,
    $add_count,
    $add_edges,
    $rem_count,
    $rem_edges) {

    return pht(
      '%s edited %s commit(s), added %s: %s; removed %s: %s.',
      $actor,
      $total_count,
      $add_count,
      $add_edges,
      $rem_count,
      $rem_edges);
  }

  public function getFeedAddString(
    $actor,
    $object,
    $add_count,
    $add_edges) {

    return pht(
      '%s added %s commit(s) to %s: %s.',
      $actor,
      $add_count,
      $object,
      $add_edges);
  }

  public function getFeedRemoveString(
    $actor,
    $object,
    $rem_count,
    $rem_edges) {

    return pht(
      '%s removed %s commit(s) from %s: %s.',
      $actor,
      $rem_count,
      $object,
      $rem_edges);
  }

  public function getFeedEditString(
    $actor,
    $object,
    $total_count,
    $add_count,
    $add_edges,
    $rem_count,
    $rem_edges) {

    return pht(
      '%s edited %s commit(s) for %s, added %s: %s; removed %s: %s.',
      $actor,
      $total_count,
      $object,
      $add_count,
      $add_edges,
      $rem_count,
      $rem_edges);
  }

}

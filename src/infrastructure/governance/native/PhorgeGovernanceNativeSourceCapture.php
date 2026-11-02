<?php

/** Bounded native observations; private bindings are not public evidence. */
final class PhorgeGovernanceNativeSourceCapture extends Phobject {

  private $snapshot;
  private $summary;

  public function __construct(
    PhorgeGovernanceReadSetSnapshot $snapshot,
    array $summary) {
    if (($summary['status'] ?? null) !== 'native-observed-blocked' ||
        ($summary['native_closure_complete'] ?? null) !== false ||
        ($summary['can_execute'] ?? null) !== false ||
        ($summary['execution_atomicity_proved'] ?? null) !== false) {
      throw new InvalidArgumentException('Native observations cannot certify execution.');
    }
    $this->snapshot = $snapshot;
    // The reader constructs only scalar/array evidence, never caller metadata.
    $this->summary = json_decode(json_encode($summary, JSON_THROW_ON_ERROR), true);
  }

  /** Restricted internal use: contains native principal and session references. */
  public function getSnapshot() {
    return $this->snapshot;
  }

  public function getSources() {
    return $this->snapshot->getSources();
  }

  public function getSummary() {
    return $this->summary;
  }

}

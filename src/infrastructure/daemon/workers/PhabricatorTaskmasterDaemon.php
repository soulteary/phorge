<?php

/**
 * Retired compatibility daemon for persisted/manual taskmaster references.
 *
 * Gorge owns task leasing, retries and archive bookkeeping. PHP worker task
 * classes remain live because gorge-worker delegates business execution back
 * through `worker.execute`, but this process must never lease SQL tasks itself.
 */
final class PhabricatorTaskmasterDaemon extends PhabricatorDaemon {

  protected function run() {
    throw new Exception(
      pht(
        'PhabricatorTaskmasterDaemon has been retired. Configure the Gorge '.
        'taskqueue/worker services and keep "%s" at 0.',
        'phd.taskmasters'));
  }

}

<?php

final class PhabricatorRepositoryCommitPublishWorker
  extends PhabricatorRepositoryCommitParserWorker {

  protected function getImportStepFlag() {
    return PhabricatorRepositoryCommit::IMPORTED_PUBLISH;
  }

  public function getRequiredLeaseTime() {
    // Herald rules may take a long time to process.
    return phutil_units('4 hours in seconds');
  }

  protected function parseCommit(
    PhabricatorRepository $repository,
    PhabricatorRepositoryCommit $commit) {

    if (!$this->shouldSkipImportStep()) {
      $this->publishCommit($repository, $commit);
      $commit->writeImportStatusFlag($this->getImportStepFlag());
    }

    // This is the last task in the sequence, so we don't need to queue any
    // followup workers.
  }

  private function publishCommit(
    PhabricatorRepository $repository,
    PhabricatorRepositoryCommit $commit) {
    $viewer = PhabricatorUser::getOmnipotentUser();

    $commit_phid = $commit->getPHID();

    // Reload the commit to get the commit data, identities, and any
    // outstanding audit requests.
    $commit = id(new DiffusionCommitQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($commit_phid))
      ->needCommitData(true)
      ->needIdentities(true)
      ->needAuditRequests(true)
      ->executeOne();
    if (!$commit) {
      throw new PhabricatorWorkerPermanentFailureException(
        pht(
          'Failed to reload commit "%s".',
          $commit_phid));
    }

    $publisher = $repository->newPublisher();
    $should_publish = $publisher->shouldPublishCommit($commit);

    if (!$should_publish) {
      $hold_reasons = $publisher->getCommitHoldReasons($commit);
    } else {
      $hold_reasons = array();
    }

    $data = $commit->getCommitData();
    if ($data->getCommitDetail('holdReasons') !== $hold_reasons) {
      $data->setCommitDetail('holdReasons', $hold_reasons);
      $data->save();
    }

    if (!$should_publish) {
      return;
    }

    // NOTE: Close revisions and tasks before applying transactions, because
    // we want a side effect of closure (the commit being associated with
    // a revision) to occur before a side effect of transactions (Herald
    // executing). The close methods queue tasks for the actual updates to
    // commits/revisions, so those won't occur until after the commit gets
    // transactions.

    $this->closeRevisions($viewer, $commit);
    $this->closeTasks($viewer, $commit);

    $this->applyTransactions($viewer, $repository, $commit);
  }

  private function applyTransactions(
    PhabricatorUser $actor,
    PhabricatorRepository $repository,
    PhabricatorRepositoryCommit $commit) {

    $xactions = $this->newPublishTransactions($commit);

    $acting_phid = $this->getPublishAsPHID($commit);
    $content_source = $this->newContentSource();

    $revision = DiffusionCommitRevisionQuery::loadRevisionForCommit(
      $actor,
      $commit);

    // Prevent the commit from generating a mention of the associated
    // revision, if one exists, so we don't double up because of the URI
    // in the commit message.
    $unmentionable_phids = array();
    if ($revision) {
      $unmentionable_phids[] = $revision->getPHID();
    }

    $editor = $commit->getApplicationTransactionEditor()
      ->setActor($actor)
      ->setActingAsPHID($acting_phid)
      ->setContinueOnNoEffect(true)
      ->setContinueOnMissingFields(true)
      ->setContentSource($content_source)
      ->addUnmentionablePHIDs($unmentionable_phids);

    try {
      $raw_patch = $this->loadRawPatchText($repository, $commit);
    } catch (Exception $ex) {
      $raw_patch = pht('Unable to generate patch: %s', $ex->getMessage());
    }
    $editor->setRawPatch($raw_patch);

    $editor->applyTransactions($commit, $xactions);
  }

  private function getPublishAsPHID(PhabricatorRepositoryCommit $commit) {
    if ($commit->hasCommitterIdentity()) {
      return $commit->getCommitterIdentity()->getIdentityDisplayPHID();
    }

    if ($commit->hasAuthorIdentity()) {
      return $commit->getAuthorIdentity()->getIdentityDisplayPHID();
    }

    return id(new PhabricatorDiffusionApplication())->getPHID();
  }

  private function newPublishTransactions(PhabricatorRepositoryCommit $commit) {
    $data = $commit->getCommitData();

    $xactions = array();

    $xactions[] = $commit->getApplicationTransactionTemplate()
      ->setTransactionType(PhorgeAuditCommitCommitTransaction::TRANSACTIONTYPE)
      ->setDateCreated($commit->getEpoch())
      ->setNewValue(
        array(
          'description'   => $data->getCommitMessage(),
          'summary'       => $data->getSummary(),
          'authorName'    => $data->getAuthorString(),
          'authorPHID'    => $commit->getAuthorPHID(),
          'committerName' => $data->getCommitterString(),
          'committerPHID' => $data->getCommitDetail('committerPHID'),
        ));

    return $xactions;
  }

  private function loadRawPatchText(
    PhabricatorRepository $repository,
    PhabricatorRepositoryCommit $commit) {
    $viewer = PhabricatorUser::getOmnipotentUser();

    $identifier = $commit->getCommitIdentifier();

    $drequest = DiffusionRequest::newFromDictionary(
      array(
        'user' => $viewer,
        'repository' => $repository,
      ));

    $time_key = 'metamta.diffusion.time-limit';
    $byte_key = 'metamta.diffusion.byte-limit';
    $time_limit = PhabricatorEnv::getEnvConfig($time_key);
    $byte_limit = PhabricatorEnv::getEnvConfig($byte_key);

    $diff_info = DiffusionQuery::callConduitWithDiffusionRequest(
      $viewer,
      $drequest,
      'diffusion.rawdiffquery',
      array(
        'commit' => $identifier,
        'linesOfContext' => 3,
        'timeout' => $time_limit,
        'byteLimit' => $byte_limit,
      ));

    if ($diff_info['tooSlow']) {
      throw new Exception(
        pht(
          'Patch generation took longer than configured limit ("%s") of '.
          '%s second(s).',
          $time_key,
          new PhutilNumber($time_limit)));
    }

    if ($diff_info['tooHuge']) {
      $pretty_limit = phutil_format_bytes($byte_limit);
      throw new Exception(
        pht(
          'Patch size exceeds configured byte size limit ("%s") of %s.',
          $byte_key,
          $pretty_limit));
    }

    $file_phid = $diff_info['filePHID'];
    $file = id(new PhabricatorFileQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($file_phid))
      ->executeOne();
    if (!$file) {
      throw new Exception(
        pht(
          'Failed to load file ("%s") returned by "%s".',
          $file_phid,
          'diffusion.rawdiffquery'));
    }

    return $file->loadFileData();
  }

  private function closeRevisions(
    PhabricatorUser $actor,
    PhabricatorRepositoryCommit $commit) {

    $differential_class = PhabricatorDifferentialApplication::class;
    if (!PhabricatorApplication::isClassInstalled($differential_class)) {
      return;
    }

    $repository = $commit->getRepository();
    $data = $commit->getCommitData();
    $ref = $data->getCommitRef();

    $field_query = id(new DiffusionLowLevelCommitFieldsQuery())
      ->setRepository($repository)
      ->withCommitRef($ref);

    $field_values = $field_query->execute();

    $revision_id = idx($field_values, 'revisionID');
    if (!$revision_id) {
      return;
    }

    $revision = id(new DifferentialRevisionQuery())
      ->setViewer($actor)
      ->withIDs(array($revision_id))
      ->executeOne();
    if (!$revision) {
      return;
    }

    // NOTE: This is very old code from when revisions had a single reviewer.
    // It still powers the "Reviewer (Deprecated)" field in Herald, but should
    // be removed.
    if (!empty($field_values['reviewedByPHIDs'])) {
      $data->setCommitDetail(
        'reviewerPHID',
        head($field_values['reviewedByPHIDs']));
    }

    $match_data = $field_query->getRevisionMatchData();

    $data->setCommitDetail('differential.revisionID', $revision_id);
    $data->setCommitDetail('revisionMatchData', $match_data);

    $data->save();

    $properties = array(
      'revisionMatchData' => $match_data,
    );
    $this->queueObjectUpdate($commit, $revision, $properties);
  }

  private function closeTasks(
    PhabricatorUser $actor,
    PhabricatorRepositoryCommit $commit) {

    $maniphest = 'PhabricatorManiphestApplication';
    if (!PhabricatorApplication::isClassInstalled($maniphest)) {
      return;
    }

    $data = $commit->getCommitData();

    $prefixes = ManiphestTaskStatus::getStatusPrefixMap();
    $suffixes = ManiphestTaskStatus::getStatusSuffixMap();
    $message = $data->getCommitMessage();

    $matches = id(new ManiphestCustomFieldStatusParser())
      ->parseCorpus($message);

    $task_map = array();
    foreach ($matches as $match) {
      $prefix = phutil_utf8_strtolower($match['prefix']);
      $suffix = phutil_utf8_strtolower($match['suffix']);

      $status = idx($suffixes, $suffix);
      if (!$status) {
        $status = idx($prefixes, $prefix);
      }

      foreach ($match['monograms'] as $task_monogram) {
        $task_id = (int)trim($task_monogram, 'tT');
        $task_map[$task_id] = $status;
      }
    }

    if (!$task_map) {
      return;
    }

    $tasks = id(new ManiphestTaskQuery())
      ->setViewer($actor)
      ->withIDs(array_keys($task_map))
      ->execute();
    foreach ($tasks as $task_id => $task) {
      $status = $task_map[$task_id];

      $properties = array(
        'status' => $status,
      );

      $this->queueObjectUpdate($commit, $task, $properties);
    }
  }

  private function queueObjectUpdate(
    PhabricatorRepositoryCommit $commit,
    $object,
    array $properties) {

    $this->queueTask(
      'DiffusionUpdateObjectAfterCommitWorker',
      array(
        'commitPHID' => $commit->getPHID(),
        'objectPHID' => $object->getPHID(),
        'properties' => $properties,
      ),
      array(
        'priority' => PhabricatorWorker::PRIORITY_DEFAULT,
      ));
  }

}

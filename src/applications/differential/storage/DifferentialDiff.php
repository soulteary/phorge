<?php

final class DifferentialDiff
  extends DifferentialDAO
  implements
    PhabricatorPolicyInterface,
    PhabricatorExtendedPolicyInterface,
    PhabricatorDestructibleInterface,
    PhabricatorConduitResultInterface {

  protected $revisionID;
  protected $authorPHID;
  protected $repositoryPHID;
  protected $commitPHID;

  protected $sourceMachine;
  protected $sourcePath;

  protected $sourceControlSystem;
  protected $sourceControlBaseRevision;
  protected $sourceControlPath;

  protected $lintStatus;
  protected $unitStatus;

  protected $lineCount;

  protected $branch;
  protected $bookmark;

  protected $creationMethod;
  protected $repositoryUUID;

  protected $description;

  protected $viewPolicy;

  private $unsavedChangesets = array();
  private $changesets = self::ATTACHABLE;
  private $properties = self::ATTACHABLE;

  private $unitMessages = self::ATTACHABLE;

  protected function getConfiguration() {
    return array(
      self::CONFIG_AUX_PHID => true,
      self::CONFIG_COLUMN_SCHEMA => array(
        'revisionID' => 'id?',
        'authorPHID' => 'phid?',
        'repositoryPHID' => 'phid?',
        'sourceMachine' => 'text255?',
        'sourcePath' => 'text255?',
        'sourceControlSystem' => 'text64?',
        'sourceControlBaseRevision' => 'text255?',
        'sourceControlPath' => 'text255?',
        'lintStatus' => 'uint32',
        'unitStatus' => 'uint32',
        'lineCount' => 'uint32',
        'branch' => 'text255?',
        'bookmark' => 'text255?',
        'repositoryUUID' => 'text64?',
        'commitPHID' => 'phid?',

        // T6203/NULLABILITY
        // These should be non-null; all diffs should have a creation method
        // and the description should just be empty.
        'creationMethod' => 'text255?',
        'description' => 'text255?',
      ),
      self::CONFIG_KEY_SCHEMA => array(
        'revisionID' => array(
          'columns' => array('revisionID'),
        ),
        'key_commit' => array(
          'columns' => array('commitPHID'),
        ),
      ),
    ) + parent::getConfiguration();
  }

  public function generatePHID() {
    return PhabricatorPHID::generateNewPHID(
      DifferentialDiffPHIDType::TYPECONST);
  }

  public function addUnsavedChangeset(DifferentialChangeset $changeset) {
    if ($this->changesets === null) {
      $this->changesets = array();
    }
    $this->unsavedChangesets[] = $changeset;
    $this->changesets[] = $changeset;
    return $this;
  }

  /**
   * @param array<DifferentialChangeset> $changesets
   */
  public function attachChangesets(array $changesets) {
    assert_instances_of($changesets, DifferentialChangeset::class);
    $this->changesets = $changesets;
    return $this;
  }

  public function getChangesets() {
    return $this->assertAttached($this->changesets);
  }

  public function loadChangesets() {
    if (!$this->getID()) {
      return array();
    }
    $changesets = id(new DifferentialChangeset())->loadAllWhere(
      'diffID = %d',
      $this->getID());

    foreach ($changesets as $changeset) {
      $changeset->attachDiff($this);
    }

    return $changesets;
  }

  public function save() {
    $this->openTransaction();
      $ret = parent::save();
      foreach ($this->unsavedChangesets as $changeset) {
        $changeset->setDiffID($this->getID());
        $changeset->save();
      }
    $this->saveTransaction();
    return $ret;
  }

  public static function initializeNewDiff(PhabricatorUser $actor) {
    // This used to read a default view policy from the Differential
    // application's "differential.default.view" capability. That application
    // has been removed, and no other one holds the capability, so the policy
    // it defaulted to is used directly. A diff is still restricted further by
    // its repository through getExtendedPolicy().
    $view_policy = PhabricatorPolicies::POLICY_USER;

    $diff = id(new self())
      ->setViewPolicy($view_policy);

    return $diff;
  }

  /**
   * @param PhabricatorUser $actor
   * @param array<ArcanistDiffChange> $changes
   */
  public static function newFromRawChanges(
    PhabricatorUser $actor,
    array $changes) {

    assert_instances_of($changes, ArcanistDiffChange::class);

    $diff = self::initializeNewDiff($actor);
    return self::buildChangesetsFromRawChanges($diff, $changes);
  }

  /**
   * @param array<ArcanistDiffChange> $changes
   */
  public static function newEphemeralFromRawChanges(array $changes) {
    assert_instances_of($changes, ArcanistDiffChange::class);

    $diff = id(new self())->makeEphemeral();
    return self::buildChangesetsFromRawChanges($diff, $changes);
  }

  /**
   * @param array<ArcanistDiffChange> $changes
   */
  private static function buildChangesetsFromRawChanges(
    DifferentialDiff $diff,
    array $changes) {

    // There may not be any changes; initialize the changesets list so that
    // we don't throw later when accessing it.
    $diff->attachChangesets(array());

    $lines = 0;
    foreach ($changes as $change) {
      if ($change->getType() == ArcanistDiffChangeType::TYPE_MESSAGE) {
        // If a user pastes a diff into Differential which includes a commit
        // message (e.g., they ran `git show` to generate it), discard that
        // change when constructing a DifferentialDiff.
        continue;
      }

      $changeset = new DifferentialChangeset();
      $add_lines = 0;
      $del_lines = 0;
      $first_line = PHP_INT_MAX;
      $hunks = $change->getHunks();
      if ($hunks) {
        foreach ($hunks as $hunk) {
          $dhunk = new DifferentialHunk();
          $dhunk->setOldOffset($hunk->getOldOffset());
          $dhunk->setOldLen($hunk->getOldLength());
          $dhunk->setNewOffset($hunk->getNewOffset());
          $dhunk->setNewLen($hunk->getNewLength());
          $dhunk->setChanges($hunk->getCorpus());
          $changeset->addUnsavedHunk($dhunk);
          $add_lines += $hunk->getAddLines();
          $del_lines += $hunk->getDelLines();
          $added_lines = $hunk->getChangedLines('new');
          if ($added_lines) {
            $first_line = min($first_line, head_key($added_lines));
          }
        }
        $lines += $add_lines + $del_lines;
      } else {
        // This happens when you add empty files.
        $changeset->attachHunks(array());
      }

      $metadata = $change->getAllMetadata();
      if ($first_line != PHP_INT_MAX) {
        $metadata['line:first'] = $first_line;
      }

      $changeset->setOldFile($change->getOldPath());
      $changeset->setFilename($change->getCurrentPath());
      $changeset->setChangeType($change->getType());

      $changeset->setFileType($change->getFileType());
      $changeset->setMetadata($metadata);
      $changeset->setOldProperties($change->getOldProperties());
      $changeset->setNewProperties($change->getNewProperties());
      $changeset->setAwayPaths($change->getAwayPaths());
      $changeset->setAddLines($add_lines);
      $changeset->setDelLines($del_lines);

      $diff->addUnsavedChangeset($changeset);
    }
    $diff->setLineCount($lines);

    $changesets = $diff->getChangesets();

    // TODO: This is "safe", but it would be better to propagate a real user
    // down the stack.
    $viewer = PhabricatorUser::getOmnipotentUser();

    id(new DifferentialChangesetEngine())
      ->setViewer($viewer)
      ->rebuildChangesets($changesets);

    return $diff;
  }

  public function getDiffDict() {
    $dict = array(
      'id' => $this->getID(),
      'revisionID' => $this->getRevisionID(),
      'dateCreated' => $this->getDateCreated(),
      'dateModified' => $this->getDateModified(),
      'sourceControlBaseRevision' => $this->getSourceControlBaseRevision(),
      'sourceControlPath' => $this->getSourceControlPath(),
      'sourceControlSystem' => $this->getSourceControlSystem(),
      'branch' => $this->getBranch(),
      'bookmark' => $this->getBookmark(),
      'creationMethod' => $this->getCreationMethod(),
      'description' => $this->getDescription(),
      'unitStatus' => $this->getUnitStatus(),
      'lintStatus' => $this->getLintStatus(),
      'changes' => $this->buildChangesList(),
    );

    return $dict + $this->getDiffAuthorshipDict();
  }

  public function getDiffAuthorshipDict() {
    $dict = array('properties' => array());

    $properties = id(new DifferentialDiffProperty())->loadAllWhere(
      'diffID = %d',
      $this->getID());
    foreach ($properties as $property) {
      $dict['properties'][$property->getName()] = $property->getData();

      if ($property->getName() == 'local:commits') {
        foreach ($property->getData() as $commit) {
          $dict['authorName'] = $commit['author'];
          $dict['authorEmail'] = idx($commit, 'authorEmail');
          break;
        }
      }
    }

    return $dict;
  }

  public function buildChangesList() {
    $changes = array();
    foreach ($this->getChangesets() as $changeset) {
      $hunks = array();
      foreach ($changeset->getHunks() as $hunk) {
        $hunks[] = array(
          'oldOffset' => $hunk->getOldOffset(),
          'newOffset' => $hunk->getNewOffset(),
          'oldLength' => $hunk->getOldLen(),
          'newLength' => $hunk->getNewLen(),
          'addLines'  => null,
          'delLines'  => null,
          'isMissingOldNewline' => null,
          'isMissingNewNewline' => null,
          'corpus'    => $hunk->getChanges(),
        );
      }
      $change = array(
        'id'            => $changeset->getID(),
        'metadata'      => $changeset->getMetadata(),
        'oldPath'       => $changeset->getOldFile(),
        'currentPath'   => $changeset->getFilename(),
        'awayPaths'     => $changeset->getAwayPaths(),
        'oldProperties' => $changeset->getOldProperties(),
        'newProperties' => $changeset->getNewProperties(),
        'type'          => $changeset->getChangeType(),
        'fileType'      => $changeset->getFileType(),
        'commitHash'    => null,
        'addLines'      => $changeset->getAddLines(),
        'delLines'      => $changeset->getDelLines(),
        'hunks'         => $hunks,
      );
      $changes[] = $change;
    }
    return $changes;
  }

  public function attachProperty($key, $value) {
    if (!is_array($this->properties)) {
      $this->properties = array();
    }
    $this->properties[$key] = $value;
    return $this;
  }

  public function getProperty($key) {
    return $this->assertAttachedKey($this->properties, $key);
  }

  public function hasDiffProperty($key) {
    $properties = $this->getDiffProperties();
    return array_key_exists($key, $properties);
  }

  public function attachDiffProperties(array $properties) {
    $this->properties = $properties;
    return $this;
  }

  public function getDiffProperties() {
    return $this->assertAttached($this->properties);
  }

  public function attachUnitMessages(array $unit_messages) {
    $this->unitMessages = $unit_messages;
    return $this;
  }


  public function getUnitMessages() {
    return $this->assertAttached($this->unitMessages);
  }


/* -(  PhabricatorPolicyInterface  )----------------------------------------- */


  public function getCapabilities() {
    return array(
      PhabricatorPolicyCapability::CAN_VIEW,
    );
  }

  public function getPolicy($capability) {
    return $this->viewPolicy;
  }

  public function hasAutomaticCapability($capability, PhabricatorUser $viewer) {
    return ($this->getAuthorPHID() == $viewer->getPHID());
  }

  public function describeAutomaticCapability($capability) {
    return pht('The author of a diff can see it.');
  }


/* -(  PhabricatorExtendedPolicyInterface  )--------------------------------- */


  public function getExtendedPolicy($capability, PhabricatorUser $viewer) {
    $extended = array();

    switch ($capability) {
      case PhabricatorPolicyCapability::CAN_VIEW:
        if ($this->getRepositoryPHID()) {
          $extended[] = array(
            $this->getRepositoryPHID(),
            PhabricatorPolicyCapability::CAN_VIEW,
          );
        }
        break;
    }

    return $extended;
  }


/* -(  PhabricatorDestructibleInterface  )----------------------------------- */


  public function destroyObjectPermanently(
    PhabricatorDestructionEngine $engine) {

    $viewer = $engine->getViewer();

    $this->openTransaction();
      $this->delete();

      foreach ($this->loadChangesets() as $changeset) {
        $engine->destroyObject($changeset);
      }

      $properties = id(new DifferentialDiffProperty())->loadAllWhere(
        'diffID = %d',
        $this->getID());
      foreach ($properties as $prop) {
        $prop->delete();
      }

      $viewstate_query = id(new DifferentialViewStateQuery())
        ->setViewer($viewer)
        ->withObjectPHIDs(array($this->getPHID()));
      $viewstates = new PhabricatorQueryIterator($viewstate_query);
      foreach ($viewstates as $viewstate) {
        $viewstate->delete();
      }

    $this->saveTransaction();
  }


/* -(  PhabricatorConduitResultInterface  )---------------------------------- */


  public function getFieldSpecificationsForConduit() {
    return array(
      id(new PhabricatorConduitSearchFieldSpecification())
        ->setKey('revisionPHID')
        ->setType('phid')
        ->setDescription(pht('Associated revision PHID.')),
      id(new PhabricatorConduitSearchFieldSpecification())
        ->setKey('authorPHID')
        ->setType('phid')
        ->setDescription(pht('Revision author PHID.')),
      id(new PhabricatorConduitSearchFieldSpecification())
        ->setKey('repositoryPHID')
        ->setType('phid')
        ->setDescription(pht('Associated repository PHID.')),
      id(new PhabricatorConduitSearchFieldSpecification())
        ->setKey('refs')
        ->setType('map<string, wild>')
        ->setDescription(pht('List of related VCS references.')),
    );
  }

  public function getFieldValuesForConduit() {
    $refs = array();

    $branch = $this->getBranch();
    if (phutil_nonempty_string($branch)) {
      $refs[] = array(
        'type' => 'branch',
        'name' => $branch,
      );
    }

    $onto = $this->loadTargetBranch();
    if (phutil_nonempty_string($onto)) {
      $refs[] = array(
        'type' => 'onto',
        'name' => $onto,
      );
    }

    $base = $this->getSourceControlBaseRevision();
    if ($base !== null && strlen($base)) {
      $refs[] = array(
        'type' => 'base',
        'identifier' => $base,
      );
    }

    $bookmark = $this->getBookmark();
    if (phutil_nonempty_string($bookmark)) {
      $refs[] = array(
        'type' => 'bookmark',
        'name' => $bookmark,
      );
    }

    return array(
      'authorPHID' => $this->getAuthorPHID(),
      'repositoryPHID' => $this->getRepositoryPHID(),
      'refs' => $refs,
    );
  }

  public function getConduitSearchAttachments() {
    return array();
  }

}

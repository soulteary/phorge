<?php

/**
 * Project progress is an append-only project transaction stream. The property
 * is only a current-state projection; the original records remain in the
 * native transaction table with native authors, timestamps, and policies.
 */
final class TeamProjectProgress extends Phobject {

  const SUMMARY_PROPERTY = 'team.progress.current';
  const MAX_TITLE_LENGTH = 200;
  const MAX_BODY_LENGTH = 20000;
  const MAX_PARTICIPANTS = 50;

  public static function getHistoryState() {
    $state = PhabricatorEnv::getEnvConfig('project.history-state');
    return in_array($state, array('off', 'active', 'read-only'), true)
      ? $state : 'off';
  }

  public static function isHistoryVisible() {
    return self::getHistoryState() !== 'off';
  }

  public static function isWorkspaceEnabled() {
    return PhabricatorEnv::getEnvConfig('project.workspace-mode') === 'unified';
  }

  public static function areTaskDraftsEnabled() {
    return PhabricatorEnv::getEnvConfig('project.history-task-drafts') === true &&
      self::getHistoryState() === 'active';
  }

  private static function loadActiveUser(PhabricatorUser $user) {
    if (!$user->getPHID()) {
      return null;
    }
    // The caller may hold an account loaded before it was disabled or lost
    // approval. Never use that snapshot to authorize a new record.
    $current = id(new PhabricatorPeopleQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($user->getPHID()))
      ->needUserSettings(true)
      ->executeOne();
    return $current && self::isActiveUser($current) ? $current : null;
  }

  public static function getKindNames() {
    return array(
      'node' => pht('Node'),
      'comment' => pht('Work Comment'),
    );
  }

  public static function getStatusNames() {
    return array(
      'planned' => pht('Planned'),
      'active' => pht('In Progress'),
      'blocked' => pht('Blocked'),
      'done' => pht('Completed'),
    );
  }

  public static function getTransactionTypes() {
    return array(
      'node' => TeamProjectProgressNodeTransaction::TRANSACTIONTYPE,
      'update' => TeamProjectProgressUpdateTransaction::TRANSACTIONTYPE,
      'comment' => TeamProjectProgressCommentTransaction::TRANSACTIONTYPE,
      'milestone' => TeamProjectProgressMilestoneTransaction::TRANSACTIONTYPE,
    );
  }

  public static function normalizeKind($kind) {
    if ($kind === 'update' || $kind === 'milestone') {
      return 'node';
    }
    return $kind;
  }

  private static function isActiveUser(PhabricatorUser $user) {
    return $user->getPHID() &&
      $user->isLoggedIn() &&
      $user->getIsApproved() &&
      !$user->getIsDisabled() &&
      !$user->getIsSystemAgent() &&
      !$user->getIsMailingList();
  }

  private static function loadProject($viewer, $project) {
    if (!$project->getID()) {
      return null;
    }
    $projects = id(new PhabricatorProjectQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($project->getPHID()))
      ->needMembers(true)
      ->needAncestorMembers(true)
      ->execute();
    return head($projects);
  }

  public static function canPublish($viewer, $project) {
    if (self::getHistoryState() !== 'active') {
      return false;
    }
    $viewer = self::loadActiveUser($viewer);
    if (!$viewer) {
      return false;
    }
    $project = self::loadProject($viewer, $project);
    if (!$project) {
      return false;
    }
    return PhabricatorPolicyFilter::hasCapability(
      $viewer, $project, PhabricatorPolicyCapability::CAN_EDIT);
  }

  public static function newQuery($viewer, $project, $kind = null) {
    $types = self::getTransactionTypes();
    $kind = self::normalizeKind($kind);
    if ($kind !== null) {
      if (!is_string($kind) || !isset($types[$kind])) {
        throw new InvalidArgumentException(pht('Choose a valid record type.'));
      }
      if ($kind === 'node') {
        $types = array($types['node'], $types['update'], $types['milestone']);
      } else {
        $types = array($types[$kind]);
      }
    }
    return id(new PhabricatorProjectTransactionQuery())
      ->setViewer($viewer)
      ->withObjectPHIDs(array($project->getPHID()))
      ->withTransactionTypes(array_values($types));
  }

  public static function getTransactions($viewer, $project, $pager) {
    return self::newQuery($viewer, $project)->executeWithCursorPager($pager);
  }

  public static function getSummary($project, $viewer) {
    $project = self::loadProject($viewer, $project);
    if (!$project) {
      throw new PhabricatorPolicyException(pht('You do not have permission to view these project records.'));
    }
    $current = $project->getProperty(self::SUMMARY_PROPERTY, array());
    $status = idx($current, 'status');
    $status_name = idx(self::getStatusNames(), $status);
    if ($status_name === null) {
      $status_name = $current ? pht('Status Not Set') : pht('No Records Published');
    }
    $latest_node = self::newQuery($viewer, $project, 'node')
      ->setLimit(1)->executeOne();
    $node_status = $latest_node ? idx($latest_node->getNewValue(), 'status') : null;
    $node_status_name = self::getNodeStatusName($latest_node);
    return array(
      // Preserve the old project projection for compatibility, but never
      // infer a node's status from it or change it when publishing a node.
      'status' => $status,
      'statusName' => $status_name,
      'nodeStatus' => $node_status,
      'nodeStatusName' => $node_status_name,
      'percent' => idx($current, 'percent'),
      'updatedAt' => idx($current, 'updatedAt'),
      'updatedByPHID' => idx($current, 'updatedByPHID'),
      'latest' => self::newQuery($viewer, $project)->setLimit(1)->executeOne(),
      'latestNode' => $latest_node,
      'latestProgress' => $latest_node,
      'latestComment' => self::newQuery($viewer, $project, 'comment')
        ->setLimit(1)->executeOne(),
    );
  }

  public static function getNodeStatusName($record) {
    if (!$record) {
      return pht('No Nodes Published');
    }
    $value = $record->getNewValue();
    // Every newly written stage record has the version marker, even when a
    // caller uses an old transaction type. A historical
    // record without a saved status must remain explicitly unannotated.
    $types = self::getTransactionTypes();
    if (in_array($record->getTransactionType(), array(
          $types['node'], $types['update'], $types['milestone'])) &&
        idx($value, 'version') === 2) {
      return pht('Recorded');
    }
    $status = idx($value, 'status');
    $name = idx(self::getStatusNames(), $status);
    if ($name !== null) {
      return $name;
    }
    return pht('Unannotated');
  }

  /**
   * Build an editable task draft without saving either a task or a relation.
   * Native creation forms still apply their field and policy validation.
   */
  public static function newTaskDraft(PhabricatorUser $viewer, $transaction_id) {
    $viewer = self::loadActiveUser($viewer);
    if (!$viewer || !self::areTaskDraftsEnabled()) {
      throw new PhabricatorPolicyException(pht('You do not have permission to create a task from this record.'));
    }
    if ((!is_int($transaction_id) && !is_string($transaction_id)) ||
        !preg_match('/\A[1-9][0-9]*\z/', (string)$transaction_id)) {
      throw new InvalidArgumentException(pht('Choose a valid source project record.'));
    }
    $types = self::getTransactionTypes();
    $record = id(new PhabricatorProjectTransactionQuery())
      ->setViewer($viewer)
      ->withIDs(array($transaction_id))
      ->withTransactionTypes(array($types['node'], $types['update'],
        $types['milestone']))
      ->executeOne();
    // Use one denial for missing, private, and non-node records. A transaction
    // identifier must not disclose the source project or its record type.
    if (!$record) {
      throw new PhabricatorPolicyException(pht('You do not have permission to create a task from this record.'));
    }
    $project = id(new PhabricatorProjectQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($record->getObjectPHID()))
      ->needMembers(true)
      ->needAncestorMembers(true)
      ->executeOne();
    if (!$project || !self::canPublish($viewer, $project)) {
      throw new PhabricatorPolicyException(pht('You do not have permission to create a task from this record.'));
    }
    if (!PhabricatorApplication::isClassInstalledForViewer(
        PhabricatorManiphestApplication::class, $viewer)) {
      throw new PhabricatorPolicyException(pht('You do not have permission to create tasks.'));
    }
    if (!PhabricatorPolicyFilter::hasCapability(
        $viewer, id(new ManiphestEditEngine())->setViewer($viewer),
        PhabricatorPolicyCapability::CAN_EDIT)) {
      throw new PhabricatorPolicyException(pht('You do not have permission to create tasks.'));
    }
    $value = $record->getNewValue();
    if (!is_array($value) ||
        self::normalizeKind(idx($value, 'kind')) !== 'node' ||
        !is_string(idx($value, 'title')) ||
        !is_string(idx($value, 'body'))) {
      throw new InvalidArgumentException(pht('This record cannot be used as a task source.'));
    }
    $author = $viewer->loadHandles(array($record->getAuthorPHID()))
      [$record->getAuthorPHID()];
    $source_uri = '/project/progress/'.$project->getID().'/?entry='.
      $record->getID().'#entry-'.$record->getID();
    $source = pht(
      "Source: [[%s | Project Record #%s]]\n".
      "Original Author: %s\n".
      "Originally Published: %s (UTC)",
      $source_uri, $record->getID(), $author->getFullName(),
      gmdate('Y-m-d H:i:s', $record->getDateCreated()));
    $old_status = idx(self::getStatusNames(), idx($value, 'status'));
    if ($old_status !== null) {
      $source .= "\n".pht('Original Node Status: %s (Historical Information)', $old_status);
    }

    return ManiphestTask::initializeNewTask($viewer)
      ->setTitle($value['title'])
      ->setDescription($value['body']."\n\n---\n\n".$source)
      ->attachProjectPHIDs(array($project->getPHID()));
  }

  public static function publish(
    $actor,
    $project,
    array $input,
    PhabricatorContentSource $source) {

    $actor = self::loadActiveUser($actor);
    if (!$actor || !self::canPublish($actor, $project)) {
      throw new PhabricatorPolicyException(
        pht('Only project editors can publish records.'));
    }
    $project = self::loadProject($actor, $project);
    $kind = self::normalizeKind(idx($input, 'kind'));
    $types = self::getTransactionTypes();
    if (!is_string($kind) || !isset($types[$kind])) {
      throw new InvalidArgumentException(pht('Choose a valid record type.'));
    }
    $input['kind'] = $kind;
    $xaction = id(new PhabricatorProjectTransaction())
      ->setTransactionType($types[$kind])
      ->setNewValue($input);
    id(new PhabricatorProjectTransactionEditor())
      ->setActor($actor)
      ->setContentSource($source)
      ->applyTransactions($project, array($xaction));
    return $xaction;
  }

  /** Validate even callers using the native editor directly, not only the UI. */
  public static function normalizeRecord($actor, $project, $kind, $input) {
    $actor = self::loadActiveUser($actor);
    if (!$actor) {
      throw new InvalidArgumentException(pht(
        'Only current, approved project editors can publish records.'));
    }
    if (!is_array($input) || idx($input, 'kind') !== $kind) {
      throw new InvalidArgumentException(pht('The record type does not match its content.'));
    }
    $title = idx($input, 'title');
    $body = idx($input, 'body');
    if (!is_string($title) || !strlen(trim($title))) {
      throw new InvalidArgumentException(pht('Enter a record title.'));
    }
    if (!is_string($body) || !strlen(trim($body))) {
      throw new InvalidArgumentException(pht('Enter record content.'));
    }
    $title = trim($title);
    $body = trim($body);
    if (!phutil_is_utf8($title) ||
        phutil_utf8_strlen($title) > self::MAX_TITLE_LENGTH) {
      throw new InvalidArgumentException(pht('Record titles may contain at most 200 characters.'));
    }
    if (!phutil_is_utf8($body) ||
        phutil_utf8_strlen($body) > self::MAX_BODY_LENGTH) {
      throw new InvalidArgumentException(pht('Record content may contain at most 20000 characters.'));
    }
    $status = idx($input, 'status');
    $percent = idx($input, 'percent');
    if ($kind === 'comment') {
      // A work comment must never change the current project state, including
      // when a form submits stale progress fields along with the comment.
      $status = null;
      $percent = null;
    } else {
      if ($status === '') {
        $status = null;
      }
      if ($status !== null &&
          (!is_string($status) || !isset(self::getStatusNames()[$status]))) {
        throw new InvalidArgumentException(pht('Choose a valid historical node status.'));
      }
      // Execution state belongs to native workboard tasks. Accept a stale
      // form's known status for compatibility, but never save it anew, even
      // when a client calls the legacy native transaction types directly.
      $status = null;
      if ($percent === '') {
        $percent = null;
      }
      if ($percent !== null) {
        if ((!is_int($percent) &&
             !(is_string($percent) && preg_match('/\A[0-9]{1,3}\z/', $percent))) ||
            (int)$percent < 0 || (int)$percent > 100) {
          throw new InvalidArgumentException(pht('Reported completion must be an integer from 0 to 100.'));
        }
        $percent = (int)$percent;
      }
    }
    $participants = idx($input, 'participants', array());
    if (!is_array($participants) ||
        count($participants) > self::MAX_PARTICIPANTS) {
      throw new InvalidArgumentException(pht('Choose at most 50 participants.'));
    }
    foreach ($participants as $phid) {
      if (!is_string($phid) || phid_get_type($phid) !== 'USER') {
        throw new InvalidArgumentException(pht('Participants must be valid users.'));
      }
    }
    if (!$participants) {
      $participants[] = $actor->getPHID();
    }
    $participants = array_values(array_unique($participants));
    $users = id(new PhabricatorPeopleQuery())
      ->setViewer($actor)
      ->withPHIDs($participants)
      ->needUserSettings(true)
      ->execute();
    $users = mpull($users, null, 'getPHID');
    foreach ($participants as $phid) {
      $user = idx($users, $phid);
      if (!$user || !self::isActiveUser($user) ||
          !self::loadProject($user, $project)) {
        throw new InvalidArgumentException(
          pht('Participants must be current users who can view this project.'));
      }
    }
    // Deliberately discard caller-supplied IDs, authors, dates, or arbitrary
    // properties. Native transaction metadata is the source of attribution.
    $record = array(
      'kind' => $kind,
      'title' => $title,
      'body' => $body,
      'status' => $status,
      'percent' => $percent,
      'participants' => $participants,
    );
    if ($kind !== 'comment') {
      // This marker is server controlled, not copied from caller metadata.
      $record['version'] = 2;
    }
    return $record;
  }
}

abstract class TeamProjectProgressRecordTransaction
  extends PhabricatorProjectTransactionType {

  abstract public function getRecordKind();

  public function generateOldValue($object) {
    return null;
  }

  public function newRemarkupChanges() {
    // Support transactions are expanded before record validation. Ignore
    // malformed payloads here and let normalizeRecord() report their errors.
    $value = $this->getNewValue();
    if (!is_array($value) || !is_string(idx($value, 'body'))) {
      return array();
    }
    return array($this->newRemarkupChange()
      ->setOldValue(null)
      ->setNewValue($value['body']));
  }

  public function getTransactionHasEffect($object, $old, $new) {
    // Identical text may legitimately be published on different work days.
    return true;
  }

  public function getRequiredCapabilities(
    $object,
    PhabricatorApplicationTransaction $xaction) {
    if (!TeamProjectProgress::canPublish($this->getActor(), $object)) {
      throw new PhabricatorPolicyException(
        pht('Only project editors can publish records.'));
    }
    return PhabricatorPolicyCapability::CAN_EDIT;
  }

  public function validateTransactions($object, array $xactions) {
    if (!$xactions) {
      return array();
    }
    if (!TeamProjectProgress::canPublish($this->getActor(), $object)) {
      return array($this->newInvalidError(
        pht('Only project editors can publish records.'), head($xactions)));
    }
    $errors = array();
    foreach ($xactions as $xaction) {
      try {
        $value = TeamProjectProgress::normalizeRecord(
          $this->getActor(), $object, $this->getRecordKind(),
          $xaction->getNewValue());
        $xaction->setNewValue($value);
        $xaction->setAuthorPHID($this->getActor()->getPHID());
        $xaction->setDateCreated(time());
        $xaction->setEditPolicy(PhabricatorPolicies::POLICY_NOONE);
      } catch (InvalidArgumentException $ex) {
        $errors[] = $this->newInvalidError($ex->getMessage(), $xaction);
      }
    }
    return $errors;
  }

  public function applyInternalEffects($object, $value) {
    if ($this->getRecordKind() === 'comment') {
      return;
    }
    // The native editor locks and reloads the project before this merge.
    // Status remains only in immutable historical nodes. Newly appended
    // stage records, including legacy transaction types, have no task state.
    // Project completion is an independently and explicitly entered value.
    $current = $object->getProperty(
      TeamProjectProgress::SUMMARY_PROPERTY, array());
    if ($value['percent'] !== null) {
      $current['percent'] = $value['percent'];
    }
    $current['updatedAt'] = time();
    $current['updatedByPHID'] = $this->getActor()->getPHID();
    $object->setProperty(TeamProjectProgress::SUMMARY_PROPERTY, $current);
  }

  public function getIcon() {
    switch ($this->getRecordKind()) {
      case 'comment':
        return 'fa-comment-o';
      default:
        return 'fa-flag-checkered';
    }
  }

  public function getActionName() {
    if ($this->getRecordKind() !== 'comment' &&
        idx($this->getNewValue(), 'version') === 2) {
      return pht('Project Record');
    }
    return idx(TeamProjectProgress::getKindNames(),
      TeamProjectProgress::normalizeKind($this->getRecordKind()));
  }

  public function getTitle() {
    return pht('%s published %s: %s',
      $this->renderAuthor(), $this->getActionName(),
      $this->renderValue(idx($this->getNewValue(), 'title')));
  }

  public function getTitleForFeed() {
    $title = idx($this->getNewValue(), 'title');
    $uri = $this->getRecordURI();
    if ($this->isTextMode()) {
      $title = pht('%s (%s)', $this->renderValue($title),
        PhabricatorEnv::getProductionURI($uri));
    } else {
      $title = phutil_tag('a', array('href' => $uri), $title);
    }
    return pht('%s on %s published %s: %s',
      $this->renderAuthor(), $this->renderObject(), $this->getActionName(),
      $title);
  }

  private function getRecordStorage() {
    // This fork keeps the modular type's storage getter private. The native
    // change context exposes the same attached transaction through its public
    // accessor. Creating this context has no effects and publishes no change.
    return $this->newRemarkupChange()->getTransaction();
  }

  private function getRecordURI() {
    $uri = '/project/progress/'.$this->getObject()->getID().'/';
    $id = $this->getRecordStorage()->getID();
    if ($id) {
      $uri .= '?entry='.$id.'#entry-'.$id;
    }
    return $uri;
  }

  public function hasChangeDetailView() {
    return true;
  }

  public function newChangeDetailView() {
    $viewer = $this->getViewer();
    $value = $this->getNewValue();
    $storage = $this->getRecordStorage();
    $properties = id(new PHUIPropertyListView())->setViewer($viewer);
    $properties->addProperty(pht('Record Type'), $this->getActionName());
    $properties->addProperty(pht('Title'), idx($value, 'title'));
    $properties->addProperty(pht('Publisher'), $viewer->renderHandle($this->getAuthorPHID()));
    $properties->addProperty(pht('Published At'),
      phabricator_datetime($storage->getDateCreated(), $viewer));
    $properties->addProperty(pht('Participants'), $viewer
      ->renderHandleList(idx($value, 'participants', array()))->setAsInline(true));
    if ($this->getRecordKind() !== 'comment') {
      $is_stage_record = idx($value, 'version') === 2;
      $properties->addProperty($is_stage_record ? pht('Record Status') : pht('Node Status'),
        TeamProjectProgress::getNodeStatusName($storage));
    }
    if (idx($value, 'percent') !== null) {
      $properties->addProperty(pht('Reported Project Completion'), $value['percent'].'%');
    }
    $properties->addProperty(pht('Permanent Link'), phutil_tag('a',
      array('href' => $this->getRecordURI()), pht('View Record #').$storage->getID()));
    // Native transaction queries attach only the querying viewer's membership
    // to projects. Mention rendering also checks each mentioned user's access,
    // so native details and background mail rendering need the complete member
    // context, including ancestors. Keep the query scoped to the actual viewer.
    $project = id(new PhabricatorProjectQuery())->setViewer($viewer)
      ->withPHIDs(array($this->getObject()->getPHID()))
      ->needMembers(true)->needAncestorMembers(true)->executeOne();
    if (!$project) {
      throw new PhabricatorPolicyException(pht('You do not have permission to view this project record.'));
    }
    $body = id(new PHUIRemarkupView($viewer, idx($value, 'body', '')))
      ->setContextObject($project);
    return phutil_tag('div', array('class' => 'team-project-progress-details'),
      array($properties, $body));
  }
}

final class TeamProjectProgressNodeTransaction
  extends TeamProjectProgressRecordTransaction {
  const TRANSACTIONTYPE = 'team:project-node';

  public function getRecordKind() {
    return 'node';
  }
}

final class TeamProjectProgressUpdateTransaction
  extends TeamProjectProgressRecordTransaction {
  const TRANSACTIONTYPE = 'team:project-progress';

  public function getRecordKind() {
    return 'update';
  }
}

final class TeamProjectProgressCommentTransaction
  extends TeamProjectProgressRecordTransaction {
  const TRANSACTIONTYPE = 'team:project-comment';

  public function getRecordKind() {
    return 'comment';
  }
}

final class TeamProjectProgressMilestoneTransaction
  extends TeamProjectProgressRecordTransaction {
  const TRANSACTIONTYPE = 'team:project-keynode';

  public function getRecordKind() {
    return 'milestone';
  }
}

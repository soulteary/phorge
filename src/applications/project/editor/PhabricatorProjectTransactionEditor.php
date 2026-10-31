<?php

final class PhabricatorProjectTransactionEditor
  extends PhabricatorApplicationTransactionEditor {

  private $isMilestone;
  private $parentPHID;

  private function setIsMilestone($is_milestone) {
    $this->isMilestone = $is_milestone;
    return $this;
  }

  public function getIsMilestone() {
    return $this->isMilestone;
  }

  private function setParentPHID($phid) {
    $this->parentPHID = $phid;
    return $this;
  }

  private function getParentPHID() {
    return $this->parentPHID;
  }

  public function getEditorApplicationClass() {
    return PhabricatorProjectApplication::class;
  }

  public function getEditorObjectsDescription() {
    return pht('Projects');
  }

  public function getCreateObjectTitle($author, $object) {
    return pht('%s created this project.', $author);
  }

  public function getCreateObjectTitleForFeed($author, $object) {
    return pht('%s created %s.', $author, $object);
  }

  public function getTransactionTypes() {
    $types = parent::getTransactionTypes();

    $types[] = PhabricatorTransactions::TYPE_EDGE;
    $types[] = PhabricatorTransactions::TYPE_VIEW_POLICY;
    $types[] = PhabricatorTransactions::TYPE_EDIT_POLICY;
    $types[] = PhabricatorTransactions::TYPE_JOIN_POLICY;

    return $types;
  }

  protected function validateAllTransactions(
    PhabricatorLiskDAO $object,
    array $xactions) {

    $errors = array();

    // Prevent creating projects which are both subprojects and milestones,
    // since this does not make sense, won't work, and will break everything.
    $parent_xaction = null;
    foreach ($xactions as $xaction) {
      switch ($xaction->getTransactionType()) {
        case PhabricatorProjectParentTransaction::TRANSACTIONTYPE:
        case PhabricatorProjectMilestoneTransaction::TRANSACTIONTYPE:
          if ($xaction->getNewValue() === null) {
            continue 2;
          }

          if (!$parent_xaction) {
            $parent_xaction = $xaction;
            continue 2;
          }

          $errors[] = new PhabricatorApplicationTransactionValidationError(
            $xaction->getTransactionType(),
            pht('Invalid'),
            pht(
              'When creating a project, specify a maximum of one parent '.
              'project or milestone project. A project can not be both a '.
              'subproject and a milestone.'),
            $xaction);
          break 2;
      }
    }

    $is_milestone = $this->getIsMilestone();

    foreach ($xactions as $xaction) {
      switch ($xaction->getTransactionType()) {
        case PhabricatorTransactions::TYPE_EDGE:
          $type = $xaction->getMetadataValue('edge:type');
          if ($type != PhabricatorProjectProjectHasMemberEdgeType::EDGECONST) {
            break;
          }

          if ($is_milestone) {
            $errors[] = new PhabricatorApplicationTransactionValidationError(
              $xaction->getTransactionType(),
              pht('Invalid'),
              pht(
                'You can not change members of a milestone. Members of the '.
                'parent project are automatically members of the milestone.'),
              $xaction);
          }
          break;
      }
    }

    TeamProjectMembership::validateTransactions(
      $this->getActor(), $object, $xactions, $this->getIsNewObject());
    return $errors;
  }

  protected function willPublish(PhabricatorLiskDAO $object, array $xactions) {
    // NOTE: We're using the omnipotent user here because the original actor
    // may no longer have permission to view the object.
    return id(new PhabricatorProjectQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($object->getPHID()))
      ->needAncestorMembers(true)
      ->executeOne();
  }

  protected function shouldSendMail(
    PhabricatorLiskDAO $object,
    array $xactions) {
    return true;
  }

  protected function getMailSubjectPrefix() {
    return pht('[Project]');
  }

  protected function getMailTo(PhabricatorLiskDAO $object) {
    return array(
      $this->getActingAsPHID(),
    );
  }

  protected function getMailCc(PhabricatorLiskDAO $object) {
    return array();
  }

  public function getMailTagsMap() {
    return array(
      PhabricatorProjectTransaction::MAILTAG_METADATA =>
        pht('Project name, hashtags, icon, image, or color changes.'),
      PhabricatorProjectTransaction::MAILTAG_MEMBERS =>
        pht('Project membership changes.'),
      PhabricatorProjectTransaction::MAILTAG_WATCHERS =>
        pht('Project watcher list changes.'),
      PhabricatorProjectTransaction::MAILTAG_OTHER =>
        pht('Other project activity not listed above occurs.'),
    );
  }

  protected function buildReplyHandler(PhabricatorLiskDAO $object) {
    return id(new ProjectReplyHandler())
      ->setMailReceiver($object);
  }

  protected function buildMailTemplate(PhabricatorLiskDAO $object) {
    $name = $object->getName();

    return id(new PhabricatorMetaMTAMail())
      ->setSubject("{$name}");
  }

  protected function buildMailBody(
    PhabricatorLiskDAO $object,
    array $xactions) {

    $body = parent::buildMailBody($object, $xactions);

    $uri = '/project/profile/'.$object->getID().'/';
    $body->addLinkSection(
      pht('PROJECT DETAIL'),
      PhabricatorEnv::getProductionURI($uri));

    return $body;
  }

  protected function shouldPublishFeedStory(
    PhabricatorLiskDAO $object,
    array $xactions) {
    return true;
  }

  protected function supportsSearch() {
    return true;
  }

  /**
   * @param PhabricatorProject $object
   * @param list<PhabricatorApplicationTransaction> $xactions Transactions
   */
  protected function applyFinalEffects(
    PhabricatorLiskDAO $object,
    array $xactions) {

    $materialize = false;
    $new_parent = null;
    foreach ($xactions as $xaction) {
      switch ($xaction->getTransactionType()) {
        case PhabricatorTransactions::TYPE_EDGE:
          switch ($xaction->getMetadataValue('edge:type')) {
            case PhabricatorProjectProjectHasMemberEdgeType::EDGECONST:
              $materialize = true;
              break;
          }
          break;
        case PhabricatorProjectParentTransaction::TRANSACTIONTYPE:
        case PhabricatorProjectMilestoneTransaction::TRANSACTIONTYPE:
          $materialize = true;
          $new_parent = $object->getParentProject();
          break;
      }
    }

    // A new subproject keeps only the members explicitly supplied by its
    // creator. In particular, the first child must not import parent members.

    // TODO: We should dump an informational transaction onto the parent
    // project to show that we created the sub-thing.

    if ($materialize) {
      id(new PhabricatorProjectsMembershipIndexEngineExtension())
        ->rematerialize($object);
    }

    if ($new_parent) {
      id(new PhabricatorProjectsMembershipIndexEngineExtension())
        ->rematerialize($new_parent);
    }

    // See PHI1046. Milestones are always in the Space of their parent project.
    // Synchronize the database values to match the application values.
    $conn = $object->establishConnection('w');
    queryfx(
      $conn,
      'UPDATE %R SET spacePHID = %ns
        WHERE parentProjectPHID = %s AND milestoneNumber IS NOT NULL',
      $object,
      $object->getSpacePHID(),
      $object->getPHID());

    return parent::applyFinalEffects($object, $xactions);
  }

  public function addSlug(PhabricatorProject $project, $slug, $force) {
    $slug = PhabricatorSlug::normalizeProjectSlug($slug);
    $table = new PhabricatorProjectSlug();
    $project_phid = $project->getPHID();

    if ($force) {
      // If we have the `$force` flag set, we only want to ignore an existing
      // slug if it's for the same project. We'll error on collisions with
      // other projects.
      $current = $table->loadOneWhere(
        'slug = %s AND projectPHID = %s',
        $slug,
        $project_phid);
    } else {
      // Without the `$force` flag, we'll just return without doing anything
      // if any other project already has the slug.
      $current = $table->loadOneWhere(
        'slug = %s',
        $slug);
    }

    if ($current) {
      return;
    }

    return id(new PhabricatorProjectSlug())
      ->setSlug($slug)
      ->setProjectPHID($project_phid)
      ->save();
  }

  public function removeSlugs(PhabricatorProject $project, array $slugs) {
    // Do not allow removing the project's primary slug which the edit form
    // may allow through a series of renames/moves. See T15636
    if (($key = array_search($project->getPrimarySlug(), $slugs)) !== false) {
      unset($slugs[$key]);
    }

    if (!$slugs) {
      return;
    }

    // We're going to try to delete both the literal and normalized versions
    // of all slugs. This allows us to destroy old slugs that are no longer
    // valid.
    foreach ($this->normalizeSlugs($slugs) as $slug) {
      $slugs[] = $slug;
    }

    $objects = id(new PhabricatorProjectSlug())->loadAllWhere(
      'projectPHID = %s AND slug IN (%Ls)',
      $project->getPHID(),
      $slugs);

    foreach ($objects as $object) {
      $object->delete();
    }
  }

  public function normalizeSlugs(array $slugs) {
    foreach ($slugs as $key => $slug) {
      $slugs[$key] = PhabricatorSlug::normalizeProjectSlug($slug);
    }

    $slugs = array_unique($slugs);
    $slugs = array_values($slugs);

    return $slugs;
  }

  /**
   * @param PhabricatorProject $object
   * @param list<PhabricatorApplicationTransaction> $xactions Transactions
   */
  protected function adjustObjectForPolicyChecks(
    PhabricatorLiskDAO $object,
    array $xactions) {

    /** @var PhabricatorProject $copy */
    $copy = parent::adjustObjectForPolicyChecks($object, $xactions);
    foreach ($xactions as $xaction) {
      if ($xaction->getTransactionType() === TeamProjectMemberRoleTransaction::TRANSACTIONTYPE) {
        $copy->setProperty(TeamProjectMembership::ROLES_PROPERTY, $xaction->getNewValue());
      }
    }

    $type_edge = PhabricatorTransactions::TYPE_EDGE;
    $edgetype_member = PhabricatorProjectProjectHasMemberEdgeType::EDGECONST;

    // See T13462. If we're creating a milestone, set a dummy milestone
    // number so the project behaves like a milestone and uses milestone
    // policy rules. Otherwise, we'll end up checking the default policies
    // (which are not relevant to milestones) instead of the parent project
    // policies (which are the correct policies).
    if ($this->getIsMilestone() && !$copy->isMilestone()) {
      $copy->setMilestoneNumber(1);
    }

    $hint = null;
    if ($this->getIsMilestone()) {
      // See T13462. If we're creating a milestone, predict that the members
      // of the newly created milestone will be the same as the members of the
      // parent project, since this is the governing rule.

      $parent = id(new PhabricatorProjectQuery())
        ->setViewer($this->getActor())
        ->withPHIDs(array($this->getParentPHID()))
        ->needMembers(true)
        ->executeOne();
      $members = $parent->getMemberPHIDs();
      $copy->attachParentProject($parent);

      $hint = array_fuse($members);
    } else {
      $member_xaction = null;
      foreach ($xactions as $xaction) {
        if ($xaction->getTransactionType() !== $type_edge) {
          continue;
        }

        $edgetype = $xaction->getMetadataValue('edge:type');
        if ($edgetype !== $edgetype_member) {
          continue;
        }

        $member_xaction = $xaction;
      }

      if ($member_xaction) {
        $object_phid = $object->getPHID();

        if ($object_phid) {
          $project = id(new PhabricatorProjectQuery())
            ->setViewer($this->getActor())
            ->withPHIDs(array($object_phid))
            ->needMembers(true)
            ->executeOne();
          $members = $project->getDirectMemberPHIDs();
        } else {
          $members = array();
        }

        $clone_xaction = clone $member_xaction;
        $hint = $this->getPHIDTransactionNewValue($clone_xaction, $members);
        $hint = array_fuse($hint);

        // Editing direct membership does not remove rights originating in a
        // descendant. Predict the same union as the membership index does.
        if ($object_phid && $object->getHasSubprojects()) {
          $descendants = id(new PhabricatorProjectQuery())
            ->setViewer(PhabricatorUser::getOmnipotentUser())
            ->withAncestorProjectPHIDs(array($object_phid))
            ->withIsMilestone(false)
            ->execute();
          if ($descendants) {
            $edges = id(new PhabricatorEdgeQuery())
              ->withSourcePHIDs(mpull($descendants, 'getPHID'))
              ->withEdgeTypes(array($edgetype_member));
            $edges->execute();
            $inherited = $edges->getDestinationPHIDs();
            $hint += array_fuse($inherited);
          }
        }
      }
    }

    if ($hint !== null) {
      $rule = new PhabricatorProjectMembersPolicyRule();
      PhabricatorPolicyRule::passTransactionHintToRule(
        $copy,
        $rule,
        $hint);
    }

    return $copy;
  }

  /**
   * @param PhabricatorProject $object
   * @param list<PhabricatorApplicationTransaction> $xactions Transactions
   */
  protected function expandTransactions(
    PhabricatorLiskDAO $object,
    array $xactions) {

    $results = parent::expandTransactions($object, $xactions);

    $is_milestone = $object->isMilestone();
    $parent_phid = $object->getParentProjectPHID();
    foreach ($xactions as $xaction) {
      switch ($xaction->getTransactionType()) {
        case PhabricatorProjectMilestoneTransaction::TRANSACTIONTYPE:
          if ($xaction->getNewValue() !== null) {
            $is_milestone = true;
            $parent_phid = $xaction->getNewValue();
          }
          break;
      }
    }

    $this->setIsMilestone($is_milestone);
    $this->setParentPHID($parent_phid);

    $has_role = false;
    foreach ($results as $xaction) {
      if ($xaction->getTransactionType() === TeamProjectMemberRoleTransaction::TRANSACTIONTYPE) {
        $has_role = true;
      }
    }
    if (!$is_milestone && !$has_role) {
      if ($this->getIsNewObject()) {
        $results[] = id(new PhabricatorProjectTransaction())
          ->setTransactionType(TeamProjectMemberRoleTransaction::TRANSACTIONTYPE)
          ->setNewValue(array($object->getAuthorPHID() => 'admin'));
      } else {
        $roles = TeamProjectMembership::roleMap($object);
        $members = array_fuse($object->getDirectMemberPHIDs());
        $expected = null;
        foreach ($results as $xaction) {
          if ($xaction->getTransactionType() !== PhabricatorTransactions::TYPE_EDGE ||
              $xaction->getMetadataValue('edge:type') !== PhabricatorProjectProjectHasMemberEdgeType::EDGECONST) {
            continue;
          }
          $spec = $xaction->getNewValue();
          if (isset($spec['='])) { $members = array_fuse(array_keys($spec['='])); }
          foreach (idx($spec, '+', array()) as $phid => $value) { $members[$phid] = $phid; }
          foreach (idx($spec, '-', array()) as $phid => $value) { unset($members[$phid]); }
          $expected = $xaction->getMetadataValue(TeamProjectMembership::EXPECTED_STATE);
        }
        $removed = array_diff($object->getDirectMemberPHIDs(), array_keys($members));
        $pruned = $roles;
        foreach ($removed as $phid) {
          // Leaving and rejoining must not let a read-only member discard
          // an administrator's explicit edit ceiling.
          if ($phid === $this->getActor()->getPHID() && idx($roles, $phid) === 'viewer' &&
              !TeamProjectMembership::canManage($this->getActor(), $object)) {
            continue;
          }
          unset($pruned[$phid]);
        }
        ksort($pruned, SORT_STRING); ksort($roles, SORT_STRING);
        if ($pruned !== $roles) {
          $results[] = id(new PhabricatorProjectTransaction())
            ->setTransactionType(TeamProjectMemberRoleTransaction::TRANSACTIONTYPE)
            ->setMetadataValue(TeamProjectMembership::EXPECTED_STATE, $expected)
            ->setNewValue($pruned);
        }
      }
    }

    return $results;
  }

  protected function shouldApplyHeraldRules(
    PhabricatorLiskDAO $object,
    array $xactions) {
    return true;
  }

  protected function buildHeraldAdapter(
    PhabricatorLiskDAO $object,
    array $xactions) {

    // Herald rules may run on behalf of other users and need to execute
    // membership checks against ancestors.
    $project = id(new PhabricatorProjectQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($object->getPHID()))
      ->needAncestorMembers(true)
      ->executeOne();

    return id(new PhabricatorProjectHeraldAdapter())
      ->setProject($project);
  }

}

<?php

/**
 * Validate an ordinary project's final access before any project writes.
 *
 * This is not an additional capability. The native policy filter remains the
 * authority. Row locks use the project's transaction connection, including
 * cross-schema user and Space reads, and survive until that transaction ends.
 */
final class PhabricatorProjectHandoff extends Phobject {

  const TOKEN = 'project.handoff.token';
  const EXPIRES = 'project.handoff.expires';

  public static function newError($message, $invalid_policy = false) {
    return new PhabricatorApplicationTransactionValidationException(
      array(
        new PhabricatorApplicationTransactionValidationError(
          PhabricatorTransactions::TYPE_EDIT_POLICY,
          $invalid_policy ? pht('Invalid Project Policy') : pht('Unable to Transfer'),
          $message),
      ));
  }

  public static function loadMembers(PhabricatorProject $project) {
    if (!$project->getID()) {
      return array();
    }
    $rows = queryfx_all(
      $project->establishConnection('w'),
      'SELECT dst FROM %T WHERE src = %s AND type = %d ORDER BY dst',
      'edge',
      $project->getPHID(),
      PhabricatorProjectProjectHasMemberEdgeType::EDGECONST);
    return ipull($rows, 'dst');
  }

  public static function validatePolicy($value) {
    if (PhabricatorPolicyQuery::isGlobalPolicy($value)) {
      return;
    }
    if (PhabricatorPolicyQuery::getObjectPolicy($value)) {
      $rule = PhabricatorPolicyQuery::getObjectPolicyRule($value);
      if ($rule->canApplyToObject(new PhabricatorProject())) {
        return;
      }
      throw self::newError(pht(
        'The selected policy does not apply to projects. Choose a project policy.'), true);
    }
    $policy = id(new PhabricatorPolicyQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($value))
      ->execute();
    if (!$policy) {
      throw self::newError(pht(
        'The selected project policy does not exist. Choose a valid policy.'), true);
    }
  }

  public static function validate(
    PhabricatorProject $before,
    PhabricatorProject $after,
    PhabricatorUser $actor,
    array $xactions,
    ?AphrontRequest $request) {

    $conn = $after->establishConnection('w');
    $parent = null;
    // Clones may carry the source object's transaction-hint cache key. Keep
    // the pre-change and final-state policy hints strictly separate.
    unset($before->_hashKey);
    $members = $before->getMemberPHIDs();
    $requires_edit = false;
    foreach ($xactions as $xaction) {
      if (in_array($xaction->getTransactionType(), array(
        PhabricatorTransactions::TYPE_VIEW_POLICY,
        PhabricatorTransactions::TYPE_EDIT_POLICY,
        PhabricatorTransactions::TYPE_JOIN_POLICY,
        PhabricatorTransactions::TYPE_SPACE,
      ), true)) {
        $requires_edit = true;
      }
      if ($xaction->getTransactionType() === PhabricatorTransactions::TYPE_EDGE &&
          $xaction->getMetadataValue('edge:type') ===
            PhabricatorProjectProjectHasMemberEdgeType::EDGECONST) {
        // Values have already been combined and normalized by the editor.
        $members = array_keys($xaction->getNewValue());
        $changed_members = array_merge(
          array_diff($before->getMemberPHIDs(), $members),
          array_diff($members, $before->getMemberPHIDs()));
        if (array_diff($changed_members, array($actor->getPHID()))) {
          $requires_edit = true;
        }
      }
    }
    if ($before->getID()) {
      // Inverse user edges are published after commit. Authorize against the
      // canonical project membership captured under the project row lock.
      PhabricatorPolicyRule::passTransactionHintToRule(
        $before, new PhabricatorProjectMembersPolicyRule(),
        array_fuse($before->getMemberPHIDs()));
      $capabilities = array(PhabricatorPolicyCapability::CAN_VIEW);
      if ($requires_edit) {
        $capabilities[] = PhabricatorPolicyCapability::CAN_EDIT;
      }
      id(new PhabricatorPolicyFilter())->setViewer($actor)
        ->requireCapabilities($capabilities)->raisePolicyExceptions(true)
        ->apply(array($before));
      if (!$requires_edit && !self::canManage($actor, $before)) {
        // A non-editor joining/leaving only their own membership is not a
        // transfer of edit authority. Preserve the native join/leave rules.
        return;
      }
    } else {
      $app = id(new PhabricatorApplicationQuery())->setViewer($actor)
        ->withClasses(array(PhabricatorProjectApplication::class))->executeOne();
      PhabricatorPolicyFilter::requireCapability(
        $actor, $app, ProjectCreateProjectsCapability::CAPABILITY);
      if ($after->getParentProjectPHID()) {
        // New objects have no row to lock yet. Lock the parent and recheck
        // the creation precondition immediately before saving the child.
        queryfx_one($conn, 'SELECT id FROM %R WHERE phid = %s FOR UPDATE',
          new PhabricatorProject(), $after->getParentProjectPHID());
        $parent = id(new PhabricatorProjectQuery())->setViewer($actor)
          ->withPHIDs(array($after->getParentProjectPHID()))->needMembers(true)
          ->requireCapabilities(array(
            PhabricatorPolicyCapability::CAN_VIEW,
            PhabricatorPolicyCapability::CAN_EDIT,
          ))->executeOne();
        if (!$parent || !$parent->supportsSubprojects()) {
          throw self::newError(pht(
            'You no longer have permission to create a child of this project.'));
        }
        $parent->attachMemberPHIDs(self::loadMembers($parent));
        unset($parent->_hashKey);
        PhabricatorPolicyRule::passTransactionHintToRule(
          $parent, new PhabricatorProjectMembersPolicyRule(),
          array_fuse($parent->getMemberPHIDs()));
      }
    }
    sort($members);
    $candidate = clone $after;
    unset($candidate->_hashKey);
    $candidate->attachMemberPHIDs($members);
    PhabricatorPolicyRule::passTransactionHintToRule(
      $candidate,
      new PhabricatorProjectMembersPolicyRule(),
      array_fuse($members));

    foreach (array('view', 'edit', 'join') as $capability) {
      self::validatePolicy($candidate->getPolicy($capability));
    }

    // Preserve the ordinary self-retaining path. A transfer is the additional
    // case which requires a locked, independently verified recipient.
    if (self::isEligible($actor) && self::canManage($actor, $candidate)) {
      return;
    }

    $candidates = array_fuse($members);
    $dependencies = array();
    foreach (array('view', 'edit') as $capability) {
      self::inspectPolicy(
        $conn, $candidate->getPolicy($capability),
        $candidates, $dependencies);
    }
    // The original authorization is also part of the proof. A mutable or
    // unsupported policy on the source project must not authorize a transfer
    // based on an earlier request snapshot.
    $authorization_object = $before->getID() ? $before : $parent;
    $unused_candidates = array();
    self::inspectPolicy(
      $conn,
      id(new PhabricatorProjectApplication())->getPolicy(
        PhabricatorPolicyCapability::CAN_VIEW),
      $unused_candidates,
      $dependencies);
    if ($authorization_object) {
      foreach (array('view', 'edit') as $capability) {
        self::inspectPolicy(
          $conn, $authorization_object->getPolicy($capability),
          $unused_candidates, $dependencies);
      }
    }
    unset($candidates[$actor->getPHID()]);
    if (!$candidates) {
      throw self::newError(pht(
        'No active recipient can both view and edit the project after this '.
        'change. Include an approved user in the project members or an '.
        'explicit user policy, and check the view policy, Space and Projects application access.'));
    }

    // Refresh Space state from locked rows, not an earlier request cache.
    $space_rows = queryfx_all(
      $conn, 'SELECT * FROM %R ORDER BY id FOR UPDATE',
      new PhabricatorSpacesNamespace());
    $spaces = array();
    foreach ($space_rows as $row) {
      $space = id(new PhabricatorSpacesNamespace())->loadFromArray($row);
      $spaces[$space->getPHID()] = $space;
    }
    PhabricatorSpacesNamespaceQuery::destroySpacesCache();
    PhabricatorCaches::getRequestCache()->setKey(
      PhabricatorSpacesNamespaceQuery::KEY_ALL, $spaces);

    $actor_row = queryfx_one(
      $conn, 'SELECT * FROM %R WHERE phid = %s FOR UPDATE',
      new PhabricatorUser(), $actor->getPHID());
    $fresh_actor = $actor_row
      ? id(new PhabricatorUser())->loadFromArray($actor_row)
      : null;
    if (!self::isEligible($fresh_actor)) {
      throw self::newError(pht(
        'The acting account is not an active, approved user.'));
    }

    // Inspect dependency *types*, not allow/deny decisions. Decisions below
    // still come exclusively from PhabricatorPolicyFilter. Arbitrary custom
    // rules (including time-dependent rules) can not prove a stable handoff.
    $space = idx($spaces, $candidate->getSpacePHID());
    if (!$space && $candidate->getSpacePHID() === null) {
      foreach ($spaces as $item) {
        if ($item->getIsDefaultNamespace()) {
          $space = $item;
          break;
        }
      }
    }
    if ($space) {
      self::inspectPolicy(
        $conn, $space->getViewPolicy(), $candidates, $dependencies);
    }
    if ($authorization_object) {
      $old_space = idx($spaces, $authorization_object->getSpacePHID());
      if (!$old_space && $authorization_object->getSpacePHID() === null) {
        foreach ($spaces as $item) {
          if ($item->getIsDefaultNamespace()) {
            $old_space = $item;
            break;
          }
        }
      }
      if ($old_space) {
        self::inspectPolicy(
          $conn, $old_space->getViewPolicy(),
          $unused_candidates, $dependencies);
      }
      id(new PhabricatorPolicyFilter())->setViewer($fresh_actor)
        ->requireCapabilities(array(
          PhabricatorPolicyCapability::CAN_VIEW,
          PhabricatorPolicyCapability::CAN_EDIT,
        ))->raisePolicyExceptions(true)->apply(array($authorization_object));
    }

    $keeps_view = PhabricatorPolicyFilter::hasCapability(
      $fresh_actor, $candidate, PhabricatorPolicyCapability::CAN_VIEW);
    $keeps_edit = PhabricatorPolicyFilter::hasCapability(
      $fresh_actor, $candidate, PhabricatorPolicyCapability::CAN_EDIT);
    if ($keeps_view && $keeps_edit) {
      return;
    }

    unset($candidates[$actor->getPHID()]);
    ksort($candidates);
    $recipient = null;
    $recipient_row = null;
    if ($candidates) {
      $rows = queryfx_all(
        $conn, 'SELECT * FROM %R WHERE phid IN (%Ls) ORDER BY id FOR UPDATE',
        new PhabricatorUser(), array_keys($candidates));
      foreach ($rows as $row) {
        $user = id(new PhabricatorUser())->loadFromArray($row);
        if (!self::isEligible($user)) {
          continue;
        }
        if (!id(new PhabricatorProjectQuery())->setViewer($user)
            ->canViewerUseQueryApplication()) {
          continue;
        }
        $allowed = id(new PhabricatorPolicyFilter())
          ->setViewer($user)
          ->requireCapabilities(array(
            PhabricatorPolicyCapability::CAN_VIEW,
            PhabricatorPolicyCapability::CAN_EDIT,
          ))
          ->apply(array($candidate));
        if ($allowed) {
          $recipient = $user;
          $recipient_row = array(
            $user->getPHID(), $user->getIsDisabled(),
            $user->getIsApproved(), $user->getIsAdmin(),
          );
          break;
        }
      }
    }

    if (!$recipient) {
      throw self::newError(pht(
        'No active recipient can both view and edit the project after this '.
        'change. Include an approved user in the project members or an '.
        'explicit user policy, and check the view policy, Space and Projects application access.'));
    }

    if (!$request) {
      // Conduit and internal callers have the same authorization and final
      // state checks. There is no interactive confirmation for these callers.
      return;
    }

    $data = $request->getPassthroughRequestData();
    unset($data[self::TOKEN], $data[self::EXPIRES]);
    ksort($data);
    $old_members = $before->getMemberPHIDs();
    sort($old_members);
    $state = array(
      'actor' => $actor->getPHID(),
      'session' => $actor->hasSession()
        ? $actor->getSession()->getSessionKey() : null,
      'object' => $before->getID(),
      'old' => array(
        $before->getViewPolicy(), $before->getEditPolicy(),
        $before->getJoinPolicy(), $before->getSpacePHID(),
        $old_members, $before->getDateModified(),
      ),
      'new' => array(
        $candidate->getName(), $candidate->getViewPolicy(),
        $candidate->getEditPolicy(), $candidate->getJoinPolicy(),
        $candidate->getSpacePHID(), $candidate->getParentProjectPHID(),
        $members,
      ),
      'recipient' => $recipient_row,
      'spaces' => $space_rows,
      'dependencies' => $dependencies,
      'request' => $data,
    );
    $expires = $request->getInt(self::EXPIRES);
    $token = $request->getStr(self::TOKEN);
    if ($expires >= time() && $expires <= time() + 900 &&
        hash_equals(self::sign($state, $expires), $token)) {
      return;
    }

    $expires = time() + 900;
    throw new PhabricatorProjectHandoffException(
      self::sign($state, $expires), $expires, !$keeps_view);
  }

  private static function sign(array $state, $expires) {
    return PhabricatorHash::digestWithNamedKey(
      phutil_json_encode(array($state, $expires)), 'project-handoff');
  }

  private static function isEligible($user) {
    return $user && !$user->getIsDisabled() && $user->getIsApproved() &&
      !$user->getIsSystemAgent() && !$user->getIsMailingList();
  }

  private static function canManage($user, $project) {
    if (!id(new PhabricatorProjectQuery())->setViewer($user)
        ->canViewerUseQueryApplication()) {
      return false;
    }
    return (bool)id(new PhabricatorPolicyFilter())
      ->setViewer($user)
      ->requireCapabilities(array(
        PhabricatorPolicyCapability::CAN_VIEW,
        PhabricatorPolicyCapability::CAN_EDIT,
      ))
      ->apply(array($project));
  }

  private static function inspectPolicy(
    AphrontDatabaseConnection $conn,
    $value,
    array &$candidates,
    array &$dependencies) {

    if (PhabricatorPolicyQuery::isGlobalPolicy($value) ||
        $value === 'obj.project.members') {
      return;
    }
    if (phid_get_type($value) === PhabricatorPeopleUserPHIDType::TYPECONST) {
      $candidates[$value] = $value;
      return;
    }
    if (phid_get_type($value) !== PhabricatorPolicyPHIDTypePolicy::TYPECONST) {
      throw self::newError(pht(
        'This transfer depends on a policy which can not be verified safely. '.
        'Use project members or an explicit user policy for the transfer.'));
    }
    $row = queryfx_one(
      $conn, 'SELECT * FROM %R WHERE phid = %s FOR UPDATE',
      new PhabricatorPolicy(), $value);
    if (!$row) {
      throw self::newError(pht(
        'The selected project policy does not exist. Choose a valid policy.'));
    }
    $dependencies[$value] = $row;
    $policy = id(new PhabricatorPolicy())->loadFromArray($row);
    $supported = array(
      'PhabricatorUsersPolicyRule',
      'PhabricatorAdministratorsPolicyRule',
      'PhorgeGlobalPolicyRule',
      'PhabricatorProjectMembersPolicyRule',
    );
    foreach ($policy->getRules() as $rule) {
      if (!in_array(idx($rule, 'rule'), $supported, true)) {
        throw self::newError(pht(
          'This transfer depends on a policy which can not be verified safely. '.
          'Use project members or an explicit user policy for the transfer.'));
      }
      if ($rule['rule'] === 'PhabricatorUsersPolicyRule') {
        foreach ((array)idx($rule, 'value') as $phid) {
          $candidates[$phid] = $phid;
        }
      }
    }
  }

}

<?php

/** Fixed transport dispatch; no actor or authority is accepted in payloads. */
final class PhorgeOrganizationEndpoint extends Phobject {
  public static function read(PhorgeGovernanceNativeActorContext $context,
    $mode, $unit, $subject, $limit, $after) {
    if (!is_string($mode)) {
      throw new InvalidArgumentException('organization_query_mode_refused');
    }
    $query = new PhorgeOrganizationQuery($context);
    switch ($mode) {
      case 'list': return $query->listUnits($limit, $after);
      case 'selector': return $query->selectUnits($limit, $after);
      case 'export': return $query->exportUnitsPage($limit, $after);
      case 'count': return $query->countUnits();
      case 'search-count': return $query->countUnits($unit??'');
      case 'member-count': return $query->countMembers($unit);
      case 'members': return $query->listMembers($unit, $limit, $after);
      case 'unassigned': return $query->listUnassignedUsers($limit,$after);
      case 'search': return $query->searchUnits($unit??'', $limit,$after);
      case 'user-teams': return $query->listUserTeams($subject, $limit);
      default: throw new InvalidArgumentException('organization_query_mode_refused');
    }
  }

  public static function write(PhorgeGovernanceNativeActorContext $context,
    $action, array $payload) {
    if (!is_string($action)) {
      throw new InvalidArgumentException('organization_action_refused');
    }
    $service = PhorgeOrganizationService::newForActor($context);
    switch ($action) {
      case 'unit.create': return $service->createUnit($payload);
      case 'unit.preview': return $service->previewUnitChange($payload);
      case 'unit.metadata': return $service->updateUnitMetadata($payload);
      case 'department.clear': return $service->clearPrimaryDepartment($payload);
      case 'unit.update': return $service->updateUnit($payload);
      case 'department.assign': return $service->assignPrimaryDepartment($payload);
      case 'members.edit': return $service->execute($payload);
      case 'approval.propose': return $service->proposeApproval($payload);
      case 'approval.approve':
        if (array_keys($payload) !== array('approval_id')) {
          throw new InvalidArgumentException('organization_approval_request_refused');
        }
        return $service->approveApproval($payload['approval_id']);
      default: throw new InvalidArgumentException('organization_action_refused');
    }
  }
}

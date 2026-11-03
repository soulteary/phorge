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
      case 'transfer': return $query->getTransfer($unit);
      case 'audit': return $query->readAuditPage($limit,$after,false);
      case 'audit-retention': return $query->readAuditPage($limit,$after,true);
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
      case 'transfer.preview':
        $checked=PhorgeOrganizationTransferRequest::envelope($payload,false);
        return $service->previewTransfer($checked['request'],$checked['deadline_offset']);
      case 'transfer.propose':
        $checked=PhorgeOrganizationTransferRequest::envelope($payload,true);
        return $service->proposeTransfer($checked['request'],$checked['ttl_seconds'],$checked['deadline_offset']);
      case 'transfer.approve':
        $keys=array_keys($payload);sort($keys);
        if($keys!==array('request_digest','transfer_id')){throw new InvalidArgumentException('organization_transfer_transport_refused');}
        return $service->approveTransfer($payload['transfer_id'],$payload['request_digest']);
      case 'transfer.execute':
        $keys=array_keys($payload);sort($keys);
        if($keys!==array('acknowledge_no_project_permission_change','transfer_id')){throw new InvalidArgumentException('organization_transfer_transport_refused');}
        return $service->executeTransfer($payload['transfer_id'],$payload['acknowledge_no_project_permission_change']);
      case 'unit.create': return $service->createUnit($payload);
      case 'unit.preview': return $service->previewUnitChange($payload);
      case 'unit.metadata': return $service->updateUnitMetadata($payload);
      case 'department.clear': return $service->clearPrimaryDepartment($payload);
      case 'unit.update': return $service->updateUnit($payload);
      case 'department.assign': return $service->assignPrimaryDepartment($payload);
      case 'members.edit': return $service->execute($payload);
      case 'compound.execute-approved':
        if(array_keys($payload)!==array('approval_id')){throw new InvalidArgumentException('organization_approval_request_refused');}
        return $service->executeApprovedCompound($payload['approval_id']);
      case 'compound.preview': return $service->previewCompound($payload);
      case 'compound.propose': return $service->proposeCompoundApproval($payload);
      case 'compound.execute': return $service->executeCompound($payload);
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

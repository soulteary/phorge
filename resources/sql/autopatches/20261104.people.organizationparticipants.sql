-- Derived discovery metadata only. No authorization binding is rewritten.
-- Legacy rows remain explicitly unindexed until an isolated reviewed backfill.
ALTER TABLE {$NAMESPACE}_user.organization_approval
  ADD sourceParticipantVersion INT UNSIGNED NOT NULL DEFAULT 0,
  ADD sourceSubjectPHID VARBINARY(64) DEFAULT NULL,
  ADD sourceRequesterPHID VARBINARY(64) DEFAULT NULL,
  ADD sourceExecutorPHID VARBINARY(64) DEFAULT NULL,
  ADD KEY key_source_approver (approverPHID),
  ADD KEY key_source_version (sourceParticipantVersion),
  ADD KEY key_source_subject (sourceSubjectPHID),
  ADD KEY key_source_requester (sourceRequesterPHID),
  ADD KEY key_source_executor (sourceExecutorPHID);
ALTER TABLE {$NAMESPACE}_user.organization_transfer
  ADD KEY key_responsible (responsiblePHID),
  ADD KEY key_requester (requesterPHID),
  ADD KEY key_executor (executorPHID),
  ADD KEY key_approver (approverPHID);
ALTER TABLE {$NAMESPACE}_user.organization_handover
  ADD KEY key_responsible (responsiblePHID);
ALTER TABLE {$NAMESPACE}_user.organization_outbox
  ADD KEY key_transfer (transferID),
  ADD KEY key_handover (handoverID);
ALTER TABLE {$NAMESPACE}_user.organization_projectdisposition
  ADD KEY key_manual_actor (manualActorPHID);

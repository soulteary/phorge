-- Existing candidate rows deliberately remain NULL and unusable. The current
-- external incarnation/key is never read from or backfilled into this database.
ALTER TABLE {$NAMESPACE}_user.governance_mediabrowser
  ADD issuerIncarnation VARBINARY(32) NULL;
ALTER TABLE {$NAMESPACE}_user.governance_mediaexchange
  ADD issuerIncarnation VARBINARY(32) NULL;
ALTER TABLE {$NAMESPACE}_user.governance_mediacredential
  ADD issuerIncarnation VARBINARY(32) NULL;
